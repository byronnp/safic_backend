<?php

namespace App\Modules\Garita\Actions;

use App\Core\Storage\ArchivosCondominio;
use App\Modules\Garita\Models\AccesoGarita;
use App\Modules\Garita\Models\AutorizacionVisita;
use App\Modules\Garita\Models\Paquete;
use Illuminate\Support\Facades\DB;

/**
 * Retención de datos (LOPDP): a los 12 meses se borran el nombre, la cédula y la placa de los visitantes, tanto de la bitácora
 * como de las autorizaciones ya vencidas. Queda el hecho (que hubo un ingreso, cuándo y a qué unidad) para las estadísticas.
 */
final class AnonimizarGaritaAction
{
    public const MESES = 12;

    public const NOMBRE = 'Visitante anonimizado';

    public function __construct(private readonly ArchivosCondominio $archivos) {}

    /** @return array{accesos: int, autorizaciones: int, paquetes: int} */
    public function execute(): array
    {
        $limite = now()->subMonths(self::MESES);

        return DB::transaction(function () use ($limite): array {
            $accesos = AccesoGarita::query()->whereNull('anonimizado_en')->where('entrada_en', '<', $limite)
                ->update(['nombre' => self::NOMBRE, 'documento' => null, 'placa' => null, 'anonimizado_en' => now()]);
            $autorizaciones = AutorizacionVisita::query()->where('fecha_hasta', '<', $limite->toDateString())->where('visitante_nombre', '!=', self::NOMBRE)
                ->update(['visitante_nombre' => self::NOMBRE, 'visitante_documento' => null, 'placa' => null]);

            // De los paquetes ya entregados se borra quién retiró y su firma (el archivo también)
            $viejos = Paquete::query()->where('estado', Paquete::ENTREGADO)->whereNull('anonimizado_en')->where('entregado_en', '<', $limite)->get();
            foreach ($viejos as $p) {
                if ($p->firma_ruta !== null) {
                    $this->archivos->eliminar($p->firma_ruta);
                }
                $p->forceFill(['retira_nombre' => self::NOMBRE, 'firma_ruta' => null, 'anonimizado_en' => now()])->save();
            }

            return ['accesos' => $accesos, 'autorizaciones' => $autorizaciones, 'paquetes' => $viejos->count()];
        });
    }
}
