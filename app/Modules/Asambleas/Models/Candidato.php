<?php

namespace App\Modules\Asambleas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Candidato a un cargo de la directiva en un punto de elección.
 *
 * @property int $id
 * @property int $asamblea_id
 * @property int $punto_id
 * @property int $persona_id
 * @property string $cargo presidente|vicepresidente|secretario|tesorero
 * @property int|null $postulado_por
 */
class Candidato extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    protected $table = 'candidatos';

    protected $fillable = ['asamblea_id', 'punto_id', 'persona_id', 'cargo', 'postulado_por'];
}
