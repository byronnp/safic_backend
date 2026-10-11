<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;

/**
 * «Si la descripción del banco contiene `patron`, es de este `tipo`» (interés, comisión u otro).
 *
 * @property int $id
 * @property string $patron
 * @property string $tipo
 * @property int|null $creada_por
 */
class ReglaConciliacion extends Model
{
    use BelongsToCondominio;

    protected $table = 'reglas_conciliacion';

    protected $fillable = ['patron', 'tipo', 'creada_por'];
}
