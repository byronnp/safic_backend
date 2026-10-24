<?php

namespace App\Modules\Asambleas\Actions;

use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Comunicacion\Actions\PublicarAnuncioAction;
use App\Modules\Comunicacion\Models\Anuncio;
use App\Modules\Comunicacion\Models\AnuncioDestinatario;
use App\Modules\Unidades\Actions\OcupantesVigentesAction;

/**
 * Recordatorios por correo de una asamblea convocada: 3 días antes a las unidades que aún no confirmaron, y 2 horas antes a todas.
 * Cada recordatorio se manda una sola vez (queda registrado en la asamblea). Si ya faltan menos de 2 horas, solo va el de 2 horas.
 */
final class RecordarAsambleasAction
{
    public function __construct(private readonly OcupantesVigentesAction $ocupantes) {}

    /** @return int Correos de recordatorio enviados a unidades (suma de ambos) */
    public function execute(): int
    {
        $total = 0;
        $asambleas = Asamblea::query()->where('estado', Asamblea::CONVOCADA)->whereNotNull('anuncio_id')->where('fecha_hora', '>', now())->get();

        foreach ($asambleas as $a) {
            $anuncio = Anuncio::query()->find($a->anuncio_id);
            if ($anuncio === null) {
                continue;
            }
            $faltan = now()->diffInMinutes($a->fecha_hora, false);

            if ($a->recordada_2h_en === null && $faltan <= 120) {
                $total += $this->avisar($anuncio, soloPendientes: false);
                $a->forceFill(['recordada_2h_en' => now(), 'recordada_3d_en' => $a->recordada_3d_en ?? now()])->save();
            } elseif ($a->recordada_3d_en === null && $faltan <= 3 * 24 * 60) {
                $total += $this->avisar($anuncio, soloPendientes: true);
                $a->forceFill(['recordada_3d_en' => now()])->save();
            }
        }

        return $total;
    }

    private function avisar(Anuncio $anuncio, bool $soloPendientes): int
    {
        $q = AnuncioDestinatario::query()->where('anuncio_id', $anuncio->id);
        if ($soloPendientes) {
            $q->whereNull('confirmado_en');
        }
        $unidades = $q->pluck('unidad_id');
        foreach ($unidades as $unidadId) {
            $personas = array_map(fn (array $o): array => ['unidad_id' => (int) $unidadId, 'nombre' => $o['nombre'], 'email' => $o['email'], 'tiene_cuenta' => true], $this->ocupantes->execute((int) $unidadId));
            PublicarAnuncioAction::notificar($anuncio, $personas, true);
        }

        return $unidades->count();
    }
}
