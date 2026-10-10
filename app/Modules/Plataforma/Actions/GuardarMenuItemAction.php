<?php

namespace App\Modules\Plataforma\Actions;

use App\Core\Audit\BitacoraPlataforma;
use App\Core\Http\Exceptions\ApiException;
use App\Core\Menu\Models\MenuItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Caso de uso del super admin: crear o editar un ítem del menú del sistema. El menú es un
 * catálogo global: el cambio rige para todos los condominios al instante. Los ítems no se
 * borran (el seeder los volvería a crear): se desactivan.
 */
final class GuardarMenuItemAction
{
    /**
     * @param  array<string, mixed>  $datos  Validados por GuardarMenuItemRequest
     */
    public function crear(array $datos): MenuItem
    {
        return DB::transaction(function () use ($datos): MenuItem {
            $ambito = (string) $datos['ambito'];
            $padre = $this->padre($datos['padre_id'] ?? null, $ambito);
            $esGrupo = empty($datos['ruta']);

            if ($esGrupo && $padre !== null) {
                throw ValidationException::withMessages(['padre_id' => 'Un grupo no va dentro de otro grupo.']);
            }

            $item = MenuItem::create([
                'clave' => $this->claveNueva($ambito, (string) $datos['etiqueta']),
                'padre_id' => $padre?->id,
                'ambito' => $ambito,
                'etiqueta' => $datos['etiqueta'],
                'icono' => $datos['icono'],
                'ruta' => $datos['ruta'] ?? null,
                'permiso' => $datos['permiso'] ?? null,
                'seccion' => $esGrupo && ($datos['seccion'] ?? false),
                'orden' => ((int) MenuItem::query()->where('ambito', $ambito)->where('padre_id', $padre?->id)->max('orden')) + 10,
                'activo' => $datos['activo'] ?? true,
            ]);

            if (! $esGrupo) {
                $this->asignarPerfiles($item, $datos['roles'] ?? []);
            }

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $datos  Solo lo que cambia
     */
    public function editar(int $id, array $datos): MenuItem
    {
        return DB::transaction(function () use ($id, $datos): MenuItem {
            $item = MenuItem::query()->lockForUpdate()->findOrFail($id);
            $esGrupo = $item->ruta === null;

            if (! $esGrupo && array_key_exists('ruta', $datos) && empty($datos['ruta'])) {
                throw ValidationException::withMessages(['ruta' => 'Una pantalla necesita su ruta. Para quitarla del menú, desactívala.']);
            }
            if ($esGrupo && ! empty($datos['ruta'])) {
                throw ValidationException::withMessages(['ruta' => 'Un grupo no tiene pantalla propia.']);
            }
            if ($esGrupo && array_key_exists('roles', $datos)) {
                throw ValidationException::withMessages(['roles' => 'Los perfiles se asignan a cada pantalla, no al grupo.']);
            }

            $item->fill(array_intersect_key($datos, array_flip(['etiqueta', 'icono', 'ruta', 'permiso', 'activo'])))->save();

            if (array_key_exists('roles', $datos)) {
                $this->asignarPerfiles($item, $datos['roles'] ?? []);
            }

            return $item;
        });
    }

    private function padre(mixed $padreId, string $ambito): ?MenuItem
    {
        if ($padreId === null) {
            return null;
        }

        $padre = MenuItem::query()->where('ambito', $ambito)->find($padreId);

        if ($padre === null || $padre->ruta !== null || $padre->padre_id !== null) {
            throw ValidationException::withMessages(['padre_id' => 'Elige un grupo de este mismo menú.']);
        }

        return $padre;
    }

    private function claveNueva(string $ambito, string $etiqueta): string
    {
        $base = $ambito.'.'.(Str::slug($etiqueta) ?: 'item');
        $clave = $base;
        for ($n = 2; MenuItem::query()->where('clave', $clave)->exists(); $n++) {
            $clave = "{$base}-{$n}";
        }

        return $clave;
    }

    /**
     * @param  list<string>  $nombres  Perfiles (roles globales) del mismo ámbito del ítem
     */
    private function asignarPerfiles(MenuItem $item, array $nombres): void
    {
        $validos = array_column(ListarMenuSistemaAction::rolesDelAmbito($item->ambito), 'clave');
        $ajenos = array_diff($nombres, $validos);

        if ($ajenos !== []) {
            throw new ApiException('PERFIL_INVALIDO', 'Algún perfil no corresponde a este menú: '.implode(', ', $ajenos).'.', 422);
        }

        $ids = Role::query()->whereNull('condominio_id')->where('guard_name', 'api')->whereIn('name', $nombres)->pluck('id')->all();
        $antes = $item->roles()->pluck('name')->sort()->values()->all();
        $item->roles()->sync($ids);
        $despues = collect($nombres)->unique()->sort()->values()->all();

        if ($antes !== $despues) {
            BitacoraPlataforma::registrar('actualizado', 'menu', $item->id, $item->ambito.': '.$item->etiqueta, ['perfiles' => $antes], ['perfiles' => $despues]);
        }
    }
}
