<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Storage\ArchivosCondominio;
use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Reservas\Models\ReservaDanoFoto;
use App\Modules\Reservas\Services\GarantiaDeReserva;
use App\Modules\Unidades\Models\Unidad;

/**
 * Consulta de la administración: los daños que el guardia reportó al recibir un área, con sus fotos (enlaces temporales) y la
 * garantía de la reserva, para decidir qué hacer con ella.
 */
final class VerDanosDeReservaAction
{
    public function __construct(private readonly ArchivosCondominio $archivos, private readonly Calendario $calendario, private readonly GarantiaDeReserva $garantias) {}

    /** @return array<string, mixed> */
    public function execute(int $reservaId): array
    {
        $r = Reserva::query()->findOrFail($reservaId);
        if ($r->danos === null) {
            throw new ApiException('RESERVA_SIN_DANOS', 'Esta reserva no tiene daños reportados.', 404);
        }

        $g = $this->garantias->de($r, $this->garantias->pagada($r));
        $guardia = $r->entrega_por === null ? null : User::query()->whereKey($r->entrega_por)->value('name');

        return [
            'reserva_id' => $r->id,
            'area' => (string) (CondominioAmenidad::query()->whereKey($r->amenidad_id)->value('nombre') ?? 'Área común'),
            'unidad' => (string) (Unidad::query()->whereKey($r->unidad_id)->value('codigo') ?? ''),
            'fecha' => $r->fecha->toDateString(), 'inicio' => substr($r->inicio, 0, 5), 'fin' => substr($r->fin, 0, 5),
            'garantia' => (string) $r->garantia,
            'danos' => $r->danos,
            'recibida_en' => $r->entrega_en?->setTimezone($this->calendario->zonaHoraria())->toIso8601String(),
            'recibida_por' => $guardia,
            'resolucion' => $r->danos_decision === null ? null : [
                'decision' => $r->danos_decision, 'monto' => $r->danos_monto === null ? null : (string) $r->danos_monto, 'nota' => $r->danos_nota,
                'garantia_estado' => $g['estado'] ?? null,
                'por_devolver' => ($g['estado'] ?? null) === GarantiaDeReserva::POR_DEVOLVER ? Dinero::texto($g['monto']) : null,
                'cobrada_en_cuota' => $r->danos_cuota_id !== null,
                'resuelta_en' => $r->danos_resuelta_en?->setTimezone($this->calendario->zonaHoraria())->toIso8601String(),
            ],
            'fotos' => ReservaDanoFoto::query()->where('reserva_id', $r->id)->orderBy('orden')->get()
                ->map(fn (ReservaDanoFoto $f): array => ['id' => $f->id, 'url' => $this->archivos->existe($f->ruta) ? $this->archivos->urlTemporal($f->ruta) : null])->all(),
        ];
    }
}
