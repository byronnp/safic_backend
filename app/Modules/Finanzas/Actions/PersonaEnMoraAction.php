<?php

namespace App\Modules\Finanzas\Actions;

use App\Modules\Finanzas\Services\MorosidadService;

/**
 * Consulta pública del módulo Finanzas para la directiva (Decreto 462): ¿esta persona, propietaria de
 * alguna unidad, está en mora? Quien lo está no puede ocupar un cargo.
 */
final class PersonaEnMoraAction
{
    public function __construct(private readonly MorosidadService $morosidad) {}

    public function execute(int $personaId): bool
    {
        return $this->morosidad->personaEnMora($personaId);
    }
}
