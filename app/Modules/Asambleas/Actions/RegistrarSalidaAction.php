<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Permiso;
use App\Core\Realtime\EnVivo;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaAsistencia;
use App\Modules\Unidades\Models\Persona;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: marcar la salida de una unidad (baja el quórum). En mesa, con `unidad_id`; por la app, sin él: se sale de todas las
 * unidades que esa cuenta marcó. Quien vuelve a entrar queda en una fila nueva.
 */
final class RegistrarSalidaAction
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @param array{unidad_id?: int|null} $d */
    public function execute(User $user, int $asambleaId, array $d): int
    {
        $n = DB::transaction(function () use ($user, $asambleaId, $d): int {
            $a = Asamblea::query()->lockForUpdate()->findOrFail($asambleaId);
            if (! $a->registroAbierto()) {
                throw new ApiException('REGISTRO_CERRADO', 'El registro de asistencia no está abierto.', 409);
            }

            $q = AsambleaAsistencia::query()->whereNull('salida_en')->where('asamblea_id', $asambleaId);
            if (isset($d['unidad_id'])) {
                if (! $user->can(Permiso::AsambleasRegistrar->value)) {
                    throw new ApiException('SIN_PERMISO', 'Solo quien registra la asistencia puede marcar la salida de una unidad.', 403);
                }
                $q->where('unidad_id', (int) $d['unidad_id']);
            } else {
                $personas = Persona::query()->where('user_id', $user->id)->pluck('id')->all();
                $q->where('canal', 'app')->where('registrada_por', $user->id)->whereIn('asistente_persona_id', $personas);
            }

            $n = $q->update(['salida_en' => now()]);
            if ($n === 0) {
                throw new ApiException('NO_REGISTRADA', 'No hay una asistencia vigente para marcar su salida.', 409);
            }

            return $n;
        });
        EnVivo::aAsambleas($this->tenant->require(), 'asistencia', $asambleaId);

        return $n;
    }
}
