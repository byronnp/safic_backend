<?php

namespace App\Core\Access;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El catálogo de permisos y roles, leído de la base. Es la única fuente: el código no repite etiquetas, ámbitos ni reglas. Se guarda en
 * caché y se descarta cuando cambia (seeders y pantalla de roles llaman a `olvidar()`).
 *
 * Los enums Permiso y Rol son solo nombres que el código necesita escribir (una ruta, una regla); aquí está lo demás.
 */
final class Catalogo
{
    public const CONDOMINIO = 'condominio';

    public const PLATAFORMA = 'plataforma';

    private const CACHE = 'acceso.catalogo';

    /** @var array{permisos: array<string, PermisoInfo>, roles: array<string, RolInfo>}|null */
    private ?array $memo = null;

    /** @return array<string, PermisoInfo> por clave, en el orden en que se definieron */
    public function permisos(?string $ambito = null): array
    {
        $todos = $this->cargar()['permisos'];

        return $ambito === null ? $todos : array_filter($todos, fn (PermisoInfo $p): bool => $p->ambito === $ambito);
    }

    public function permiso(string $clave): ?PermisoInfo
    {
        $todos = $this->cargar()['permisos'];

        return array_key_exists($clave, $todos) ? $todos[$clave] : null;
    }

    /** @return array<string, RolInfo> por clave, en el orden definido */
    public function roles(?string $ambito = null): array
    {
        $todos = $this->cargar()['roles'];

        return $ambito === null ? $todos : array_filter($todos, fn (RolInfo $r): bool => $r->ambito === $ambito);
    }

    public function rol(string $clave): ?RolInfo
    {
        $todos = $this->cargar()['roles'];

        return array_key_exists($clave, $todos) ? $todos[$clave] : null;
    }

    /** Los cargos de la directiva, en su orden. @return list<RolInfo> */
    public function cargos(): array
    {
        return array_values(array_filter($this->roles(self::CONDOMINIO), fn (RolInfo $r): bool => $r->esCargo));
    }

    /** Los perfiles que el administrador asigna directamente a un usuario. @return list<RolInfo> */
    public function asignables(): array
    {
        return array_values(array_filter($this->roles(self::CONDOMINIO), fn (RolInfo $r): bool => $r->asignable));
    }

    /** Roles que no pueden trabajar sin la verificación en dos pasos (nombres). @return list<string> */
    public function nombresQueExigenDobleFactor(): array
    {
        return array_values(array_map(fn (RolInfo $r): string => $r->clave, array_filter($this->roles(), fn (RolInfo $r): bool => $r->exigeDobleFactor)));
    }

    /** Roles que consumen cupo del plan (nombres). @return list<string> */
    public function nombresQueCuentanParaCupo(): array
    {
        return array_values(array_map(fn (RolInfo $r): string => $r->clave, array_filter($this->roles(), fn (RolInfo $r): bool => $r->cuentaCupo === true)));
    }

    /** El nombre de un rol para mostrar (correos y mensajes); un rol sin etiqueta se escribe legible desde su clave. */
    public function etiquetaDeRol(string $clave): string
    {
        $rol = $this->rol($clave);

        return $rol === null ? Str::headline($clave) : $rol->etiqueta;
    }

    /** Descarta lo guardado: se vuelve a leer de la base en el siguiente uso. */
    public function olvidar(): void
    {
        $this->memo = null;
        Cache::forget(self::CACHE);
    }

    /** @return array{permisos: array<string, PermisoInfo>, roles: array<string, RolInfo>} */
    private function cargar(): array
    {
        return $this->memo ??= Cache::remember(self::CACHE, 600, fn (): array => $this->leer());
    }

    /** @return array{permisos: array<string, PermisoInfo>, roles: array<string, RolInfo>} */
    private function leer(): array
    {
        $permisos = [];
        foreach (DB::table('permissions')->where('guard_name', 'api')->orderBy('id')->get() as $p) {
            $permisos[$p->name] = new PermisoInfo($p->name, (string) ($p->etiqueta ?? $p->name), (string) ($p->grupo ?? 'Núcleo'), (string) $p->ambito, (bool) $p->administrativo, (bool) $p->escritura);
        }

        /** @var Collection<int, list<string>> $obligatorios */
        $obligatorios = DB::table('rol_permisos_obligatorios as o')->join('permissions as p', 'p.id', '=', 'o.permission_id')
            ->get(['o.role_id', 'p.name'])->groupBy('role_id')->map(fn ($filas): array => $filas->pluck('name')->all());

        $roles = [];
        foreach (DB::table('roles')->whereNull('condominio_id')->where('guard_name', 'api')->orderBy('orden')->orderBy('id')->get() as $r) {
            $roles[$r->name] = new RolInfo(
                (int) $r->id, $r->name, (string) ($r->etiqueta ?? $r->name), (string) $r->ambito, (bool) $r->es_cargo, $r->cuenta_cupo === null ? null : (bool) $r->cuenta_cupo,
                (bool) $r->asignable, (bool) $r->requiere_vigencia, (bool) $r->exige_doble_factor, (bool) $r->solo_lectura, (bool) $r->sin_administrativos, (bool) $r->sistema, (int) $r->orden,
                $obligatorios[$r->id] ?? [],
            );
        }

        return ['permisos' => $permisos, 'roles' => $roles];
    }
}
