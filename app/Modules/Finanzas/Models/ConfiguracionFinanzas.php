<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;

/**
 * Reglas de gasto del condominio. Sin fila se usan los valores por defecto (umbral $500,
 * quien aprobó una factura no la paga).
 *
 * @property string $umbral_segunda_aprobacion
 * @property bool $aprobador_puede_pagar
 */
class ConfiguracionFinanzas extends Model
{
    use BelongsToCondominio;

    public const UMBRAL_POR_DEFECTO = '500.00';

    protected $table = 'configuraciones_finanzas';

    protected $fillable = ['umbral_segunda_aprobacion', 'aprobador_puede_pagar'];

    protected function casts(): array
    {
        return ['aprobador_puede_pagar' => 'boolean'];
    }

    public static function vigente(): self
    {
        return self::query()->first() ?? new self([
            'umbral_segunda_aprobacion' => self::UMBRAL_POR_DEFECTO,
            'aprobador_puede_pagar' => false,
        ]);
    }
}
