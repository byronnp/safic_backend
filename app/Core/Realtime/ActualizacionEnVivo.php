<?php

namespace App\Core\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Aviso de «algo cambió»: nunca lleva datos de negocio ni personales, solo qué recurso cambió y su id. El cliente vuelve a pedir
 * el dato por la API normal, así RLS, permisos y enmascarado de datos personales siguen decidiendo qué ve cada quien.
 *
 * Sale después de confirmar la transacción: si se publicara antes, el cliente podría recargar y no ver todavía el cambio.
 */
final class ActualizacionEnVivo implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    /** Un fallo de Reverb se reintenta poco: el cliente tiene su consulta periódica de respaldo. */
    public int $tries = 2;

    /** @param list<string> $canales nombres de canal privado, sin el prefijo «private-» */
    public function __construct(
        public readonly array $canales,
        public readonly string $recurso,
        public readonly ?int $id = null,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (string $canal): PrivateChannel => new PrivateChannel($canal), $this->canales);
    }

    public function broadcastAs(): string
    {
        return 'actualizacion';
    }

    /** @return array{recurso: string, id: int|null} */
    public function broadcastWith(): array
    {
        return ['recurso' => $this->recurso, 'id' => $this->id];
    }
}
