<?php

namespace App\Modules\Plataforma\Actions;

use App\Core\Access\Catalogo;
use App\Core\Access\RolInfo;
use App\Core\Audit\BitacoraPlataforma;
use App\Core\Http\Exceptions\ApiException;
use App\Core\Menu\Models\MenuItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Caso de uso del super admin: crear un rol adicional o cambiar los permisos de un rol.
 * El cambio rige para todos los condominios al instante. Las reglas fijas del código
 * (contador solo lectura, residente y guardia sin permisos administrativos) no se saltan.
 */
final class GuardarRolAction
{
    public function __construct(private readonly Catalogo $catalogo) {}

    /**
     * @param  list<string>  $permisos
     */
    public function crear(string $nombre, array $permisos, int $actorId): Role
    {
        $clave = Str::slug($nombre, '_');

        if ($clave === '' || $this->existe($clave)) {
            throw new ApiException('ROL_EXISTENTE', 'Ya existe un rol con ese nombre.', 409);
        }

        return DB::transaction(function () use ($clave, $nombre, $permisos, $actorId): Role {
            $rol = null;
            $this->enGlobal(function () use ($clave, $nombre, &$rol): void {
                // Un rol del super admin: del condominio, sin reglas especiales y fuera del código (`sistema` falso)
                $rol = Role::create([
                    'name' => $clave, 'guard_name' => 'api', 'condominio_id' => null, 'etiqueta' => mb_substr(trim($nombre), 0, 80),
                    'ambito' => Catalogo::CONDOMINIO, 'sistema' => false, 'orden' => 100,
                ]);
            });

            /** @var Role $nuevo */
            $nuevo = $rol;
            $creado = $this->asignar($nuevo, null, $permisos, $actorId, 'creado');
            $this->asignarMenuInicial($creado, array_values(array_unique($permisos)));

            return $creado;
        });
    }

    /**
     * Menú web inicial de un rol nuevo: las pantallas que no piden permiso (inicio) y las de los permisos que se le dieron, la misma
     * regla con la que MenuSeeder reparte las pantallas a los roles del sistema. Sin esto el rol quedaba sin ninguna pantalla hasta
     * que el super admin lo asignara a mano en «Menú del sistema» (donde sigue pudiendo ajustarlo). Las pestañas de las apps del
     * residente y del guardia no hacen falta: salen solas de los permisos.
     *
     * @param  list<string>  $permisos
     */
    private function asignarMenuInicial(Role $rol, array $permisos): void
    {
        $hojas = MenuItem::query()
            ->where('ambito', MenuItem::AMBITO_CONDOMINIO)->where('activo', true)->whereNotNull('ruta')
            ->where(fn ($q) => $q->whereNull('permiso')->orWhereIn('permiso', $permisos))
            ->pluck('id');

        DB::table('menu_item_rol')->insertOrIgnore($hojas->map(fn (int $id): array => ['menu_item_id' => $id, 'role_id' => $rol->id])->all());
    }

    /**
     * @param  list<string>  $permisos  El conjunto completo que queda (no un parche)
     */
    public function permisos(string $clave, array $permisos, int $actorId): Role
    {
        return DB::transaction(function () use ($clave, $permisos, $actorId): Role {
            $rol = ListarRolesAdminAction::rolesDeCondominio()->firstWhere('name', $clave)
                ?? throw new ApiException('ROL_NO_ENCONTRADO', 'Ese rol no existe o no se edita aquí.', 404);

            return $this->asignar($rol, $this->catalogo->rol($clave), $permisos, $actorId, 'permisos');
        });
    }

    /**
     * @param  list<string>  $permisos
     */
    private function asignar(Role $rol, ?RolInfo $info, array $permisos, int $actorId, string $accion): Role
    {
        $conjunto = array_values(array_unique($permisos));

        foreach ($conjunto as $clave) {
            $permiso = $this->catalogo->permiso($clave);
            if ($permiso === null || $permiso->esDePlataforma()) {
                throw new ApiException('PERMISO_INVALIDO', "El permiso «{$clave}» no se puede asignar a un rol de condominio.", 422);
            }
            $motivo = $info?->motivoBloqueo($permiso);
            if ($motivo !== null) {
                throw new ApiException('PERMISO_BLOQUEADO', $motivo, 422);
            }
        }

        if ($info !== null) {
            foreach ($info->obligatorios as $obligatorio) {
                if (! in_array($obligatorio, $conjunto, true)) {
                    throw new ApiException('PERMISO_OBLIGATORIO', "El rol {$info->etiqueta} necesita «{$this->catalogo->permiso($obligatorio)?->etiqueta}».", 422);
                }
            }
        }

        $antes = $rol->permissions->pluck('name')->sort()->values()->all();

        $this->enGlobal(function () use ($rol, $conjunto): void {
            $rol->syncPermissions($conjunto);
        });

        BitacoraPlataforma::registrar(
            $accion === 'creado' ? 'creado' : 'actualizado',
            'rol',
            $rol->name,
            $info->etiqueta ?? ($rol->etiqueta ?? Str::headline($rol->name)),
            $accion === 'creado' ? null : ['permisos' => $antes],
            ['permisos' => collect($conjunto)->sort()->values()->all()],
        );

        return $rol->load('permissions');
    }

    private function existe(string $clave): bool
    {
        return Role::query()->whereNull('condominio_id')->where('guard_name', 'api')->where('name', $clave)->exists();
    }

    /** Los roles son globales (equipo nulo) y el caché de permisos se descarta al terminar. */
    private function enGlobal(callable $accion): void
    {
        $anterior = getPermissionsTeamId();
        setPermissionsTeamId(null);

        try {
            $accion();
        } finally {
            setPermissionsTeamId($anterior);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->catalogo->olvidar();
        }
    }
}
