<?php

namespace Database\Seeders;

use App\Core\Permissions\Rol;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Finanzas\Actions\GuardarConfiguracionCobroAction;
use App\Modules\Finanzas\Models\ConfiguracionCobro;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Plataforma\Models\Plan;
use App\Modules\Unidades\Models\Bloque;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Database\Seeder;

/**
 * Datos de demostración para desarrollo y staging. Nunca en producción.
 * Contraseña de todos los usuarios: Safic2026!
 */
class DemoSeeder extends Seeder
{
    public function run(TenantContext $tenant): void
    {
        $password = 'Safic2026!';

        $profesional = Plan::query()->where('codigo', 'profesional')->value('id');

        $jardines = Condominio::updateOrCreate(['codigo' => 'SF-0001'], [
            'nombre' => 'Conjunto Jardines del Valle', 'tipo' => 'conjunto',
            'total_unidades' => 148, 'estado' => Condominio::ESTADO_ACTIVO,
            'plan_id' => $profesional, 'valor_unidad' => '2.00',
            'provincia_codigo' => '17', 'canton_codigo' => '1701', 'parroquia_codigo' => '170157',
            'direccion' => 'Vía Interoceánica km 12', 'latitud' => '-0.201500', 'longitud' => '-78.433900',
        ]);
        $arupos = Condominio::updateOrCreate(['codigo' => 'SF-0012'], [
            'nombre' => 'Conjunto Los Arupos', 'tipo' => 'conjunto',
            'total_unidades' => 130, 'estado' => Condominio::ESTADO_ACTIVO,
            'plan_id' => $profesional, 'valor_unidad' => '2.00',
            'provincia_codigo' => '17', 'canton_codigo' => '1701', 'parroquia_codigo' => '170156',
            'direccion' => 'Av. Ilaló y calle Los Arupos', 'latitud' => '-0.285412', 'longitud' => '-78.471236',
        ]);

        $superAdmin = User::updateOrCreate(['email' => 'admin@safic.ec'], ['name' => 'Administrador SAFIC', 'password' => $password, 'activo' => true]);
        $this->asignar($superAdmin, Rol::EQUIPO_PLATAFORMA, Rol::SuperAdmin);

        $maria = User::updateOrCreate(['email' => 'maria@jardinesdelvalle.ec'], ['name' => 'María Rodríguez', 'password' => $password, 'activo' => true]);
        $this->membresia($maria, $jardines, principal: true);
        $this->membresia($maria, $arupos, principal: false);
        $this->asignar($maria, $jardines->id, Rol::Administrador);
        $this->asignar($maria, $arupos->id, Rol::Administrador);

        $diego = User::updateOrCreate(['email' => 'diego@correo.ec'], ['name' => 'Diego Mora', 'password' => $password, 'activo' => true]);
        $this->membresia($diego, $jardines, principal: true);
        $this->asignar($diego, $jardines->id, Rol::Residente);

        // Ambos cobran un valor general (como el condominio piloto)
        foreach ([$jardines, $arupos] as $condominio) {
            $tenant->run($condominio->id, fn () => app(GuardarConfiguracionCobroAction::class)->execute([
                'metodo' => ConfiguracionCobro::METODO_GENERAL, 'cuota_general' => '80.00',
                'dia_vencimiento' => 10, 'aplica_desde' => now()->startOfMonth()->toDateString(),
            ]));
        }

        $tenant->run($jardines->id, function () {
            foreach (['Torre A', 'Torre B', 'Torre C'] as $i => $nombre) {
                $bloque = Bloque::firstOrCreate(['nombre' => $nombre], ['orden' => $i + 1]);
                $letra = substr($nombre, -1);

                // Dos pisos de cuatro departamentos por torre, como en los mockups
                foreach ([1, 2] as $piso) {
                    foreach ([1, 2, 3, 4] as $n) {
                        Unidad::firstOrCreate(
                            ['codigo' => sprintf('%s-%d%02d', $letra, $piso, $n)],
                            ['bloque_id' => $bloque->id, 'tipo' => 'departamento', 'piso' => $piso, 'area_m2' => $n <= 2 ? '84.00' : '96.00', 'alicuota' => $n <= 2 ? '0.6200' : '0.7100'],
                        );
                    }
                }
            }

            foreach ([1, 2, 3, 4] as $n) {
                Unidad::firstOrCreate(['codigo' => sprintf('CS-%02d', $n)], ['tipo' => 'casa', 'area_m2' => '140.00', 'alicuota' => '1.0400']);
                Unidad::firstOrCreate(['codigo' => sprintf('P-%02d', $n)], ['tipo' => 'parqueadero', 'area_m2' => '12.50']);
            }
        });

        $tenant->run($arupos->id, function () {
            foreach (['Etapa 1', 'Etapa 2'] as $i => $nombre) {
                Bloque::firstOrCreate(['nombre' => $nombre], ['orden' => $i + 1]);
            }
        });
    }

    private function membresia(User $user, Condominio $condominio, bool $principal): void
    {
        $user->membresias()->updateOrCreate(
            ['condominio_id' => $condominio->id],
            ['es_principal' => $principal, 'activo' => true],
        );
    }

    private function asignar(User $user, int $condominioId, Rol $rol): void
    {
        setPermissionsTeamId($condominioId);
        $user->unsetRelation('roles');
        $user->assignRole($rol->value);
        setPermissionsTeamId(null);
    }
}
