<?php

namespace App\Modules\Garita\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Garita\Models\AccesoGarita;
use App\Modules\Garita\Models\AutorizacionVisita;
use App\Modules\Garita\Services\ValidadorDeVisita;
use App\Modules\Unidades\Models\Unidad;
use Carbon\CarbonImmutable;

/**
 * Consulta del guardia: ¿sirve este QR ahora? Devuelve siempre quién es y a dónde va (para que confirme que la persona y la
 * placa coinciden) y, si no sirve, el motivo. Un QR de otro condominio o inventado responde 404 sin decir más.
 */
final class ValidarQrAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Calendario $calendario,
        private readonly ValidadorDeVisita $validador,
    ) {}

    /** @return array<string, mixed> */
    public function execute(string $token): array
    {
        $a = $this->buscar($token);
        $motivo = $this->validador->motivo($a, CarbonImmutable::now($this->calendario->zonaHoraria()));
        // Una recurrente que ya está dentro no vuelve a entrar sin salir antes
        if ($motivo === null && AccesoGarita::query()->where('autorizacion_id', $a->id)->where('estado', AccesoGarita::DENTRO)->exists()) {
            $motivo = 'QR_YA_DENTRO';
        }

        return [
            'valido' => $motivo === null,
            'motivo' => $motivo,
            'mensaje' => $motivo === null ? null : ($motivo === 'QR_YA_DENTRO' ? 'Esta persona ya está dentro. Registra su salida primero.' : $this->validador->mensaje($motivo)),
            'visita' => [
                'visitante' => $a->visitante_nombre,
                'placa' => $a->placa,
                'unidad' => Unidad::query()->whereKey($a->unidad_id)->value('codigo'),
                'autorizo' => $a->creada_por === null ? null : User::query()->whereKey($a->creada_por)->value('name'),
                'tipo' => $a->tipo,
                'fecha_desde' => $a->fecha_desde->toDateString(), 'fecha_hasta' => $a->fecha_hasta->toDateString(),
                'hora_desde' => substr($a->hora_desde, 0, 5), 'hora_hasta' => substr($a->hora_hasta, 0, 5), 'dias_semana' => $a->dias_semana,
            ],
        ];
    }

    /** El QR del condominio activo; cualquier otro responde igual que uno inexistente. */
    public function buscar(string $token): AutorizacionVisita
    {
        $propio = str_starts_with($token, $this->tenant->require().'-');

        return ($propio ? AutorizacionVisita::query()->where('token', $token)->first() : null)
            ?? throw new ApiException('QR_NO_RECONOCIDO', 'No reconocemos este QR. Pide al visitante que lo abra de nuevo o regístralo sin QR.', 404);
    }
}
