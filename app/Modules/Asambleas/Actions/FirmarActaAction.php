<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Rol;
use App\Core\Realtime\EnVivo;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Asambleas\Models\Acta;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Comunicacion\Actions\PublicarAnuncioAction;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: firmar el acta (Art. 43). Firman el presidente y el secretario; si el presidente está ausente, firma el vicepresidente y el
 * acta dice «en subrogación». Quien firma lo hace con su cuenta: queda su nombre, la fecha y la hora. Con las dos firmas el acta queda
 * firmada, la asamblea pasa a `acta_firmada` y se publica a los propietarios con un anuncio. (La firma electrónica certificada queda fuera
 * de esta entrega.)
 */
final class FirmarActaAction
{
    public function __construct(private readonly PublicarAnuncioAction $anuncios, private readonly TenantContext $tenant) {}

    public function execute(User $user, int $actaId, bool $presidenteAusente): Acta
    {
        [$acta, $recienFirmada] = DB::transaction(function () use ($user, $actaId, $presidenteAusente): array {
            $acta = Acta::query()->lockForUpdate()->findOrFail($actaId);
            $asamblea = Asamblea::query()->lockForUpdate()->findOrFail($acta->asamblea_id);
            if ($acta->estaFirmada()) {
                throw new ApiException('ACTA_YA_FIRMADA', 'El acta ya tiene las dos firmas.', 409);
            }

            $esSecretario = $user->hasRole(Rol::Secretario->value);
            $esPresidente = $user->hasRole(Rol::Presidente->value);
            $esVice = $user->hasRole(Rol::Vicepresidente->value);
            if (! $esSecretario && ! $esPresidente && ! $esVice) {
                throw new ApiException('NO_ES_FIRMANTE', 'El acta la firman el presidente (o su subrogante) y el secretario.', 403);
            }

            if ($esSecretario) {
                if ($acta->firmada_secretario_en !== null) {
                    throw new ApiException('YA_FIRMO', 'El secretario ya firmó el acta.', 409);
                }
                $acta->forceFill(['secretario_user_id' => $user->id, 'secretario_nombre' => $user->name, 'firmada_secretario_en' => now()]);
            } else {
                if ($acta->firmada_presidente_en !== null) {
                    throw new ApiException('YA_FIRMO', 'El presidente ya firmó el acta.', 409);
                }
                $subroga = ! $esPresidente;
                if ($subroga && ! $asamblea->en_subrogacion && ! $presidenteAusente) {
                    throw new ApiException('PRESIDENTE_AUSENTE_REQUERIDO', 'Solo el vicepresidente firma si el presidente está ausente: confírmalo para firmar en subrogación.', 422);
                }
                $acta->forceFill([
                    'presidente_user_id' => $user->id, 'presidente_nombre' => $user->name, 'firmada_presidente_en' => now(), 'presidente_en_subrogacion' => $subroga,
                ]);
            }

            $completa = $acta->firmada_presidente_en !== null && $acta->firmada_secretario_en !== null;
            if ($completa) {
                $acta->forceFill(['estado' => Acta::FIRMADA, 'publicada_en' => now()]);
                $asamblea->forceFill(['estado' => Asamblea::ACTA_FIRMADA])->save();
            }
            $acta->save();

            return [$acta, $completa];
        });

        if ($recienFirmada) {
            $this->publicar($user, $acta);
        }
        EnVivo::aAsambleas($this->tenant->require(), 'asambleas', $acta->asamblea_id);

        return $acta;
    }

    /** Avisa a los propietarios que el acta y las resoluciones ya están disponibles. Si no hay a quién avisar, el acta igual queda publicada. */
    private function publicar(User $user, Acta $acta): void
    {
        $titulo = (string) ($acta->contenido['asamblea']['titulo'] ?? 'asamblea');
        try {
            $this->anuncios->execute($user, [
                'titulo' => mb_substr("Acta N.º {$acta->numero} disponible: {$titulo}", 0, 120),
                'mensaje' => "El acta de la asamblea «{$titulo}» ya está firmada y publicada, junto con sus resoluciones. Puedes descargarla en Asambleas.",
                'destino_tipo' => 'todos', 'fijar' => false, 'confirmacion' => false,
            ], null);
        } catch (ApiException) {
            // Sin destinatarios: no es motivo para deshacer las firmas
        }
    }
}
