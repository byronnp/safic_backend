<?php

namespace App\Modules\Garita\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Storage\ArchivosCondominio;
use App\Models\User;
use App\Modules\Garita\Models\Paquete;
use App\Modules\Unidades\Actions\OcupantesVigentesAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del guardia: entregar un paquete a quien lo retira, con su firma. Retira un ocupante vigente de la unidad (se
 * elige de la lista) o una persona que el residente autorizó (se escribe su nombre).
 */
final class EntregarPaqueteAction
{
    public function __construct(private readonly ArchivosCondominio $archivos, private readonly OcupantesVigentesAction $ocupantes) {}

    public function execute(User $guardia, int $paqueteId, ?int $ocupanteId, ?string $autorizado, UploadedFile $firma): Paquete
    {
        if (($ocupanteId === null) === ($autorizado === null || trim($autorizado) === '')) {
            throw new ApiException('RETIRA_NO_INDICADO', 'Indica quién retira: un ocupante de la unidad o una persona autorizada.', 422);
        }

        $paquete = Paquete::query()->findOrFail($paqueteId);
        if ($paquete->estado !== Paquete::EN_GARITA) {
            throw new ApiException('PAQUETE_YA_ENTREGADO', 'Este paquete ya fue entregado.', 409);
        }

        [$tipo, $nombre] = ['autorizado', trim((string) $autorizado)];
        if ($ocupanteId !== null) {
            $ocupante = collect($this->ocupantes->execute($paquete->unidad_id))->firstWhere('id', $ocupanteId)
                ?? throw new ApiException('OCUPANTE_NO_VIGENTE', 'Esa persona ya no vive en la unidad.', 422);
            [$tipo, $nombre] = ['ocupante', $ocupante['nombre']];
        }

        $ruta = $this->archivos->guardar($firma, 'paquetes-firmas');

        try {
            return DB::transaction(function () use ($guardia, $paqueteId, $tipo, $nombre, $ruta): Paquete {
                $p = Paquete::query()->lockForUpdate()->findOrFail($paqueteId);
                if ($p->estado !== Paquete::EN_GARITA) {
                    throw new ApiException('PAQUETE_YA_ENTREGADO', 'Este paquete ya fue entregado.', 409);
                }
                $p->forceFill([
                    'estado' => Paquete::ENTREGADO, 'entregado_en' => now(), 'entregado_por' => $guardia->id,
                    'retira_tipo' => $tipo, 'retira_nombre' => $nombre, 'firma_ruta' => $ruta,
                ])->save();

                return $p;
            });
        } catch (\Throwable $e) {
            $this->archivos->eliminar($ruta);

            throw $e;
        }
    }
}
