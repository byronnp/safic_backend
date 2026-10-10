<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Cuenta bancaria de un proveedor (adonde el condominio le paga). El número va cifrado y fuera
 * de la auditoría. `activa` es la vigente; `pendiente` espera sus 24 h.
 *
 * @property int $id
 * @property int $proveedor_id
 * @property string $banco
 * @property string $tipo corriente|ahorros
 * @property string $numero
 * @property string $titular
 * @property string $estado pendiente|activa|reemplazada|cancelada
 * @property Carbon|null $activa_en
 * @property int|null $solicitada_por
 */
class ProveedorCuenta extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const TIPOS = ['corriente', 'ahorros'];

    protected $table = 'proveedor_cuentas';

    /** @var list<string> */
    protected array $auditExclude = ['numero'];

    protected $fillable = ['proveedor_id', 'banco', 'tipo', 'numero', 'titular', 'estado', 'activa_en', 'solicitada_por'];

    protected function casts(): array
    {
        return ['numero' => 'encrypted', 'activa_en' => 'datetime'];
    }
}
