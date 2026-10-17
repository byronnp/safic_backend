<?php

namespace App\Modules\Finanzas\Actions;

use App\Modules\Finanzas\Models\ConfiguracionFinanzas;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: el administrador fija las reglas de dinero del condominio. Es una sola fila por
 * condominio; lo que no viaja se conserva. Los cambios quedan auditados.
 */
final class GuardarConfiguracionFinanzasAction
{
    /**
     * @param  array<string, mixed>  $datos  Validados por el controlador
     * @return array{umbral_segunda_aprobacion: string, aprobador_puede_pagar: bool, tolerancia_bancaria: string, dias_gracia: int, meses_restriccion: int, dias_aviso_restriccion: int}
     */
    public function execute(array $datos): array
    {
        return DB::transaction(function () use ($datos): array {
            $config = ConfiguracionFinanzas::query()->lockForUpdate()->first() ?? new ConfiguracionFinanzas;
            $config->fill(array_intersect_key($datos, array_flip(['umbral_segunda_aprobacion', 'aprobador_puede_pagar', 'tolerancia_bancaria', 'dias_gracia', 'meses_restriccion', 'dias_aviso_restriccion'])));
            $config->save();

            return self::formato($config->refresh());
        });
    }

    /**
     * @return array{umbral_segunda_aprobacion: string, aprobador_puede_pagar: bool, tolerancia_bancaria: string, dias_gracia: int, meses_restriccion: int, dias_aviso_restriccion: int}
     */
    public static function formato(ConfiguracionFinanzas $c): array
    {
        return [
            'umbral_segunda_aprobacion' => (string) $c->umbral_segunda_aprobacion,
            'aprobador_puede_pagar' => (bool) $c->aprobador_puede_pagar,
            'tolerancia_bancaria' => (string) $c->tolerancia_bancaria,
            'dias_gracia' => (int) $c->dias_gracia,
            'meses_restriccion' => (int) $c->meses_restriccion,
            'dias_aviso_restriccion' => (int) $c->dias_aviso_restriccion,
        ];
    }
}
