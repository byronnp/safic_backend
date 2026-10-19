<?php

namespace App\Modules\Reservas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;

/**
 * Una foto de los daños reportados al recibir un área.
 *
 * @property int $reserva_id
 * @property string $ruta
 * @property int $orden
 */
class ReservaDanoFoto extends Model
{
    use BelongsToCondominio;

    protected $table = 'reserva_danos_fotos';

    protected $fillable = ['reserva_id', 'ruta', 'orden'];
}
