<?php

namespace Database\Seeders;

use App\Modules\Plataforma\Models\AmenidadCatalogo;
use App\Modules\Plataforma\Models\Plan;
use App\Modules\Plataforma\Models\Provincia;
use Illuminate\Database\Seeder;

/**
 * Catálogos de plataforma. Idempotente: crea lo que falta y no pisa lo que el
 * super admin haya cambiado después (planes y amenidades se editan desde el panel).
 *
 * Cantones y parroquias se cargan con el archivo oficial del INEC:
 *     php artisan safic:importar-dpa storage/app/dpa.csv
 */
class CatalogosSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->planes() as $orden => $plan) {
            Plan::query()->firstOrCreate(['clave' => $plan['clave']], $plan + ['orden' => $orden + 1]);
        }

        foreach ($this->provincias() as $codigo => $nombre) {
            Provincia::query()->firstOrCreate(['codigo' => $codigo], ['nombre' => $nombre]);
        }

        foreach ($this->amenidades() as $orden => $amenidad) {
            AmenidadCatalogo::query()->firstOrCreate(['clave' => $amenidad['clave']], $amenidad + ['orden' => $orden + 1]);
        }
    }

    /**
     * Límite de usuarios administrativos por plan: Básico 2, Profesional 3, Completo 4.
     *
     * @return list<array{clave: string, nombre: string, max_administrativos: int, valor_unidad_sugerido: string}>
     */
    private function planes(): array
    {
        return [
            ['clave' => Plan::BASICO, 'nombre' => 'Básico', 'max_administrativos' => 2, 'valor_unidad_sugerido' => '2.00'],
            ['clave' => Plan::PROFESIONAL, 'nombre' => 'Profesional', 'max_administrativos' => 3, 'valor_unidad_sugerido' => '2.00'],
            ['clave' => Plan::COMPLETO, 'nombre' => 'Completo', 'max_administrativos' => 4, 'valor_unidad_sugerido' => '2.00'],
        ];
    }

    /**
     * Las 24 provincias con su código de la DPA del INEC.
     *
     * @return array<string, string>
     */
    private function provincias(): array
    {
        return [
            '01' => 'Azuay', '02' => 'Bolívar', '03' => 'Cañar', '04' => 'Carchi',
            '05' => 'Cotopaxi', '06' => 'Chimborazo', '07' => 'El Oro', '08' => 'Esmeraldas',
            '09' => 'Guayas', '10' => 'Imbabura', '11' => 'Loja', '12' => 'Los Ríos',
            '13' => 'Manabí', '14' => 'Morona Santiago', '15' => 'Napo', '16' => 'Pastaza',
            '17' => 'Pichincha', '18' => 'Tungurahua', '19' => 'Zamora Chinchipe', '20' => 'Galápagos',
            '21' => 'Sucumbíos', '22' => 'Orellana', '23' => 'Santo Domingo de los Tsáchilas', '24' => 'Santa Elena',
        ];
    }

    /**
     * Catálogo inicial. Reservable: pasa a la agenda de áreas comunes.
     * Esencial: nunca se restringe por mora.
     *
     * @return list<array{clave: string, nombre: string, icono: string, reservable: bool, esencial: bool}>
     */
    private function amenidades(): array
    {
        return [
            ['clave' => 'piscina', 'nombre' => 'Piscina', 'icono' => 'sym_r_pool', 'reservable' => true, 'esencial' => false],
            ['clave' => 'gimnasio', 'nombre' => 'Gimnasio', 'icono' => 'sym_r_fitness_center', 'reservable' => false, 'esencial' => false],
            ['clave' => 'salon_comunal', 'nombre' => 'Salón comunal', 'icono' => 'sym_r_meeting_room', 'reservable' => true, 'esencial' => false],
            ['clave' => 'area_bbq', 'nombre' => 'Área BBQ', 'icono' => 'sym_r_outdoor_grill', 'reservable' => true, 'esencial' => false],
            ['clave' => 'canchas', 'nombre' => 'Canchas', 'icono' => 'sym_r_sports_soccer', 'reservable' => true, 'esencial' => false],
            ['clave' => 'parque_infantil', 'nombre' => 'Parque infantil', 'icono' => 'sym_r_park', 'reservable' => false, 'esencial' => false],
            ['clave' => 'guardiania', 'nombre' => 'Guardianía 24 h', 'icono' => 'sym_r_local_police', 'reservable' => false, 'esencial' => true],
            ['clave' => 'generador', 'nombre' => 'Generador', 'icono' => 'sym_r_bolt', 'reservable' => false, 'esencial' => true],
            ['clave' => 'parqueadero_visitas', 'nombre' => 'Parqueadero de visitas', 'icono' => 'sym_r_local_parking', 'reservable' => false, 'esencial' => false],
        ];
    }
}
