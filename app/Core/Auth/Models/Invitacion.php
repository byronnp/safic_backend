<?php

namespace App\Core\Auth\Models;

use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invitación para crear la contraseña y entrar por primera vez. Del token solo se
 * guarda el hash (sha256). Tabla de plataforma: se consulta sin sesión.
 *
 * @property int $id
 * @property int $user_id
 * @property int $condominio_id
 * @property int|null $creada_por
 * @property string $token_hash
 * @property \Illuminate\Support\Carbon $expira_en
 * @property \Illuminate\Support\Carbon|null $aceptada_en
 * @property-read User $user
 * @property-read Condominio $condominio
 */
class Invitacion extends Model
{
    protected $table = 'invitaciones';

    protected $fillable = ['user_id', 'condominio_id', 'creada_por', 'token_hash', 'expira_en', 'aceptada_en'];

    protected function casts(): array
    {
        return [
            'expira_en' => 'datetime',
            'aceptada_en' => 'datetime',
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

    public function vigente(): bool
    {
        return $this->aceptada_en === null && $this->expira_en->isFuture();
    }
}
