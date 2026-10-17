<?php

namespace App\Modules\Reservas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Un día (o unas horas de un día) en que un área no admite reservas.
 *
 * @property int $id
 * @property int $amenidad_id
 * @property Carbon $fecha
 * @property string|null $inicio HH:MM:SS; null = todo el día
 * @property string|null $fin
 * @property string $motivo
 */
class BloqueoArea extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    protected $table = 'bloqueos_area';

    protected $fillable = ['amenidad_id', 'fecha', 'inicio', 'fin', 'motivo', 'creado_por'];

    protected function casts(): array
    {
        return ['fecha' => 'date'];
    }
}
