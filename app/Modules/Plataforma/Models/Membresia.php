<?php

namespace App\Modules\Plataforma\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Pertenencia de un usuario a un condominio. Un usuario tiene como máximo un
 * condominio principal (índice único parcial en la migración).
 *
 * @property int $user_id
 * @property int $condominio_id
 * @property bool $es_principal
 * @property bool $activo
 * @property Carbon|null $acceso_hasta
 * @property-read User $user
 */
class Membresia extends Pivot
{
    protected $table = 'condominio_user';

    public $incrementing = true;

    protected $fillable = ['user_id', 'condominio_id', 'es_principal', 'activo', 'acceso_hasta'];

    protected function casts(): array
    {
        return [
            'es_principal' => 'boolean',
            'activo' => 'boolean',
            'acceso_hasta' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Condominio, $this>
     */
    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }
}
