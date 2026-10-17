<?php

namespace App\Modules\Reservas\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso al residente cuando cambia su reserva: aprobada, rechazada, vencida la solicitud o cancelada por falta de pago.
 */
class ReservaActualizadaMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nombre,
        public readonly string $condominio,
        public readonly string $titulo,
        public readonly string $area,
        public readonly string $cuando,
        public readonly string $detalle,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->titulo} · {$this->condominio}");
    }

    public function content(): Content
    {
        return new Content(view: 'correos.reserva-actualizada');
    }
}
