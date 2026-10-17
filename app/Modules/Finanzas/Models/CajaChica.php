<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * El fondo fijo de caja chica del condominio y quién responde por él.
 *
 * @property string $fondo
 * @property string $responsable_nombre
 */
class CajaChica extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    protected $table = 'cajas_chicas';

    protected $fillable = ['fondo', 'responsable_nombre'];

    protected function casts(): array
    {
        return ['fondo' => 'decimal:2'];
    }
}
