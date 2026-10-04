<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Finanzas\Models\ConfiguracionCobro;

/**
 * Consulta pública del módulo Finanzas: método de cobro del condominio activo.
 * La usa Unidades para saber qué montos pide cada unidad. Sin configuración
 * (condominios anteriores al asistente) se cobra el valor general.
 */
final class ObtenerMetodoCobroAction
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function execute(): string
    {
        $this->tenant->require();

        return ConfiguracionCobro::query()->value('metodo') ?? ConfiguracionCobro::METODO_GENERAL;
    }
}
