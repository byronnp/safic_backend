<?php

namespace App\Modules\Asambleas\Actions;

use App\Modules\Asambleas\Models\ConfiguracionAsambleas;

/**
 * Caso de uso de la administración: ajustar las reglas de asamblea cuando el reglamento interno del condominio lo indica (asambleas
 * virtuales, mora y quórum, voto secreto y el porcentaje para reformar el reglamento).
 */
final class GuardarConfiguracionAction
{
    public function __construct(private readonly ObtenerConfiguracionAction $obtener) {}

    /**
     * @param  array{permite_virtual: bool, mora_cuenta_para_quorum: bool, voto_secreto: bool, porcentaje_reforma: float|string|null}  $d
     * @return array{permite_virtual: bool, mora_cuenta_para_quorum: bool, voto_secreto: bool, porcentaje_reforma: string|null}
     */
    public function execute(array $d): array
    {
        $c = ConfiguracionAsambleas::query()->first() ?? new ConfiguracionAsambleas;
        $c->fill([
            'permite_virtual' => $d['permite_virtual'], 'mora_cuenta_para_quorum' => $d['mora_cuenta_para_quorum'], 'voto_secreto' => $d['voto_secreto'],
            'porcentaje_reforma' => $d['porcentaje_reforma'] === null ? null : number_format((float) $d['porcentaje_reforma'], 2, '.', ''),
        ])->save();

        return $this->obtener->execute();
    }
}
