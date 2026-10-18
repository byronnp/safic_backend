<?php

namespace App\Modules\Garita\Services;

use App\Core\Realtime\EnVivo;
use App\Modules\Unidades\Actions\OcupantesVigentesAction;

/**
 * Avisos en vivo de la garita: a quienes viven en la unidad (con cuenta) y al equipo de garita. Solo dicen qué recurso cambió;
 * cada pantalla vuelve a consultarlo por la API.
 */
final class AvisosEnVivoDeGarita
{
    public const SOLICITUDES = 'solicitudes-ingreso';

    public const PAQUETES = 'paquetes';

    public const ACCESOS = 'accesos';

    public function __construct(private readonly OcupantesVigentesAction $ocupantes) {}

    public function aLaUnidad(int $condominioId, ?int $unidadId, string $recurso, ?int $id = null): void
    {
        if ($unidadId === null) {
            return;
        }

        EnVivo::aUsuarios($condominioId, $this->ocupantes->usuarios($unidadId), $recurso, $id);
    }

    public function aLaGarita(int $condominioId, string $recurso, ?int $id = null): void
    {
        EnVivo::aGarita($condominioId, $recurso, $id);
    }
}
