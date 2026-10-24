<?php

namespace App\Modules\Asambleas\Actions;

use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPoder;
use App\Modules\Asambleas\Services\PresentadorDePoder;

/** Consulta de la administración: los poderes de una asamblea (sin los revocados) y cuántos faltan por validar. */
final class ListarPoderesAction
{
    public function __construct(private readonly PresentadorDePoder $presentador) {}

    /** @return array{pendientes: int, validados: int, rechazados: int, poderes: list<array<string, mixed>>} */
    public function execute(int $asambleaId): array
    {
        Asamblea::query()->findOrFail($asambleaId);
        $poderes = AsambleaPoder::query()->where('asamblea_id', $asambleaId)->where('estado', '!=', AsambleaPoder::REVOCADO)->orderBy('id')->get();

        return [
            'pendientes' => $poderes->where('estado', AsambleaPoder::PENDIENTE)->count(),
            'validados' => $poderes->where('estado', AsambleaPoder::VALIDADO)->count(),
            'rechazados' => $poderes->where('estado', AsambleaPoder::RECHAZADO)->count(),
            'poderes' => $this->presentador->lista($poderes),
        ];
    }
}
