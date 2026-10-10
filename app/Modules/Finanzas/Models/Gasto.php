<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Factura de un proveedor (cuenta por pagar). Los montos son decimal(12,2).
 *
 * @property int $id
 * @property int $proveedor_id
 * @property string $numero
 * @property string|null $clave_acceso
 * @property string $origen xml|manual
 * @property string|null $categoria
 * @property string|null $descripcion
 * @property Carbon $fecha_emision
 * @property Carbon $vence_el
 * @property string $subtotal
 * @property string $iva
 * @property string $total
 * @property string $pagado
 * @property string $estado por_aprobar|aprobada|rechazada|pagada
 * @property string|null $xml_ruta
 * @property string|null $pdf_ruta
 * @property int|null $registrado_por
 */
class Gasto extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const ESTADOS = ['por_aprobar', 'aprobada', 'rechazada', 'pagada'];

    protected $table = 'gastos';

    protected $fillable = [
        'proveedor_id', 'numero', 'clave_acceso', 'origen', 'categoria', 'descripcion', 'fecha_emision', 'vence_el',
        'subtotal', 'iva', 'total', 'pagado', 'estado', 'xml_ruta', 'pdf_ruta', 'registrado_por',
    ];

    protected function casts(): array
    {
        return ['fecha_emision' => 'date', 'vence_el' => 'date'];
    }

    /** @return BelongsTo<Proveedor, $this> */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }
}
