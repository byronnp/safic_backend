<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Una línea del estado de cuenta del banco. `automatico` y `conciliado` cuentan como resueltos;
 * `sugerido` espera que alguien confirme y `sin_identificar` que alguien lo clasifique.
 *
 * @property int $id
 * @property int $estado_cuenta_id
 * @property int $cuenta_bancaria_id
 * @property Carbon $fecha
 * @property string $descripcion
 * @property string|null $referencia
 * @property string $monto
 * @property string|null $saldo
 * @property string $huella
 * @property string $estado automatico|sugerido|sin_identificar|conciliado
 * @property string|null $tipo pago_residente|pago_proveedor|interes|comision|otro
 * @property int|null $pago_id
 * @property int|null $pago_proveedor_id
 * @property string|null $propuesta_tipo
 * @property int|null $propuesta_id
 * @property string|null $propuesta_texto
 */
class MovimientoBancario extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const AUTOMATICO = 'automatico';

    public const SUGERIDO = 'sugerido';

    public const SIN_IDENTIFICAR = 'sin_identificar';

    public const CONCILIADO = 'conciliado';

    public const TIPOS_MANUALES = ['interes', 'comision', 'otro'];

    protected $table = 'movimientos_bancarios';

    protected $fillable = [
        'estado_cuenta_id', 'cuenta_bancaria_id', 'fecha', 'descripcion', 'referencia', 'monto', 'saldo', 'huella', 'estado', 'tipo',
        'pago_id', 'pago_proveedor_id', 'propuesta_tipo', 'propuesta_id', 'propuesta_texto', 'conciliado_por', 'conciliado_en',
    ];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'decimal:2', 'saldo' => 'decimal:2', 'conciliado_en' => 'datetime'];
    }

    public function resuelto(): bool
    {
        return in_array($this->estado, [self::AUTOMATICO, self::CONCILIADO], true);
    }
}
