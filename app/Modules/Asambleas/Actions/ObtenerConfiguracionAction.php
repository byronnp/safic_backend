<?php

namespace App\Modules\Asambleas\Actions;

use App\Modules\Asambleas\Models\ConfiguracionAsambleas;

/** Las reglas de asamblea del condominio; con los valores por defecto de la ley si aún no guardó las suyas. */
final class ObtenerConfiguracionAction
{
    public function modelo(): ConfiguracionAsambleas
    {
        return ConfiguracionAsambleas::query()->first() ?? ConfiguracionAsambleas::porDefecto();
    }

    /** @return array{permite_virtual: bool, mora_cuenta_para_quorum: bool, voto_secreto: bool, porcentaje_reforma: string|null} */
    public function execute(): array
    {
        $c = $this->modelo();

        return [
            'permite_virtual' => $c->permite_virtual, 'mora_cuenta_para_quorum' => $c->mora_cuenta_para_quorum,
            'voto_secreto' => $c->voto_secreto, 'porcentaje_reforma' => $c->porcentaje_reforma === null ? null : (string) $c->porcentaje_reforma,
        ];
    }
}
