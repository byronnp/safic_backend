<?php

namespace App\Modules\Usuarios\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Pedido de un rol nuevo a la plataforma.
 *
 * @property int $id
 * @property int $condominio_id
 * @property int|null $user_id
 * @property string $nombre
 * @property string $descripcion
 * @property string $estado
 */
class SolicitudRol extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    protected $table = 'solicitudes_rol';

    protected $fillable = ['user_id', 'nombre', 'descripcion', 'estado'];

    protected $attributes = ['estado' => 'pendiente'];
}
