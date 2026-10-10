<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Modules\Plataforma\Models\Condominio;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;

/**
 * Estado de cuenta de una unidad en PDF (los mismos datos y el mismo saldo corriente de la pantalla).
 */
final class GenerarEstadoCuentaPdfAction
{
    public function __construct(
        private readonly ObtenerEstadoCuentaAction $estado,
        private readonly TenantContext $tenant,
        private readonly Calendario $calendario,
    ) {}

    /**
     * @return array{contenido: string, nombre: string}
     */
    public function execute(int $unidadId, ?string $desde, ?string $hasta): array
    {
        $datos = $this->estado->execute($unidadId, $desde, $hasta);
        $condominio = Condominio::query()->findOrFail($this->tenant->require());

        $datos['movimientos'] = array_map(
            fn (array $m): array => $m + ['fecha_texto' => CarbonImmutable::parse($m['fecha'])->format('d/m/Y')],
            $datos['movimientos'],
        );

        $pdf = Pdf::loadView('recibos.estado-cuenta', $datos + [
            'condominio' => ['nombre' => $condominio->nombre, 'ruc' => $condominio->ruc],
            'fecha' => CarbonImmutable::parse($this->calendario->hoy())->format('d/m/Y'),
        ]);

        return ['contenido' => $pdf->output(), 'nombre' => 'estado-de-cuenta-'.preg_replace('/[^A-Za-z0-9]+/', '', $datos['unidad']['codigo']).'.pdf'];
    }
}
