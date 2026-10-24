<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use Carbon\CarbonImmutable;

/**
 * Caso de uso de la administración o la directiva: crear o editar el borrador de una asamblea. Solo un borrador se edita: una
 * asamblea ya convocada se notificó a los propietarios. Las asambleas virtuales o mixtas solo se permiten si el reglamento interno
 * del condominio lo permite (reglas de asamblea); la ley habla de convocatoria escrita y firmas autógrafas y no regula las telemáticas.
 */
final class GuardarAsambleaAction
{
    public function __construct(private readonly Calendario $calendario, private readonly ObtenerConfiguracionAction $configuracion) {}

    /** @param array{titulo: string, tipo: string, modalidad: string, fecha_hora: string, lugar?: string|null, enlace_video?: string|null} $d */
    public function crear(User $user, array $d): Asamblea
    {
        $valores = $this->valores($d);

        return Asamblea::create($valores + ['estado' => Asamblea::BORRADOR, 'creada_por' => $user->id]);
    }

    /** @param array{titulo: string, tipo: string, modalidad: string, fecha_hora: string, lugar?: string|null, enlace_video?: string|null} $d */
    public function actualizar(int $id, array $d): Asamblea
    {
        $asamblea = Asamblea::query()->lockForUpdate()->findOrFail($id);
        if (! $asamblea->esBorrador()) {
            throw new ApiException('ASAMBLEA_NO_EDITABLE', 'Solo se edita una asamblea en borrador: esta ya se convocó.', 409);
        }
        $asamblea->forceFill($this->valores($d))->save();

        return $asamblea;
    }

    /**
     * @param  array{titulo: string, tipo: string, modalidad: string, fecha_hora: string, lugar?: string|null, enlace_video?: string|null}  $d
     * @return array<string, mixed>
     */
    private function valores(array $d): array
    {
        $modalidad = $d['modalidad'];
        $lugar = isset($d['lugar']) ? trim($d['lugar']) : '';
        $enlace = isset($d['enlace_video']) ? trim($d['enlace_video']) : '';

        if ($modalidad !== 'presencial' && ! $this->configuracion->modelo()->permite_virtual) {
            throw new ApiException('MODALIDAD_NO_PERMITIDA', 'El reglamento interno de este condominio no permite asambleas virtuales o mixtas. Actívalo en las reglas de asamblea solo si sí lo permite.', 422);
        }
        if ($modalidad !== 'virtual' && $lugar === '') {
            throw new ApiException('LUGAR_REQUERIDO', 'Indica el lugar de la asamblea.', 422);
        }
        if ($modalidad !== 'presencial' && $enlace === '') {
            throw new ApiException('ENLACE_REQUERIDO', 'Indica el enlace de la videollamada (Zoom, Meet o Teams).', 422);
        }

        $fecha = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $d['fecha_hora'], $this->calendario->zonaHoraria());
        if ($fecha === null || $fecha->isPast()) {
            throw new ApiException('FECHA_PASADA', 'La fecha y hora de la asamblea debe ser futura.', 422);
        }

        return [
            'titulo' => trim($d['titulo']), 'tipo' => $d['tipo'], 'modalidad' => $modalidad,
            'fecha_hora' => $fecha->utc(), 'lugar' => $lugar === '' ? null : $lugar, 'enlace_video' => $modalidad === 'presencial' || $enlace === '' ? null : $enlace,
        ];
    }
}
