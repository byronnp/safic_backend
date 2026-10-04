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
        return $this->configuracion()['metodo'];
    }

    /**
     * Método y cuota general (solo con método "general"; null si no hay configuración).
     *
     * @return array{metodo: string, cuota_general: string|null}
     */
    public function configuracion(): array
    {
        $this->tenant->require();
        $configuracion = ConfiguracionCobro::query()->first();

        return [
            'metodo' => $configuracion->metodo ?? ConfiguracionCobro::METODO_GENERAL,
            'cuota_general' => $configuracion?->cuota_general,
        ];
    }
}
