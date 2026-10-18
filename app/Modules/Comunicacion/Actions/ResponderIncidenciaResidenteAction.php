<?php

namespace App\Modules\Comunicacion\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Comunicacion\Models\Incidencia;
use App\Modules\Comunicacion\Models\IncidenciaComentario;
use App\Modules\Comunicacion\Services\TransicionesDeIncidencia;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use Illuminate\Support\Facades\DB;

/**
 * Casos de uso del residente sobre una incidencia suya (o de su unidad): comentar, reabrirla si no se solucionó, o confirmar que sí.
 * Una incidencia de otra persona o unidad se responde como si no existiera.
 */
final class ResponderIncidenciaResidenteAction
{
    public function __construct(private readonly UnidadesDeCuentaAction $unidades, private readonly TransicionesDeIncidencia $transiciones) {}

    public function comentar(User $user, int $id, string $texto): void
    {
        DB::transaction(function () use ($user, $id, $texto): void {
            $i = $this->propia($user, $id);
            if ($i->estado === Incidencia::CERRADO) {
                throw new ApiException('INCIDENCIA_CERRADA', 'Esta incidencia está cerrada. Reporta una nueva si el problema sigue.', 409);
            }
            IncidenciaComentario::create(['incidencia_id' => $i->id, 'user_id' => $user->id, 'de_administracion' => false, 'interna' => false, 'texto' => $texto]);
        });
    }

    /** «No se solucionó»: vuelve a En proceso, con un comentario opcional. */
    public function reabrir(User $user, int $id, ?string $texto): void
    {
        DB::transaction(function () use ($user, $id, $texto): void {
            $i = $this->propia($user, $id);
            if ($i->estado !== Incidencia::RESUELTO) {
                throw new ApiException('INCIDENCIA_NO_RESUELTA', 'Solo se puede reabrir una incidencia resuelta.', 409);
            }
            $this->transiciones->cambiar($i, Incidencia::EN_PROCESO);
            if ($texto !== null && trim($texto) !== '') {
                IncidenciaComentario::create(['incidencia_id' => $i->id, 'user_id' => $user->id, 'de_administracion' => false, 'interna' => false, 'texto' => trim($texto)]);
            }
        });
    }

    /** «Sí, se solucionó»: se cierra. */
    public function confirmar(User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id): void {
            $i = $this->propia($user, $id);
            if ($i->estado !== Incidencia::RESUELTO) {
                throw new ApiException('INCIDENCIA_NO_RESUELTA', 'Solo se confirma una incidencia resuelta.', 409);
            }
            $this->transiciones->cambiar($i, Incidencia::CERRADO, 'residente');
        });
    }

    private function propia(User $user, int $id): Incidencia
    {
        $ids = array_column($this->unidades->execute($user->id), 'unidad_id');

        return Incidencia::query()->where(fn ($q) => $q->where('reportado_por', $user->id)->orWhereIn('unidad_id', $ids))->lockForUpdate()->findOrFail($id);
    }
}
