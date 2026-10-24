<?php

namespace App\Modules\Asambleas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;

/**
 * Foto de una unidad al instalar la asamblea (no se edita después).
 *
 * @property int $id
 * @property int $asamblea_id
 * @property int $unidad_id
 * @property string $codigo
 * @property string $alicuota
 * @property bool $en_mora
 * @property bool $puede_votar
 * @property int|null $propietario_persona_id
 * @property list<array{persona_id: int, nombre: string}> $propietarios
 */
class AsambleaUnidad extends Model
{
    use BelongsToCondominio;

    protected $table = 'asamblea_unidades';

    protected $fillable = ['asamblea_id', 'unidad_id', 'codigo', 'alicuota', 'en_mora', 'puede_votar', 'propietario_persona_id', 'propietarios'];

    protected function casts(): array
    {
        return ['en_mora' => 'boolean', 'puede_votar' => 'boolean', 'propietarios' => 'array'];
    }
}
