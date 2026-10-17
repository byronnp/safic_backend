<?php

namespace App\Modules\Comunicacion\Actions;

use App\Modules\Unidades\Actions\DestinatariosDeAnuncioAction;

/**
 * Consulta de la administración: a cuántas unidades y personas con cuenta llegaría un anuncio con ese destino, antes de publicarlo.
 */
final class AlcanceDeAnuncioAction
{
    public function __construct(private readonly DestinatariosDeAnuncioAction $destinatarios) {}

    /** @param list<string>|null $codigos @return array{destino: string, unidades: int, personas_con_cuenta: int} */
    public function execute(string $tipo, ?int $bloqueId, ?array $codigos): array
    {
        $d = $this->destinatarios->execute($tipo, $bloqueId, $codigos);

        return [
            'destino' => $d['texto'],
            'unidades' => count($d['unidades']),
            'personas_con_cuenta' => count(array_filter($d['personas'], fn (array $p): bool => $p['tiene_cuenta'])),
        ];
    }
}
