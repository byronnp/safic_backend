<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Realtime\EnVivo;
use App\Core\Tenancy\TenantContext;
use App\Modules\Asambleas\Models\Asamblea;
use Illuminate\Support\Facades\DB;

/** Caso de uso: abrir el registro de asistencia de una asamblea convocada (pasa a `en_registro`). Abrirlo dos veces no hace daño. */
final class AbrirRegistroAction
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function execute(int $id): Asamblea
    {
        $a = DB::transaction(function () use ($id): Asamblea {
            $a = Asamblea::query()->lockForUpdate()->findOrFail($id);
            if ($a->estado === Asamblea::EN_REGISTRO) {
                return $a;
            }
            if ($a->estado !== Asamblea::CONVOCADA) {
                throw new ApiException('REGISTRO_NO_DISPONIBLE', 'Solo se abre el registro de una asamblea convocada.', 409);
            }
            $a->forceFill(['estado' => Asamblea::EN_REGISTRO])->save();

            return $a;
        });
        EnVivo::aAsambleas($this->tenant->require(), 'asambleas', $a->id);

        return $a;
    }
}
