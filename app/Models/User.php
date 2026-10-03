<?php

namespace App\Models;

use App\Core\Permissions\Rol;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Plataforma\Models\Membresia;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Traits\HasRoles;

/**
 * Usuario de SAFIC. Un mismo usuario puede pertenecer a varios condominios
 * (uno principal y otros secundarios) con roles distintos en cada uno.
 */
#[Fillable(['name', 'email', 'password', 'activo'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** Guard de spatie/laravel-permission. */
    protected $guard_name = 'api';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Membresia, $this>
     */
    public function membresias(): HasMany
    {
        return $this->hasMany(Membresia::class);
    }

    /**
     * Solo los condominios donde la membresía está activa y vigente.
     *
     * @return BelongsToMany<Condominio, $this>
     */
    public function condominiosActivos(): BelongsToMany
    {
        return $this->belongsToMany(Condominio::class, 'condominio_user')
            ->using(Membresia::class)
            ->withPivot(['es_principal', 'activo', 'acceso_hasta'])
            ->wherePivot('activo', true)
            ->where(fn ($q) => $q->whereNull('condominio_user.acceso_hasta')->orWhere('condominio_user.acceso_hasta', '>=', now()->toDateString()))
            ->where('condominios.estado', '!=', Condominio::ESTADO_SUSPENDIDO)
            ->orderByDesc('condominio_user.es_principal')
            ->orderBy('condominios.nombre');
    }

    public function tieneMembresiaActivaEn(int $condominioId): bool
    {
        return $this->condominiosActivos()->whereKey($condominioId)->exists();
    }

    /**
     * Roles y permisos de plataforma (super admin, soporte, cobranza…). Viven en
     * el "condominio" 0 de spatie, así que se leen cambiando de equipo un momento
     * y se restaura el equipo anterior. Devuelve null si no tiene rol de plataforma.
     *
     * @return array{roles: list<string>, permisos: list<string>}|null
     */
    public function contextoPlataforma(): ?array
    {
        $anterior = getPermissionsTeamId();
        setPermissionsTeamId(Rol::EQUIPO_PLATAFORMA);
        $this->unsetRelation('roles')->unsetRelation('permissions');

        try {
            $roles = $this->getRoleNames()->values()->all();
            $permisos = $roles === [] ? [] : $this->getAllPermissions()->pluck('name')->sort()->values()->all();
        } finally {
            setPermissionsTeamId($anterior);
            $this->unsetRelation('roles')->unsetRelation('permissions');
        }

        return $roles === [] ? null : ['roles' => $roles, 'permisos' => $permisos];
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * El token no lleva roles ni condominios: se consultan en cada petición.
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
