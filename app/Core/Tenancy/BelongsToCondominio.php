<?php

namespace App\Core\Tenancy;

use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Segunda barrera: todo modelo de una tabla con condominio_id usa este trait.
 *
 * - Filtra automáticamente por el condominio activo (CondominioScope).
 * - Al crear, completa condominio_id con el condominio activo.
 * - Sin condominio activo no devuelve filas (falla cerrado).
 *
 * Solo el código de plataforma puede usar withoutGlobalScope(CondominioScope::class)
 * y debe justificarlo; aun así, RLS en PostgreSQL sigue filtrando.
 *
 * @mixin Model
 */
trait BelongsToCondominio
{
    public static function bootBelongsToCondominio(): void
    {
        static::addGlobalScope(new CondominioScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('condominio_id') === null) {
                $model->setAttribute('condominio_id', app(TenantContext::class)->require());
            }
        });
    }

    /**
     * @return BelongsTo<Condominio, $this>
     */
    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }
}
