<?php

namespace App\Modules\Garita\Actions;

use App\Models\User;
use App\Modules\Garita\Models\AutorizacionVisita;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: el residente revoca una autorización de una de sus unidades. El QR deja de servir al instante.
 * Una autorización de otra unidad se responde como si no existiera.
 */
final class RevocarAutorizacionVisitaAction
{
    public function __construct(private readonly UnidadesDeCuentaAction $unidades) {}

    public function execute(User $user, int $id): AutorizacionVisita
    {
        $ids = array_column($this->unidades->execute($user->id), 'unidad_id');

        return DB::transaction(function () use ($ids, $id): AutorizacionVisita {
            $a = AutorizacionVisita::query()->whereIn('unidad_id', $ids)->lockForUpdate()->findOrFail($id);
            if ($a->revocada_en === null) {
                $a->forceFill(['revocada_en' => now()])->save();
            }

            return $a;
        });
    }
}
