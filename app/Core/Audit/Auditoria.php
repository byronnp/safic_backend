<?php

namespace App\Core\Audit;

use App\Core\Tenancy\BelongsToCondominio;
use OwenIt\Auditing\Models\Audit;

/**
 * Registro de auditoría de un cambio. Pertenece a un condominio (RLS) y no se
 * modifica ni se borra desde la aplicación.
 *
 * @property int $condominio_id
 */
class Auditoria extends Audit
{
    use BelongsToCondominio;

    protected $table = 'audits';

    protected $guarded = [];
}
