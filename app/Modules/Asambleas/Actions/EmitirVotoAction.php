<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Permiso;
use App\Core\Realtime\EnVivo;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaAsistencia;
use App\Modules\Asambleas\Models\AsambleaUnidad;
use App\Modules\Asambleas\Models\Candidato;
use App\Modules\Asambleas\Models\Votacion;
use App\Modules\Asambleas\Models\Voto;
use App\Modules\Asambleas\Services\CadenaDeVotos;
use App\Modules\Asambleas\Services\DerechoAVoto;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: emitir el voto de una o más unidades (Sí, No o Abstención). El voto no se edita.
 *
 * - Por la app (sin `unidad_id` o con uno suyo): vota el propietario o el representante con poder validado, por cada unidad suya que
 *   esté presente y sin mora. Las que no pueden votar se devuelven como omitidas, con el motivo.
 * - En mesa (con `unidad_id`, permiso de registrar): el secretario registra el voto de una unidad presente que no usa la app
 *   (mano alzada o papeleta); queda con canal «mesa» y quién lo registró.
 */
final class EmitirVotoAction
{
    public function __construct(
        private readonly DerechoAVoto $derecho,
        private readonly CadenaDeVotos $cadena,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return list<array{unidad_id: int, codigo: string, estado: string, motivo: string|null}>
     */
    public function execute(User $user, int $votacionId, string $opcion, ?int $unidadId, ?int $candidatoId = null): array
    {
        $resultados = DB::transaction(function () use ($user, $votacionId, $opcion, $unidadId, $candidatoId): array {
            $v = Votacion::query()->lockForUpdate()->findOrFail($votacionId);
            if (! $v->estaAbierta()) {
                throw new ApiException('VOTACION_CERRADA', 'La votación ya se cerró.', 409);
            }
            $this->validarOpcion($v, $opcion, $candidatoId);
            $asamblea = Asamblea::query()->findOrFail($v->asamblea_id);

            $enMesa = $unidadId !== null && $user->can(Permiso::AsambleasRegistrar->value);
            if ($enMesa) {
                $unidades = [$unidadId];
            } else {
                $mias = $this->derecho->unidadesDeLaCuenta($user, $asamblea);
                if ($unidadId !== null && ! in_array($unidadId, $mias, true)) {
                    throw new ApiException('NO_VOTA_POR_ESA_UNIDAD', 'Solo el propietario, o su representante con poder validado, vota por la unidad.', 403);
                }
                $unidades = $unidadId !== null ? [$unidadId] : $mias;
                if ($unidades === []) {
                    throw new ApiException('SIN_DERECHO_A_VOTO', 'No tienes unidades con derecho a voto en esta asamblea: solo votan los propietarios o sus representantes con poder.', 403);
                }
            }

            $foto = AsambleaUnidad::query()->where('asamblea_id', $asamblea->id)->whereIn('unidad_id', $unidades)->get()->keyBy('unidad_id');
            $salida = [];
            foreach ($unidades as $id) {
                $codigo = (string) ($foto[$id]->codigo ?? '—');
                $problema = $this->derecho->impedimento($asamblea, $id);
                $previo = Voto::query()->where('votacion_id', $v->id)->where('unidad_id', $id)->first();
                if ($previo !== null) {
                    $problema = ['codigo' => 'YA_VOTO', 'mensaje' => "La unidad {$codigo} ya votó".($v->secreta ? '.' : ' ('.$this->quien($v, $previo).').')];
                }
                if ($problema !== null) {
                    $salida[] = ['unidad_id' => $id, 'codigo' => $codigo, 'estado' => 'omitida', 'motivo' => $problema['mensaje'], '_codigo' => $problema['codigo']];

                    continue;
                }
                $this->guardar($v, $foto[$id], $opcion, $candidatoId, $user, $enMesa ? 'mesa' : 'app');
                $salida[] = ['unidad_id' => $id, 'codigo' => $codigo, 'estado' => 'registrado', 'motivo' => null, '_codigo' => null];
            }

            if (collect($salida)->every(fn (array $s): bool => $s['estado'] === 'omitida')) {
                $primero = $salida[0];
                $estado = $primero['_codigo'] === 'YA_VOTO' ? 409 : 422;
                throw new ApiException($primero['_codigo'], $primero['motivo'] ?? 'No se pudo registrar el voto.', $estado);
            }

            return array_map(fn (array $s): array => array_diff_key($s, ['_codigo' => 1]), $salida);
        });
        EnVivo::aAsambleas($this->tenant->require(), 'votacion', $votacionId);

        return $resultados;
    }

    /** Sí / No / Abstención en una votación común; candidato (con su id) o blanco en una elección. */
    private function validarOpcion(Votacion $v, string $opcion, ?int $candidatoId): void
    {
        if ($v->esEleccion()) {
            $valida = $opcion === 'blanco' ? $candidatoId === null
                : ($opcion === 'candidato' && $candidatoId !== null && Candidato::query()->where('id', $candidatoId)->where('punto_id', $v->punto_id)->where('cargo', $v->cargo)->exists());
            if (! $valida) {
                throw new ApiException('OPCION_NO_VALIDA', 'Elige a uno de los candidatos de este cargo o vota en blanco.', 422);
            }

            return;
        }
        if (! in_array($opcion, Votacion::OPCIONES, true) || $candidatoId !== null) {
            throw new ApiException('OPCION_NO_VALIDA', 'Elige Sí, No o Abstención.', 422);
        }
    }

    private function guardar(Votacion $v, AsambleaUnidad $u, string $opcion, ?int $candidatoId, User $user, string $canal): void
    {
        $anterior = Voto::query()->where('votacion_id', $v->id)->orderByDesc('id')->value('hash') ?? $this->cadena->semilla($v->id);
        $momento = now()->startOfSecond();
        $datos = [
            'unidad_id' => $u->unidad_id, 'opcion' => $opcion, 'alicuota' => (string) $u->alicuota, 'canal' => $canal,
            'emitido_por' => $user->id, 'emitido_en' => $this->cadena->instante($momento), 'candidato_id' => $candidatoId,
        ];
        Voto::create([
            'votacion_id' => $v->id, 'unidad_id' => $u->unidad_id, 'opcion' => $opcion, 'candidato_id' => $candidatoId, 'alicuota' => $u->alicuota, 'emitido_por' => $user->id,
            'canal' => $canal, 'emitido_en' => $momento, 'hash' => $this->cadena->hash($anterior, $datos),
        ]);
    }

    /** Quién votó por la unidad: quien está presente por ella (el propietario o su representante). */
    private function quien(Votacion $v, Voto $voto): string
    {
        $nombre = AsambleaAsistencia::query()->where('asamblea_id', $v->asamblea_id)->where('unidad_id', $voto->unidad_id)->latest('id')->value('asistente_nombre');

        return is_string($nombre) ? $nombre : 'sin dato';
    }
}
