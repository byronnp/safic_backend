<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Asambleas\Models\Resolucion;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** El acta en PDF, hecha con lo que se congeló al cerrar y las firmas de hoy. Un propietario solo la baja ya firmada y publicada. */
final class PdfDeActaAction
{
    public function __construct(private readonly VerActaAction $actas, private readonly Calendario $calendario) {}

    /** @return array{contenido: string, nombre: string} */
    public function execute(User $user, int $asambleaId): array
    {
        $acta = $this->actas->delAsamblea($user, $asambleaId);
        $zona = $this->calendario->zonaHoraria();
        $fecha = fn (?\DateTimeInterface $d): ?string => $d === null ? null : CarbonImmutable::instance($d)->setTimezone($zona)->format('d/m/Y H:i');

        $pdf = Pdf::loadView('asambleas.acta', [
            'acta' => ['numero' => $acta->numero, 'hash' => $acta->hash, 'firmada' => $acta->estaFirmada()],
            'c' => $acta->contenido,
            'resoluciones' => Resolucion::query()->where('acta_id', $acta->id)->orderBy('id')->pluck('texto')->all(),
            'firmas' => [
                'presidente' => ['nombre' => $acta->presidente_nombre, 'cuando' => $fecha($acta->firmada_presidente_en), 'subrogacion' => $acta->presidente_en_subrogacion],
                'secretario' => ['nombre' => $acta->secretario_nombre, 'cuando' => $fecha($acta->firmada_secretario_en)],
            ],
            'emitida' => now($zona)->format('d/m/Y H:i'),
        ]);

        return ['contenido' => $pdf->output(), 'nombre' => 'acta-'.str_pad((string) $acta->numero, 3, '0', STR_PAD_LEFT).'-'.Str::slug((string) ($acta->contenido['asamblea']['titulo'] ?? 'asamblea')).'.pdf'];
    }
}
