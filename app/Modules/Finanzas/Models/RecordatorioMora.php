<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un aviso de cuota enviado a una unidad (por vencer, vence hoy, atrasada, o manual).
 *
 * @property int $unidad_id
 * @property string $tipo antes|dia|atraso|manual
 * @property Carbon $referencia
 * @property string|null $enviado_a
 * @property int|null $enviado_por
 * @property Carbon $created_at
 */
class RecordatorioMora extends Model
{
    use BelongsToCondominio;

    protected $table = 'recordatorios_mora';

    protected $fillable = ['unidad_id', 'tipo', 'referencia', 'enviado_a', 'enviado_por'];

    protected function casts(): array
    {
        return ['referencia' => 'date'];
    }
}
