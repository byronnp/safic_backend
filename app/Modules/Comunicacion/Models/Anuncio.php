<?php

namespace App\Modules\Comunicacion\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Un anuncio de la administración.
 *
 * @property int $id
 * @property string $titulo
 * @property string $mensaje
 * @property string $destino_tipo todos|bloque|unidades
 * @property string $destino_texto
 * @property bool $fijado
 * @property bool $requiere_confirmacion
 * @property Carbon|null $expira_en
 * @property string|null $adjunto_ruta
 * @property string|null $adjunto_nombre
 * @property int|null $publicado_por
 * @property Carbon $created_at
 */
class Anuncio extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const TODOS = 'todos';

    public const BLOQUE = 'bloque';

    public const UNIDADES = 'unidades';

    protected $table = 'anuncios';

    protected $fillable = [
        'titulo', 'mensaje', 'destino_tipo', 'destino_texto', 'fijado', 'requiere_confirmacion', 'expira_en', 'adjunto_ruta', 'adjunto_nombre', 'publicado_por',
    ];

    protected function casts(): array
    {
        return ['fijado' => 'boolean', 'requiere_confirmacion' => 'boolean', 'expira_en' => 'date'];
    }
}
