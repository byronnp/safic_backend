<?php

namespace App\Modules\Asambleas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Reglas de asamblea de un condominio. Los valores por defecto son los de la Ley de Propiedad Horizontal; cada condominio los
 * ajusta si su reglamento interno lo indica. Sin fila, rigen los valores por defecto.
 *
 * @property bool $permite_virtual
 * @property bool $mora_cuenta_para_quorum
 * @property bool $voto_secreto
 * @property string|null $porcentaje_reforma
 */
class ConfiguracionAsambleas extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    protected $table = 'configuracion_asambleas';

    protected $fillable = ['permite_virtual', 'mora_cuenta_para_quorum', 'voto_secreto', 'porcentaje_reforma'];

    protected function casts(): array
    {
        return ['permite_virtual' => 'boolean', 'mora_cuenta_para_quorum' => 'boolean', 'voto_secreto' => 'boolean', 'porcentaje_reforma' => 'decimal:2'];
    }

    /** Los valores por defecto cuando el condominio aún no guardó sus reglas. */
    public static function porDefecto(): self
    {
        return new self(['permite_virtual' => false, 'mora_cuenta_para_quorum' => true, 'voto_secreto' => false, 'porcentaje_reforma' => null]);
    }
}
