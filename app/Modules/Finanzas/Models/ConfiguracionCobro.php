<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cómo cobra el condominio sus cuotas (una fila por condominio).
 *
 * @property int $id
 * @property int $condominio_id
 * @property string $metodo general|tipo|alicuota|unidad
 * @property string|null $cuota_general
 * @property string|null $presupuesto_mensual
 * @property int $dia_vencimiento 1–28; 0 = último día del mes
 * @property Carbon $aplica_desde
 */
class ConfiguracionCobro extends Model
{
    use BelongsToCondominio;

    public const METODO_GENERAL = 'general';

    public const METODO_TIPO = 'tipo';

    public const METODO_ALICUOTA = 'alicuota';

    public const METODO_UNIDAD = 'unidad';

    public const METODOS = [self::METODO_GENERAL, self::METODO_TIPO, self::METODO_ALICUOTA, self::METODO_UNIDAD];

    protected $table = 'configuraciones_cobro';

    protected $fillable = ['metodo', 'cuota_general', 'presupuesto_mensual', 'dia_vencimiento', 'aplica_desde'];

    protected function casts(): array
    {
        return [
            'cuota_general' => 'decimal:2',
            'presupuesto_mensual' => 'decimal:2',
            'dia_vencimiento' => 'integer',
            'aplica_desde' => 'date',
        ];
    }
}
