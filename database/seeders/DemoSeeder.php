<?php

namespace Database\Seeders;

use App\Core\Permissions\Rol;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Bloque;
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

        $jardines = Condominio::updateOrCreate(['codigo' => 'SF-0001'], [
            'nombre' => 'Conjunto Jardines del Valle', 'tipo' => 'conjunto',
            'total_unidades' => 148, 'estado' => Condominio::ESTADO_ACTIVO,
        ]);
        $arupos = Condominio::updateOrCreate(['codigo' => 'SF-0012'], [
            'nombre' => 'Conjunto Los Arupos', 'tipo' => 'conjunto',
            'total_unidades' => 130, 'estado' => Condominio::ESTADO_ACTIVO,
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

        $tenant->run($jardines->id, function () {
            foreach (['Torre A', 'Torre B', 'Torre C'] as $i => $nombre) {
                Bloque::firstOrCreate(['nombre' => $nombre], ['orden' => $i + 1]);
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
