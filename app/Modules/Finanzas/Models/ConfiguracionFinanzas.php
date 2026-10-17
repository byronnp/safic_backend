<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Reglas de gasto del condominio. Sin fila se usan los valores por defecto (umbral $500,
 * quien aprobó una factura no la paga).
 *
 * @property string $umbral_segunda_aprobacion
 * @property bool $aprobador_puede_pagar
 * @property int $dias_gracia Días después del vencimiento antes de considerar la mora
 * @property int $meses_restriccion Meses de deuda vencida desde los que se restringen las áreas comunes
 * @property int $dias_aviso_restriccion Días entre la notificación y la restricción (Decreto 462)
 * @property string $tolerancia_bancaria Diferencia de centavos que no impide dar una cuota por pagada
 */
class ConfiguracionFinanzas extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const UMBRAL_POR_DEFECTO = '500.00';

    public const TOLERANCIA_POR_DEFECTO = '0.50';

    protected $table = 'configuraciones_finanzas';

    protected $fillable = [
        'umbral_segunda_aprobacion', 'aprobador_puede_pagar', 'tolerancia_bancaria', 'dias_gracia', 'meses_restriccion', 'dias_aviso_restriccion',
    ];

    protected function casts(): array
    {
        return ['aprobador_puede_pagar' => 'boolean', 'umbral_segunda_aprobacion' => 'decimal:2', 'tolerancia_bancaria' => 'decimal:2'];
    }

    public static function vigente(): self
    {
        return self::query()->first() ?? new self([
            'umbral_segunda_aprobacion' => self::UMBRAL_POR_DEFECTO,
            'aprobador_puede_pagar' => false,
            'tolerancia_bancaria' => self::TOLERANCIA_POR_DEFECTO,
            'dias_gracia' => 5,
            'meses_restriccion' => 3,
            'dias_aviso_restriccion' => 5,
        ]);
    }
}
