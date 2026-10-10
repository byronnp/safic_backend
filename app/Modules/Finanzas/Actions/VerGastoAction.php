<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Models\Gasto;
use App\Modules\Finanzas\Models\GastoAprobacion;
use Illuminate\Support\Facades\DB;

/**
 * Una factura con su historial: quién la registró y cada aprobación o rechazo, con su cargo.
 */
final class VerGastoAction
{
    public function __construct(
        private readonly ListarGastosAction $listar,
        private readonly Calendario $calendario,
    ) {}

    /** @return array<string, mixed> */
    public function execute(int $id): array
    {
        $gasto = Gasto::query()->with('proveedor:id,razon_social,ruc')->findOrFail($id);

        $decisiones = GastoAprobacion::query()->where('gasto_id', $id)->orderBy('id')->get();
        $nombres = DB::table('users')
            ->whereIn('id', $decisiones->pluck('user_id')->push($gasto->registrado_por)->filter()->unique()->all())
            ->pluck('name', 'id');

        return $this->listar->fila($gasto, $this->calendario->hoy()) + [
            'registrada_por' => $gasto->registrado_por !== null ? ($nombres[$gasto->registrado_por] ?? null) : null,
            'registrada_por_mi' => $gasto->registrado_por !== null && $gasto->registrado_por === auth()->id(),
            'registrada_en' => $gasto->created_at?->toIso8601String(),
            'aprobaciones' => $decisiones->map(fn (GastoAprobacion $a): array => [
                'nivel' => $a->nivel,
                'decision' => $a->decision,
                'por' => $a->user_id !== null ? ($nombres[$a->user_id] ?? null) : null,
                'comentario' => $a->comentario,
                'en_subrogacion' => $a->en_subrogacion,
                'en' => $a->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
