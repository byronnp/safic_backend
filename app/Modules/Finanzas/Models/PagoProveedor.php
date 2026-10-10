<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Pago a un proveedor (la app no mueve dinero: registra la transferencia hecha desde el banco).
 *
 * @property int $id
 * @property int $numero
 * @property int $proveedor_id
 * @property int|null $proveedor_cuenta_id cuenta del proveedor a la que se pagó
 * @property int|null $cuenta_bancaria_id cuenta del condominio desde la que se pagó
 * @property string $monto
 * @property Carbon $fecha_pago
 * @property string $referencia
 * @property string|null $comprobante_ruta
 * @property string $estado registrado|anulado
 * @property string|null $motivo_anulacion
 * @property int|null $registrado_por
 */
class PagoProveedor extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    protected $table = 'pagos_proveedor';

    protected $fillable = [
        'numero', 'proveedor_id', 'proveedor_cuenta_id', 'cuenta_bancaria_id', 'monto', 'fecha_pago', 'referencia',
        'comprobante_ruta', 'estado', 'motivo_anulacion', 'registrado_por', 'anulado_por', 'anulado_en',
    ];

    protected function casts(): array
    {
        return ['fecha_pago' => 'date', 'anulado_en' => 'datetime'];
    }

    /** "PP-0087" */
    public function codigo(): string
    {
        return 'PP-'.str_pad((string) $this->numero, 4, '0', STR_PAD_LEFT);
    }

    /** @return BelongsTo<Proveedor, $this> */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /** @return HasMany<PagoProveedorAplicacion, $this> */
    public function aplicaciones(): HasMany
    {
        return $this->hasMany(PagoProveedorAplicacion::class);
    }
}
