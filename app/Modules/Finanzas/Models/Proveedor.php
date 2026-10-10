<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Proveedor del condominio (se identifica por RUC).
 *
 * @property int $id
 * @property int $condominio_id
 * @property string $ruc
 * @property string $razon_social
 * @property string|null $categoria
 * @property string|null $email
 * @property string|null $telefono
 * @property bool $activo
 */
class Proveedor extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    protected $table = 'proveedores';

    protected $fillable = ['ruc', 'razon_social', 'categoria', 'email', 'telefono', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /** @return HasMany<ProveedorCuenta, $this> */
    public function cuentas(): HasMany
    {
        return $this->hasMany(ProveedorCuenta::class);
    }

    /** @return HasMany<Gasto, $this> */
    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class);
    }
}
