<?php

namespace App\Modules\Comunicacion\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Comunicacion\Models\Anuncio;
use App\Modules\Comunicacion\Models\AnuncioDestinatario;
use App\Modules\Unidades\Actions\OcupantesVigentesAction;

/**
 * Caso de uso de la administración: reenviar el anuncio por correo a las unidades que aún no lo leyeron (o no confirmaron, si pedía
 * confirmación). Devuelve a cuántas unidades se les avisó.
 */
final class ReenviarAnuncioAction
{
    public function __construct(private readonly OcupantesVigentesAction $ocupantes) {}

    public function execute(int $anuncioId): int
    {
        $anuncio = Anuncio::query()->findOrFail($anuncioId);
        if ($anuncio->expira_en !== null && $anuncio->expira_en->isPast()) {
            throw new ApiException('ANUNCIO_EXPIRADO', 'Este anuncio ya expiró.', 409);
        }

        $columna = $anuncio->requiere_confirmacion ? 'confirmado_en' : 'leido_en';
        $pendientes = AnuncioDestinatario::query()->where('anuncio_id', $anuncio->id)->whereNull($columna)->pluck('unidad_id');

        foreach ($pendientes as $unidadId) {
            $personas = array_map(fn (array $o): array => ['unidad_id' => (int) $unidadId, 'nombre' => $o['nombre'], 'email' => $o['email'], 'tiene_cuenta' => true], $this->ocupantes->execute((int) $unidadId));
            PublicarAnuncioAction::notificar($anuncio, $personas, true);
        }

        return $pendientes->count();
    }
}
