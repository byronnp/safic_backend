<?php

namespace App\Core\Privacy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Aceptación del aviso de privacidad (LOPDP). Solo se insertan filas.
 *
 * @property int $id
 * @property int $user_id
 * @property string $version
 * @property string $origen
 * @property Carbon $aceptado_en
 * @property string|null $ip
 * @property string|null $user_agent
 */
class AceptacionPrivacidad extends Model
{
    public const ORIGEN_INVITACION = 'invitacion';

    protected $table = 'aceptaciones_privacidad';

    protected $fillable = ['user_id', 'version', 'origen', 'aceptado_en', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['aceptado_en' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function registrar(User $user, string $origen, ?string $ip, ?string $userAgent): self
    {
        return self::create([
            'user_id' => $user->id,
            'version' => (string) config('safic.aviso_privacidad_version'),
            'origen' => $origen,
            'aceptado_en' => now(),
            'ip' => $ip,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ]);
    }
}
