<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuánto de un pago a proveedor se aplicó a una factura.
 *
 * @property int $pago_proveedor_id
 * @property int $gasto_id
 * @property string $monto
 */
class PagoProveedorAplicacion extends Model
{
    use BelongsToCondominio;

    protected $table = 'pago_proveedor_aplicaciones';

    protected $fillable = ['pago_proveedor_id', 'gasto_id', 'monto'];
}
