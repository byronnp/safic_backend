<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Cuenta bancaria del condominio a la que los residentes transfieren. El número va cifrado y
 * no entra a la auditoría. La principal es la que ven los residentes.
 *
 * @property int $id
 * @property int $condominio_id
 * @property string $banco
 * @property string $tipo corriente|ahorros
 * @property string $numero
 * @property string $titular
 * @property bool $es_principal
 * @property bool $activa
 */
class CuentaBancaria extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const TIPOS = ['corriente', 'ahorros'];

    protected $table = 'cuentas_bancarias';

    /** @var list<string> */
    protected array $auditExclude = ['numero'];

    protected $fillable = ['banco', 'tipo', 'numero', 'titular', 'es_principal', 'activa'];

    protected function casts(): array
    {
        return ['numero' => 'encrypted', 'es_principal' => 'boolean', 'activa' => 'boolean'];
    }
}
