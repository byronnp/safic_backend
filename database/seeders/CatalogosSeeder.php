<?php

namespace Database\Seeders;

use App\Modules\Plataforma\Models\AmenidadCatalogo;
use App\Modules\Plataforma\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Catálogos de plataforma: planes, amenidades y división territorial del Ecuador.
 * Idempotente: se puede correr en cada despliegue. No pisa lo que el super admin
 * ajustó en planes o amenidades (solo crea los que faltan).
 */
class CatalogosSeeder extends Seeder
{
    public function run(): void
    {
        $this->planes();
        $this->amenidades();
        $this->ubicaciones();
    }

    private function planes(): void
    {
        $planes = [
            ['codigo' => 'basico', 'nombre' => 'Básico', 'limite_administrativos' => 2, 'valor_unidad_sugerido' => '1.50', 'orden' => 1],
            ['codigo' => 'profesional', 'nombre' => 'Profesional', 'limite_administrativos' => 3, 'valor_unidad_sugerido' => '2.00', 'orden' => 2],
            ['codigo' => 'completo', 'nombre' => 'Completo', 'limite_administrativos' => 4, 'valor_unidad_sugerido' => '2.50', 'orden' => 3],
        ];

        foreach ($planes as $plan) {
            Plan::firstOrCreate(['codigo' => $plan['codigo']], $plan);
        }
    }

    private function amenidades(): void
    {
        // nombre, categoría, descripción, reservable, esencial, requiere aprobación, capacidad, duración (min)
        $amenidades = [
            ['Piscina', 'recreacion', 'Piscina de uso común con horario', true, false, false, 30, 180],
            ['Salón comunal', 'social', 'Eventos sociales y reuniones', true, false, true, 80, 360],
            ['Área BBQ', 'social', 'Parrilla con mesas', true, false, false, 20, 240],
            ['Cancha múltiple', 'deporte', 'Fútbol, básquet o vóley', true, false, false, 12, 120],
            ['Gimnasio', 'deporte', 'Máquinas y pesas, uso libre', false, false, false, 15, null],
            ['Parque infantil', 'recreacion', 'Juegos para niños', false, false, false, null, null],
            ['Ascensor', 'servicios', 'Transporte vertical', false, true, false, null, null],
            ['Generador eléctrico', 'servicios', 'Respaldo de energía', false, true, false, null, null],
            ['Guardianía 24 h', 'seguridad', 'Control de acceso permanente', false, true, false, null, null],
            ['Parqueadero de visitas', 'servicios', 'Estacionamiento para visitantes', false, false, false, null, null],
        ];

        foreach ($amenidades as $orden => [$nombre, $categoria, $descripcion, $reservable, $esencial, $aprobacion, $capacidad, $duracion]) {
            AmenidadCatalogo::firstOrCreate(['nombre' => $nombre], [
                'categoria' => $categoria,
                'descripcion' => $descripcion,
                'reservable' => $reservable,
                'esencial' => $esencial,
                'requiere_aprobacion' => $aprobacion,
                'capacidad' => $capacidad,
                'duracion_maxima_min' => $duracion,
                'orden' => $orden + 1,
                'activa' => true,
            ]);
        }
    }

    /**
     * Provincias, cantones y parroquias con sus códigos INEC (database/data).
     * Upsert por código: corregir un nombre en el JSON lo actualiza al desplegar.
     */
    private function ubicaciones(): void
    {
        $ruta = database_path('data/division_territorial_ec.json');
        $datos = json_decode((string) file_get_contents($ruta), true);

        if (! is_array($datos) || ! isset($datos['provincias'])) {
            throw new RuntimeException("No se pudo leer {$ruta}.");
        }

        $provincias = $cantones = $parroquias = [];

        foreach ($datos['provincias'] as $p) {
            $provincias[] = ['codigo' => $p['codigo'], 'nombre' => $p['nombre'], 'latitud' => $p['lat'] ?? null, 'longitud' => $p['lng'] ?? null];

            foreach ($p['cantones'] as $c) {
                $cantones[] = ['codigo' => $c['codigo'], 'provincia_codigo' => $p['codigo'], 'nombre' => $c['nombre'], 'latitud' => $c['lat'] ?? null, 'longitud' => $c['lng'] ?? null];

                foreach ($c['parroquias'] as $q) {
                    $parroquias[] = ['codigo' => $q['codigo'], 'canton_codigo' => $c['codigo'], 'nombre' => $q['nombre']];
                }
            }
        }

        DB::transaction(function () use ($provincias, $cantones, $parroquias): void {
            DB::table('ubicacion_provincias')->upsert($provincias, ['codigo'], ['nombre', 'latitud', 'longitud']);
            DB::table('ubicacion_cantones')->upsert($cantones, ['codigo'], ['provincia_codigo', 'nombre', 'latitud', 'longitud']);

            foreach (array_chunk($parroquias, 500) as $lote) {
                DB::table('ubicacion_parroquias')->upsert($lote, ['codigo'], ['canton_codigo', 'nombre']);
            }
        });
    }
}
