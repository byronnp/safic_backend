<?php

namespace App\Modules\Garita\Actions;

use App\Modules\Garita\Models\AccesoGarita;
use App\Modules\Garita\Models\AutorizacionVisita;
use Illuminate\Support\Facades\DB;

/**
 * Retención de datos (LOPDP): a los 12 meses se borran el nombre, la cédula y la placa de los visitantes, tanto de la bitácora
 * como de las autorizaciones ya vencidas. Queda el hecho (que hubo un ingreso, cuándo y a qué unidad) para las estadísticas.
 */
final class AnonimizarGaritaAction
{
    public const MESES = 12;

    public const NOMBRE = 'Visitante anonimizado';

    /** @return array{accesos: int, autorizaciones: int} */
    public function execute(): array
    {
        $limite = now()->subMonths(self::MESES);

        return DB::transaction(function () use ($limite): array {
            $accesos = AccesoGarita::query()->whereNull('anonimizado_en')->where('entrada_en', '<', $limite)
                ->update(['nombre' => self::NOMBRE, 'documento' => null, 'placa' => null, 'anonimizado_en' => now()]);
            $autorizaciones = AutorizacionVisita::query()->where('fecha_hasta', '<', $limite->toDateString())->where('visitante_nombre', '!=', self::NOMBRE)
                ->update(['visitante_nombre' => self::NOMBRE, 'visitante_documento' => null, 'placa' => null]);

            return ['accesos' => $accesos, 'autorizaciones' => $autorizaciones];
        });
    }
}
