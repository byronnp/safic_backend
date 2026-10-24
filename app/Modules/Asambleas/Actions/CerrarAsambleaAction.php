<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Realtime\EnVivo;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Asambleas\Models\Acta;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\Resolucion;
use App\Modules\Asambleas\Services\ContenidoDeActa;
use App\Modules\Plataforma\Models\Condominio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del presidente: cerrar la asamblea al terminar el orden del día. Se congela el acta (asistencia, quórum, poderes,
 * resultados por punto y resoluciones), se numera por condominio y queda esperando las firmas del presidente y el secretario.
 * Si quedan puntos de votación sin tratar, avisa y pide confirmar: constarán «sin tratar» en el acta.
 */
final class CerrarAsambleaAction
{
    public function __construct(private readonly ContenidoDeActa $contenido, private readonly TenantContext $tenant) {}

    public function execute(User $user, int $id, bool $confirmarPendientes): Acta
    {
        $acta = DB::transaction(function () use ($user, $id, $confirmarPendientes): Acta {
            $a = Asamblea::query()->lockForUpdate()->findOrFail($id);
            if ($a->estado === Asamblea::EN_VOTACION) {
                throw new ApiException('VOTACION_ABIERTA', 'Hay una votación abierta: ciérrala antes de cerrar la asamblea.', 409);
            }
            if ($a->estado !== Asamblea::INSTALADA) {
                throw new ApiException('ASAMBLEA_NO_INSTALADA', $a->cerrada_en !== null ? 'Esta asamblea ya se cerró.' : 'Solo se cierra una asamblea instalada.', 409);
            }

            $pendientes = $this->contenido->pendientes($a);
            if ($pendientes !== [] && ! $confirmarPendientes) {
                throw new ApiException('PUNTOS_PENDIENTES', 'Quedan puntos sin votar: '.implode('; ', array_map(fn (array $p): string => "{$p['orden']}. {$p['titulo']}", $pendientes)).'. Si cierras ahora constarán sin tratar en el acta.', 409, ['puntos' => $pendientes]);
            }

            // Numeración por condominio: se bloquea la fila del condominio para que dos cierres a la vez no repitan número
            Condominio::query()->lockForUpdate()->findOrFail($this->tenant->require());
            $numero = ((int) Acta::query()->max('numero')) + 1;
            $cerradaEn = CarbonImmutable::now();
            $contenido = $this->contenido->armar($a, $cerradaEn, $user);

            $acta = Acta::create([
                'asamblea_id' => $a->id, 'numero' => $numero, 'contenido' => $contenido, 'estado' => Acta::PENDIENTE,
                'hash' => Acta::huellaDe($contenido),
            ]);
            // El día de la asamblea, en la hora del condominio
            $vigente = substr((string) $contenido['asamblea']['fecha_hora'], 0, 10);
            foreach ($contenido['resoluciones'] as $r) {
                Resolucion::create(['asamblea_id' => $a->id, 'acta_id' => $acta->id, 'punto_id' => $r['punto_id'], 'texto' => $r['texto'], 'vigente_desde' => $vigente]);
            }
            $a->forceFill(['estado' => Asamblea::CERRADA, 'cerrada_en' => $cerradaEn, 'cerrada_por' => $user->id])->save();

            return $acta;
        });
        EnVivo::aAsambleas($this->tenant->require(), 'asambleas', $id);

        return $acta;
    }
}
