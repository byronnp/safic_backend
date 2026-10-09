<?php

namespace App\Core\Menu\Models;

use App\Core\Audit\RegistraBitacora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role;

/**
 * Ítem del menú del sistema. Catálogo global que administra el super admin
 * (sin condominio_id ni RLS). Las hojas tienen `ruta`; los módulos y secciones
 * solo agrupan.
 *
 * @property int $id
 * @property string $clave
 * @property int|null $padre_id
 * @property string $ambito
 * @property string $etiqueta
 * @property string $icono
 * @property string|null $ruta
 * @property string|null $permiso
 * @property bool $seccion
 * @property int $orden
 * @property bool $activo
 */
class MenuItem extends Model
{
    use RegistraBitacora;

    public function bitacoraEntidad(): string
    {
        return 'menu';
    }

    public function bitacoraEtiqueta(): string
    {
        return $this->ambito.': '.$this->etiqueta;
    }

    public const AMBITO_CONDOMINIO = 'condominio';

    public const AMBITO_PLATAFORMA = 'plataforma';

    protected $table = 'menu_items';

    protected $fillable = ['clave', 'padre_id', 'ambito', 'etiqueta', 'icono', 'ruta', 'permiso', 'seccion', 'orden', 'activo'];

    protected function casts(): array
    {
        return [
            'padre_id' => 'integer',
            'seccion' => 'boolean',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'padre_id');
    }

    /**
     * Perfiles (roles globales) que ven este ítem.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'menu_item_rol', 'menu_item_id', 'role_id');
    }
}
