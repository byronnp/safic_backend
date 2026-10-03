<?php

namespace App\Modules\Plataforma\Models;

use App\Models\User;
use Database\Factories\CondominioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tabla de nivel plataforma (no usa BelongsToCondominio ni RLS): es el propio
 * catálogo de condominios que administra el super admin.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property int $total_unidades
 * @property string $estado
 * @property array<string, mixed>|null $marca
 */
class Condominio extends Model
{
    /** @use HasFactory<CondominioFactory> */
    use HasFactory;

    public const ESTADO_ACTIVO = 'activo';

    public const ESTADO_PRUEBA = 'prueba';

    public const ESTADO_SOLO_LECTURA = 'solo_lectura';

    public const ESTADO_SUSPENDIDO = 'suspendido';

    protected $table = 'condominios';

    protected $fillable = [
        'codigo', 'nombre', 'tipo', 'ruc', 'razon_social', 'total_unidades',
        'moneda', 'pais', 'zona_horaria', 'estado', 'marca',
    ];

    protected function casts(): array
    {
        return [
            'total_unidades' => 'integer',
            'marca' => 'array',
        ];
    }

    protected static function newFactory(): CondominioFactory
    {
        return CondominioFactory::new();
    }

    /**
     * @return BelongsToMany<User, $this, Membresia, 'pivot'>
     */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'condominio_user')
            ->using(Membresia::class)
            ->withPivot(['es_principal', 'activo', 'acceso_hasta'])
            ->withTimestamps();
    }
}
