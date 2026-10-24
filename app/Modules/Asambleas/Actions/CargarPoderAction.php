<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Permiso;
use App\Core\Privacy\DatosPersonales;
use App\Core\Storage\ArchivosCondominio;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPoder;
use App\Modules\Unidades\Actions\PadronDeAsambleaAction;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use App\Modules\Unidades\Models\Persona;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Caso de uso: subir el poder (notarial) o la carta con que un propietario designa a un representante (Art. 40). Lo sube el propio
 * propietario de la unidad o la administración en su nombre, hasta que se instala la asamblea. Queda pendiente: lo valida la administración.
 * Una unidad tiene un solo poder vigente por asamblea; para cambiarlo se revoca el anterior.
 */
final class CargarPoderAction
{
    public function __construct(
        private readonly PadronDeAsambleaAction $padron,
        private readonly UnidadesDeCuentaAction $unidadesDeCuenta,
        private readonly ArchivosCondominio $archivos,
    ) {}

    /** @param array{unidad_id: int, tipo: string, representante_nombre: string, representante_tipo_documento: string, representante_documento: string} $d */
    public function execute(User $user, int $asambleaId, array $d, UploadedFile $archivo): AsambleaPoder
    {
        $asamblea = Asamblea::query()->findOrFail($asambleaId);
        if (! $asamblea->estaConvocada()) {
            throw new ApiException('PODERES_CERRADOS', 'Los poderes se reciben desde que se convoca la asamblea hasta que se instala.', 409);
        }

        $unidad = collect($this->padron->execute())->firstWhere('unidad_id', $d['unidad_id']);
        if ($unidad === null) {
            throw new ApiException('UNIDAD_SIN_DERECHO', 'Esa unidad no tiene propietario con alícuota: no entra al padrón de la asamblea.', 422);
        }

        $esAdmin = $user->can(Permiso::AsambleasPreparar->value);
        $propietariaDe = collect($this->unidadesDeCuenta->execute($user->id))->where('relacion', 'propietario')->pluck('unidad_id')->all();
        if (! $esAdmin && ! in_array($d['unidad_id'], $propietariaDe, true)) {
            throw new ApiException('NO_ES_PROPIETARIO', 'Solo el propietario de la unidad puede dar poder.', 403);
        }

        $documento = DatosPersonales::normalizarDocumento($d['representante_documento']);
        $representante = Persona::query()->where('documento_hash', DatosPersonales::hashDocumento($d['representante_tipo_documento'], $documento))->first();
        $propietarios = array_column($unidad['propietarios'], 'persona_id');
        if ($representante !== null && in_array($representante->id, $propietarios, true)) {
            throw new ApiException('REPRESENTANTE_ES_PROPIETARIO', 'El representante ya es propietario de esa unidad: no necesita poder.', 422);
        }

        // Quien otorga es el propietario que sube el poder; si lo sube la administración, el propietario principal
        $otorgante = Persona::query()->where('user_id', $user->id)->whereIn('id', $propietarios)->value('id') ?? $propietarios[0];

        $ruta = $this->archivos->guardar($archivo, 'asambleas');
        try {
            return DB::transaction(function () use ($asamblea, $d, $archivo, $ruta, $user, $documento, $representante, $otorgante): AsambleaPoder {
                // Dentro de la transacción y con la asamblea bloqueada: el estado no cambia mientras se guarda
                $vigente = Asamblea::query()->lockForUpdate()->findOrFail($asamblea->id);
                if (! $vigente->estaConvocada()) {
                    throw new ApiException('PODERES_CERRADOS', 'Los poderes se reciben desde que se convoca la asamblea hasta que se instala.', 409);
                }
                if (AsambleaPoder::query()->where('asamblea_id', $asamblea->id)->where('unidad_id', $d['unidad_id'])->whereIn('estado', [AsambleaPoder::PENDIENTE, AsambleaPoder::VALIDADO])->exists()) {
                    throw new ApiException('PODER_YA_EXISTE', 'Esa unidad ya tiene un poder para esta asamblea. Revócalo si quieres cambiar de representante.', 409);
                }

                return AsambleaPoder::create([
                    'asamblea_id' => $asamblea->id, 'unidad_id' => $d['unidad_id'], 'otorgante_persona_id' => $otorgante, 'tipo' => $d['tipo'],
                    'representante_nombre' => $d['representante_nombre'], 'representante_tipo_documento' => $d['representante_tipo_documento'],
                    'representante_documento' => $documento, 'representante_persona_id' => $representante?->id,
                    'documento_ruta' => $ruta, 'documento_nombre' => Str::limit($archivo->getClientOriginalName(), 120, ''),
                    'estado' => AsambleaPoder::PENDIENTE, 'cargado_por' => $user->id,
                ]);
            });
        } catch (\Throwable $e) {
            $this->archivos->eliminar($ruta);

            throw $e;
        }
    }
}
