<?php

namespace App\Modules\Comunicacion\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Comunicacion\Models\Anuncio;
use App\Modules\Comunicacion\Models\AnuncioDestinatario;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del residente: marcar un aviso como leído o confirmar su lectura, para todas sus unidades a las que llegó. Es
 * idempotente: la primera vez queda registrada la fecha y quién fue. Un aviso que no llegó a sus unidades no existe para él.
 */
final class MarcarAvisoAction
{
    public function __construct(private readonly Calendario $calendario, private readonly UnidadesDeCuentaAction $unidades) {}

    public function leer(User $user, int $anuncioId): void
    {
        $this->marcar($user, $anuncioId, false);
    }

    public function confirmar(User $user, int $anuncioId): void
    {
        $this->marcar($user, $anuncioId, true);
    }

    private function marcar(User $user, int $anuncioId, bool $confirmar): void
    {
        $ids = array_column($this->unidades->execute($user->id), 'unidad_id');

        DB::transaction(function () use ($user, $anuncioId, $confirmar, $ids): void {
            $filas = AnuncioDestinatario::query()->where('anuncio_id', $anuncioId)->whereIn('unidad_id', $ids)->lockForUpdate()->get();
            if ($filas->isEmpty()) {
                abort(404);
            }
            $anuncio = Anuncio::query()->findOrFail($anuncioId);
            if ($confirmar) {
                if (! $anuncio->requiere_confirmacion) {
                    throw new ApiException('NO_PIDE_CONFIRMACION', 'Este anuncio no pide confirmación de lectura.', 409);
                }
                if ($anuncio->expira_en !== null && $anuncio->expira_en->toDateString() < $this->calendario->hoy()) {
                    throw new ApiException('ANUNCIO_EXPIRADO', 'Este anuncio ya expiró.', 409);
                }
            }

            $ahora = now();
            foreach ($filas as $f) {
                if ($f->leido_en === null) {
                    $f->leido_en = $ahora;
                    $f->leido_por = $user->id;
                }
                if ($confirmar && $f->confirmado_en === null) {
                    $f->confirmado_en = $ahora;
                    $f->confirmado_por = $user->id;
                }
                $f->save();
            }
        });
    }
}
