<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Permiso;
use App\Core\Realtime\EnVivo;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaAsistencia;
use App\Modules\Asambleas\Models\AsambleaPoder;
use App\Modules\Unidades\Actions\PadronDeAsambleaAction;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use App\Modules\Unidades\Models\Persona;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: registrar quién está en la asamblea (el quórum sale de aquí).
 *
 * - En mesa (con `unidad_id`): el secretario o la directiva marca a una unidad. Si la unidad dio un poder validado, entra su
 *   representante; si no, un propietario (el principal o el que se indique).
 * - Por la app (sin `unidad_id`, «Estoy presente»): solo en asambleas virtuales o mixtas. Marca todas las unidades del propietario y las
 *   que representa por poder; repetirlo mantiene la presencia viva (un asistente virtual sin actividad por 10 minutos deja de contar).
 */
final class RegistrarAsistenciaAction
{
    public function __construct(
        private readonly PadronDeAsambleaAction $padron,
        private readonly UnidadesDeCuentaAction $unidadesDeCuenta,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array{unidad_id?: int|null, asistente_persona_id?: int|null}  $d
     * @return list<int> unidades con presencia vigente tras la operación
     */
    public function execute(User $user, int $asambleaId, array $d): array
    {
        $unidades = isset($d['unidad_id'])
            ? $this->enMesa($user, $asambleaId, (int) $d['unidad_id'], $d['asistente_persona_id'] ?? null)
            : $this->porLaApp($user, $asambleaId);

        EnVivo::aAsambleas($this->tenant->require(), 'asistencia', $asambleaId);

        return $unidades;
    }

    /** @return list<int> */
    private function enMesa(User $user, int $asambleaId, int $unidadId, ?int $personaId): array
    {
        if (! $user->can(Permiso::AsambleasRegistrar->value)) {
            throw new ApiException('SIN_PERMISO', 'Solo quien registra la asistencia puede marcar a una unidad.', 403);
        }
        $unidad = $this->enPadron($unidadId);

        DB::transaction(function () use ($user, $asambleaId, $unidad, $personaId): void {
            $this->registroAbierto($asambleaId);
            $poder = AsambleaPoder::query()->where('asamblea_id', $asambleaId)->where('unidad_id', $unidad['unidad_id'])->where('estado', AsambleaPoder::VALIDADO)->first();
            if ($poder !== null) {
                $this->marcar($user, $asambleaId, $unidad['unidad_id'], AsambleaAsistencia::PODER, $poder->representante_persona_id, $poder->representante_nombre, $poder->id, 'mesa');

                return;
            }

            $propietarios = collect($unidad['propietarios']);
            $quien = $personaId === null ? $propietarios->first() : $propietarios->firstWhere('persona_id', $personaId);
            if ($quien === null) {
                throw new ApiException('NO_ES_PROPIETARIO', 'Esa persona no es propietaria de la unidad.', 422);
            }
            $this->marcar($user, $asambleaId, $unidad['unidad_id'], AsambleaAsistencia::PRESENCIAL, $quien['persona_id'], $quien['nombre'], null, 'mesa');
        });

        return [$unidadId];
    }

    /** @return list<int> */
    private function porLaApp(User $user, int $asambleaId): array
    {
        $asamblea = Asamblea::query()->findOrFail($asambleaId);
        if (! in_array($asamblea->modalidad, ['virtual', 'mixta'], true)) {
            throw new ApiException('SOLO_EN_MESA', 'Esta asamblea es presencial: tu asistencia la registra el secretario en la entrada.', 409);
        }
        $persona = Persona::query()->where('user_id', $user->id)->first();
        $enPadron = collect($this->padron->execute())->keyBy('unidad_id');
        $propias = collect($this->unidadesDeCuenta->execute($user->id))->where('relacion', 'propietario')->pluck('unidad_id')->all();
        // Una unidad con poder validado la representa su representante, no su propietario
        $conPoder = AsambleaPoder::query()->where('asamblea_id', $asambleaId)->where('estado', AsambleaPoder::VALIDADO)->get()->keyBy('unidad_id');

        $mias = [];
        foreach ($propias as $unidadId) {
            if ($enPadron->has($unidadId) && ! $conPoder->has($unidadId)) {
                $mias[$unidadId] = [AsambleaAsistencia::VIRTUAL, null];
            }
        }
        foreach ($conPoder as $unidadId => $poder) {
            if ($persona !== null && $poder->representante_persona_id === $persona->id && $enPadron->has($unidadId)) {
                $mias[$unidadId] = [AsambleaAsistencia::PODER, $poder];
            }
        }
        if ($mias === []) {
            throw new ApiException('SIN_UNIDADES', 'No tienes unidades con derecho a asistir a esta asamblea.', 403);
        }

        DB::transaction(function () use ($user, $asambleaId, $mias, $persona): void {
            $this->registroAbierto($asambleaId);
            foreach ($mias as $unidadId => [$forma, $poder]) {
                $actual = AsambleaAsistencia::query()->whereNull('salida_en')->where('asamblea_id', $asambleaId)->where('unidad_id', $unidadId)->first();
                if ($actual !== null) {
                    // «Estoy presente» otra vez: mantiene viva la presencia
                    $actual->forceFill(['ultima_actividad_en' => now()])->save();

                    continue;
                }
                $nombre = $forma === AsambleaAsistencia::PODER ? $poder->representante_nombre : ($persona?->nombreCompleto() ?? $user->name);
                $this->marcar($user, $asambleaId, $unidadId, $forma, $persona?->id, $nombre, $poder?->id, 'app');
            }
        });

        return array_keys($mias);
    }

    private function registroAbierto(int $asambleaId): void
    {
        $a = Asamblea::query()->lockForUpdate()->findOrFail($asambleaId);
        if (! $a->registroAbierto()) {
            throw new ApiException('REGISTRO_CERRADO', 'El registro de asistencia no está abierto.', 409);
        }
    }

    private function marcar(User $user, int $asambleaId, int $unidadId, string $forma, ?int $personaId, string $nombre, ?int $poderId, string $canal): void
    {
        $actual = AsambleaAsistencia::query()->whereNull('salida_en')->where('asamblea_id', $asambleaId)->where('unidad_id', $unidadId)->first();
        if ($actual !== null) {
            throw new ApiException('YA_REGISTRADA', "Esa unidad ya está presente ({$actual->asistente_nombre}).", 409);
        }
        AsambleaAsistencia::create([
            'asamblea_id' => $asambleaId, 'unidad_id' => $unidadId, 'asistente_persona_id' => $personaId, 'asistente_nombre' => mb_substr($nombre, 0, 120),
            'forma' => $forma, 'poder_id' => $poderId, 'canal' => $canal, 'registrada_por' => $user->id,
            'ingreso_en' => now(), 'ultima_actividad_en' => now(),
        ]);
    }

    /** @return array{unidad_id: int, codigo: string, alicuota: string, propietarios: list<array{persona_id: int, nombre: string}>} */
    private function enPadron(int $unidadId): array
    {
        $u = collect($this->padron->execute())->firstWhere('unidad_id', $unidadId);
        if ($u === null) {
            throw new ApiException('UNIDAD_SIN_DERECHO', 'Esa unidad no tiene propietario con alícuota: no entra al padrón de la asamblea.', 422);
        }

        return $u;
    }
}
