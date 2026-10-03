<?php

namespace App\Models;

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
     * @return BelongsToMany<Condominio, $this, Membresia, 'pivot'>
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
