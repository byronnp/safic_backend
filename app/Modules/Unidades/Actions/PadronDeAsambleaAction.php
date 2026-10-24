<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Unidad;

/**
 * Consulta pública del módulo Unidades para Asambleas: el padrón de copropietarios, es decir, las unidades que hoy tienen
 * propietario y alícuota (una unidad sin alícuota, como un parqueadero suelto, no pesa en el voto). Cada unidad trae a sus
 * propietarios vigentes: son quienes votan (Art. 41); el inquilino solo con poder.
 */
final class PadronDeAsambleaAction
{
    /**
     * @return list<array{unidad_id: int, codigo: string, alicuota: string, propietarios: list<array{persona_id: int, nombre: string}>}>
     */
    public function execute(): array
    {
        $unidades = Unidad::query()
            ->where('alicuota', '>', 0)
            ->whereHas('ocupantes', fn ($q) => Ocupante::filtrarVigentes($q->where('relacion', 'propietario')))
            ->with(['ocupantes' => fn ($relacion) => Ocupante::filtrarVigentes($relacion->getQuery()->where('relacion', 'propietario'))->orderByDesc('es_principal')->orderBy('id')->with('persona')])
            ->orderBy('codigo')
            ->get();

        $padron = [];
        foreach ($unidades as $u) {
            $propietarios = [];
            foreach ($u->ocupantes as $o) {
                // Una persona dada de baja ya no es propietaria vigente a efectos del voto
                if ($o->persona->trashed() || isset($propietarios[$o->persona_id])) {
                    continue;
                }
                $propietarios[$o->persona_id] = ['persona_id' => $o->persona_id, 'nombre' => $o->persona->nombreCompleto()];
            }
            if ($propietarios === []) {
                continue;
            }
            $padron[] = ['unidad_id' => $u->id, 'codigo' => $u->codigo, 'alicuota' => (string) $u->alicuota, 'propietarios' => array_values($propietarios)];
        }

        return $padron;
    }
}
