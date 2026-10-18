<?php

namespace App\Modules\Comunicacion\Actions;

use App\Models\User;
use App\Modules\Comunicacion\Models\Incidencia;
use App\Modules\Comunicacion\Models\IncidenciaComentario;
use App\Modules\Comunicacion\Services\AvisosDeIncidencia;
use App\Modules\Comunicacion\Services\TransicionesDeIncidencia;
use Illuminate\Support\Facades\DB;

/**
 * Casos de uso de la administración sobre una incidencia: comentar al residente o dejar una nota interna, cambiar su estado y ajustar
 * su prioridad o categoría. Lo que ve el residente (comentario público, resuelta) le llega también por correo.
 */
final class GestionarIncidenciaAction
{
    public function __construct(private readonly TransicionesDeIncidencia $transiciones, private readonly AvisosDeIncidencia $avisos) {}

    public function comentar(User $admin, int $id, string $texto, bool $interna): Incidencia
    {
        $i = DB::transaction(function () use ($admin, $id, $texto, $interna): Incidencia {
            $i = Incidencia::query()->lockForUpdate()->findOrFail($id);
            IncidenciaComentario::create(['incidencia_id' => $i->id, 'user_id' => $admin->id, 'de_administracion' => true, 'interna' => $interna, 'texto' => $texto]);
            // Una nota interna no es una respuesta al residente
            if (! $interna) {
                $this->transiciones->marcarRespuesta($i);
            }

            return $i;
        });

        if (! $interna) {
            $this->avisos->avisar($i, 'La administración respondió tu incidencia', $texto);
        }

        return $i;
    }

    public function cambiarEstado(User $admin, int $id, string $estado): Incidencia
    {
        $i = DB::transaction(function () use ($id, $estado): Incidencia {
            $i = Incidencia::query()->lockForUpdate()->findOrFail($id);
            $this->transiciones->cambiar($i, $estado);
            $this->transiciones->marcarRespuesta($i);

            return $i;
        });

        $this->avisos->avisar($i, $estado === Incidencia::RESUELTO ? 'Tu incidencia fue resuelta' : 'Tu incidencia cambió de estado',
            $estado === Incidencia::RESUELTO
                ? 'La administración la marcó como resuelta. ¿Se solucionó? Si no respondes en '.Incidencia::DIAS_PARA_CERRAR.' días, se cierra sola.'
                : 'Ahora está en «'.$this->transiciones->etiqueta($estado).'».');

        return $i;
    }

    public function ajustar(int $id, ?string $prioridad, ?string $categoria): Incidencia
    {
        return DB::transaction(function () use ($id, $prioridad, $categoria): Incidencia {
            $i = Incidencia::query()->lockForUpdate()->findOrFail($id);
            $i->fill(array_filter(['prioridad' => $prioridad, 'categoria' => $categoria], fn ($v) => $v !== null))->save();

            return $i;
        });
    }
}
