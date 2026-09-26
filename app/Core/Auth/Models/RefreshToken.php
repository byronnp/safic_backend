<?php

namespace App\Core\Auth\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Refresh token rotativo. Solo se guarda el hash SHA-256; el valor en claro
 * existe únicamente en la cookie HttpOnly (web) o en el almacenamiento seguro (móvil).
 *
 * @property int $id
 * @property int $user_id
 * @property string $familia
 * @property string $token_hash
 * @property Carbon $expira_en
 * @property Carbon|null $usado_en
 * @property Carbon|null $revocado_en
 */
class RefreshToken extends Model
{
    protected $table = 'refresh_tokens';

    protected $fillable = ['user_id', 'familia', 'token_hash', 'expira_en', 'usado_en', 'revocado_en', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return [
            'expira_en' => 'datetime',
            'usado_en' => 'datetime',
            'revocado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
