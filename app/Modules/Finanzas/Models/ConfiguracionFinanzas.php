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
 * @property string $tolerancia_bancaria Diferencia de centavos que no impide dar una cuota por pagada
 */
class ConfiguracionFinanzas extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const UMBRAL_POR_DEFECTO = '500.00';

    public const TOLERANCIA_POR_DEFECTO = '0.50';

    protected $table = 'configuraciones_finanzas';

    protected $fillable = ['umbral_segunda_aprobacion', 'aprobador_puede_pagar', 'tolerancia_bancaria'];

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
        ]);
    }
}
