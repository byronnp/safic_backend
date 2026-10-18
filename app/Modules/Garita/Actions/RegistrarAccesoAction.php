<?php

namespace App\Modules\Garita\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Garita\Models\AccesoGarita;
use App\Modules\Garita\Models\AutorizacionVisita;
use App\Modules\Garita\Services\AvisosEnVivoDeGarita;
use App\Modules\Garita\Services\ValidadorDeVisita;
use App\Modules\Unidades\Models\Unidad;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del guardia: registrar un ingreso. Con QR se vuelve a validar al registrar (otra persona pudo usarlo antes) y se
 * cuenta el uso. Sin QR (visita sin anunciar, proveedor, delivery) el guardia confirma por llamada o con la administración.
 */
final class RegistrarAccesoAction
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly ValidadorDeVisita $validador,
        private readonly ValidarQrAction $qr,
        private readonly AvisosEnVivoDeGarita $enVivo,
    ) {}

    /** @param array<string, mixed> $d */
    public function execute(User $guardia, array $d): AccesoGarita
    {
        return DB::transaction(function () use ($guardia, $d): AccesoGarita {
            $acceso = isset($d['token']) ? $this->conQr($guardia, (string) $d['token']) : $this->sinQr($guardia, $d);
            $this->enVivo->aLaUnidad($acceso->condominio_id, $acceso->unidad_id, AvisosEnVivoDeGarita::ACCESOS, $acceso->id);

            return $acceso;
        });
    }

    private function conQr(User $guardia, string $token): AccesoGarita
    {
        $a = $this->qr->buscar($token);
        // Se bloquea la fila: dos guardias que lean el mismo QR a la vez no lo usan dos veces
        $a = AutorizacionVisita::query()->lockForUpdate()->findOrFail($a->id);

        $motivo = $this->validador->motivo($a, CarbonImmutable::now($this->calendario->zonaHoraria()));
        if ($motivo === null && AccesoGarita::query()->where('autorizacion_id', $a->id)->where('estado', AccesoGarita::DENTRO)->exists()) {
            throw new ApiException('QR_YA_DENTRO', 'Esta persona ya está dentro. Registra su salida primero.', 409);
        }
        if ($motivo !== null) {
            throw new ApiException($motivo, $this->validador->mensaje($motivo), 409);
        }

        $a->forceFill(['usos' => $a->usos + 1])->save();

        return AccesoGarita::create([
            'autorizacion_id' => $a->id, 'unidad_id' => $a->unidad_id, 'tipo' => 'visita', 'nombre' => $a->visitante_nombre,
            'documento' => $a->visitante_documento, 'placa' => $a->placa, 'autorizado_por' => 'qr', 'estado' => AccesoGarita::DENTRO,
            'entrada_en' => now(), 'registrado_por' => $guardia->id,
        ]);
    }

    /** @param array<string, mixed> $d */
    private function sinQr(User $guardia, array $d): AccesoGarita
    {
        $unidadId = null;
        if (isset($d['unidad_codigo']) && $d['unidad_codigo'] !== '') {
            $unidadId = Unidad::query()->whereRaw('lower(codigo) = ?', [mb_strtolower((string) $d['unidad_codigo'])])->value('id')
                ?? throw new ApiException('UNIDAD_NO_ENCONTRADA', 'No existe una unidad con ese código.', 422);
        }
        if ($d['tipo'] === 'visita' && $unidadId === null) {
            throw new ApiException('UNIDAD_REQUERIDA', 'Indica a qué unidad va la visita.', 422);
        }
        if ($unidadId === null && (! isset($d['destino']) || trim((string) $d['destino']) === '')) {
            throw new ApiException('DESTINO_REQUERIDO', 'Indica a dónde va (unidad o lugar).', 422);
        }

        return AccesoGarita::create([
            'unidad_id' => $unidadId, 'tipo' => $d['tipo'], 'nombre' => $d['nombre'], 'documento' => $d['documento'] ?? null,
            'placa' => isset($d['placa']) && $d['placa'] !== '' ? strtoupper((string) $d['placa']) : null,
            'destino' => isset($d['destino']) && trim((string) $d['destino']) !== '' ? trim((string) $d['destino']) : null,
            'autorizado_por' => $d['autorizado_por'], 'estado' => AccesoGarita::DENTRO, 'entrada_en' => now(), 'registrado_por' => $guardia->id,
        ]);
    }
}
