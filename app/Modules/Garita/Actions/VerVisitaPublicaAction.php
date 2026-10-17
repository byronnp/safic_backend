<?php

namespace App\Modules\Garita\Actions;

use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Modules\Garita\Models\AutorizacionVisita;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Support\Facades\DB;

/**
 * Consulta pública (sin sesión) para el visitante: abre el enlace del QR y ve a dónde va y cuándo. El token lleva el
 * condominio, así que se busca dentro de él. Solo devuelve lo mínimo: el nombre de pila, la unidad y la vigencia; nunca
 * la cédula, la placa ni datos del residente. Un token inexistente responde igual que uno revocado (no hay forma de adivinar).
 */
final class VerVisitaPublicaAction
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array<string, mixed>|null */
    public function execute(string $token): ?array
    {
        [$id] = explode('-', $token, 2) + [null];
        if (! ctype_digit((string) $id)) {
            return null;
        }
        $condominio = DB::table('condominios')->where('id', (int) $id)->first(['id', 'nombre', 'direccion', 'latitud', 'longitud', 'estado']);
        if ($condominio === null || $condominio->estado === Condominio::ESTADO_SUSPENDIDO) {
            return null;
        }

        return $this->tenant->run((int) $condominio->id, function () use ($token, $condominio): ?array {
            $a = AutorizacionVisita::query()->where('token', $token)->first();
            if ($a === null) {
                return null;
            }
            $hoy = app(Calendario::class)->hoy();

            return [
                'token' => $a->token,
                'estado' => $a->estadoEn($hoy),
                'visitante' => explode(' ', trim($a->visitante_nombre))[0],
                'tipo' => $a->tipo,
                'unidad' => Unidad::query()->whereKey($a->unidad_id)->value('codigo'),
                'fecha_desde' => $a->fecha_desde->toDateString(), 'fecha_hasta' => $a->fecha_hasta->toDateString(),
                'hora_desde' => substr($a->hora_desde, 0, 5), 'hora_hasta' => substr($a->hora_hasta, 0, 5), 'dias_semana' => $a->dias_semana,
                'condominio' => [
                    'nombre' => $condominio->nombre, 'direccion' => $condominio->direccion,
                    'latitud' => $condominio->latitud === null ? null : (string) $condominio->latitud,
                    'longitud' => $condominio->longitud === null ? null : (string) $condominio->longitud,
                ],
            ];
        });
    }
}
