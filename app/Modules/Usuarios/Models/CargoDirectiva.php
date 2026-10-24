<?php

namespace App\Modules\Usuarios\Models;

use App\Core\Access\Catalogo;
use App\Core\Access\RolInfo;
use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Periodo de una persona en un cargo de la directiva.
 *
 * @property int $id
 * @property int $condominio_id
 * @property string $cargo
 * @property int $persona_id
 * @property int|null $user_id
 * @property Carbon $periodo_inicio
 * @property Carbon $periodo_fin
 * @property string $acta
 * @property Carbon|null $cerrado_en
 */
class CargoDirectiva extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    protected $table = 'cargos_directiva';

    protected $fillable = ['cargo', 'persona_id', 'user_id', 'periodo_inicio', 'periodo_fin', 'acta', 'cerrado_en'];

    protected function casts(): array
    {
        return [
            'periodo_inicio' => 'date',
            'periodo_fin' => 'date',
            'cerrado_en' => 'date',
        ];
    }

    /**
     * Los cargos de la directiva (roles marcados `es_cargo` en el catálogo), en el orden en que se muestran.
     *
     * @return list<RolInfo>
     */
    public static function cargos(): array
    {
        return app(Catalogo::class)->cargos();
    }
}
