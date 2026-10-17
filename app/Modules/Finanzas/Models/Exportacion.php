<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un archivo exportado, con su código único (EXP-7Q2K) y quién lo bajó.
 *
 * @property int $id
 * @property string $codigo
 * @property string $reporte
 * @property string $titulo
 * @property array<string, mixed>|null $parametros
 * @property string $formato
 * @property int $filas
 * @property int|null $user_id
 * @property string $user_nombre
 * @property string|null $user_rol
 * @property Carbon $created_at
 */
class Exportacion extends Model
{
    use BelongsToCondominio;

    protected $table = 'exportaciones';

    protected $fillable = ['codigo', 'reporte', 'titulo', 'parametros', 'formato', 'filas', 'user_id', 'user_nombre', 'user_rol'];

    protected function casts(): array
    {
        return ['parametros' => 'array', 'filas' => 'integer'];
    }
}
