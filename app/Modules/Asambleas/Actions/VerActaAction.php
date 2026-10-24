<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Permiso;
use App\Core\Permissions\Rol;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Asambleas\Models\Acta;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\Resolucion;
use Carbon\CarbonImmutable;

/**
 * El estado del acta de una asamblea: su número, quién firmó y si quien pregunta puede firmar. Los propietarios solo ven un acta ya
 * firmada y publicada; la mesa, desde que se cierra la asamblea.
 */
final class VerActaAction
{
    public function __construct(private readonly Calendario $calendario) {}

    /** ¿Quien consulta es solo un propietario (sin función en la mesa)? Entonces solo ve el acta publicada. */
    public function soloPropietario(User $user): bool
    {
        foreach ([Permiso::AsambleasPreparar, Permiso::AsambleasRegistrar, Permiso::AsambleasInstalar, Permiso::AsambleasFirmar] as $p) {
            if ($user->can($p->value)) {
                return false;
            }
        }

        return true;
    }

    public function delAsamblea(User $user, int $asambleaId): Acta
    {
        Asamblea::query()->findOrFail($asambleaId);
        $acta = Acta::query()->where('asamblea_id', $asambleaId)->first();
        if ($acta === null) {
            throw new ApiException('ACTA_NO_GENERADA', 'El acta se genera al cerrar la asamblea.', 409);
        }
        if ($this->soloPropietario($user) && ! $acta->estaFirmada()) {
            throw new ApiException('ACTA_NO_PUBLICADA', 'El acta se publica cuando la firmen el presidente y el secretario.', 409);
        }

        return $acta;
    }

    /** @return array<string, mixed> */
    public function execute(User $user, int $asambleaId): array
    {
        $acta = $this->delAsamblea($user, $asambleaId);
        $zona = $this->calendario->zonaHoraria();
        $local = fn (?\DateTimeInterface $d): ?string => $d === null ? null : CarbonImmutable::instance($d)->setTimezone($zona)->toIso8601String();

        // Con qué cargo firmaría quien consulta: el secretario como secretario; presidente y vicepresidente como presidencia
        $comoSecretario = $user->hasRole(Rol::Secretario->value);
        $comoPresidencia = $user->hasRole(Rol::Presidente->value) || $user->hasRole(Rol::Vicepresidente->value);
        $firma = $comoSecretario ? 'secretario' : ($comoPresidencia ? 'presidente' : null);
        $yaFirmo = ($firma === 'secretario' && $acta->firmada_secretario_en !== null) || ($firma === 'presidente' && $acta->firmada_presidente_en !== null);

        return [
            'id' => $acta->id, 'asamblea_id' => $acta->asamblea_id, 'numero' => $acta->numero, 'estado' => $acta->estado,
            'hash' => $acta->hash, 'publicada_en' => $local($acta->publicada_en),
            'presidente' => ['nombre' => $acta->presidente_nombre, 'firmada_en' => $local($acta->firmada_presidente_en), 'en_subrogacion' => $acta->presidente_en_subrogacion],
            'secretario' => ['nombre' => $acta->secretario_nombre, 'firmada_en' => $local($acta->firmada_secretario_en)],
            'resoluciones' => Resolucion::query()->where('acta_id', $acta->id)->count(),
            // Quién firma el acta: no el administrador (los firmantes son la directiva)
            'tu_firma' => $firma,
            'puede_firmar' => ! $acta->estaFirmada() && $firma !== null && ! $yaFirmo && $user->can(Permiso::AsambleasFirmar->value),
            'es_vicepresidente' => $user->hasRole(Rol::Vicepresidente->value) && ! $user->hasRole(Rol::Presidente->value),
        ];
    }
}
