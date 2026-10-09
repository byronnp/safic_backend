<?php

namespace App\Core\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una línea de la bitácora de plataforma. Solo inserción (la aplicación no tiene
 * UPDATE ni DELETE sobre la tabla).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $user_nombre
 * @property string $evento
 * @property string $entidad
 * @property string|null $entidad_id
 * @property string|null $etiqueta
 * @property int|null $condominio_id
 * @property array<string, mixed>|null $valores_anteriores
 * @property array<string, mixed>|null $valores_nuevos
 * @property string|null $ip
 * @property Carbon $created_at
 */
class RegistroBitacora extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bitacora_plataforma';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'valores_anteriores' => 'array',
            'valores_nuevos' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
