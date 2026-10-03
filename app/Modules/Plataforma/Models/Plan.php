<?php

namespace App\Modules\Plataforma\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan de la plataforma. Tabla de plataforma (sin RLS ni BelongsToCondominio).
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property int $limite_administrativos
 * @property string $valor_unidad_sugerido
 * @property int $orden
 * @property bool $activo
 */
class Plan extends Model
{
    protected $table = 'planes';

    protected $fillable = ['codigo', 'nombre', 'limite_administrativos', 'valor_unidad_sugerido', 'orden', 'activo'];

    protected function casts(): array
    {
        return [
            'limite_administrativos' => 'integer',
            'valor_unidad_sugerido' => 'decimal:2',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }
}
