<?php

namespace App\Modules\Garita\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso a los ocupantes de una unidad: llegó un paquete a la garita, o lleva más de 7 días esperando.
 */
class PaqueteEnGaritaMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nombre,
        public readonly string $condominio,
        public readonly string $unidad,
        public readonly string $empresa,
        public readonly string $recibido,
        public readonly int $dias,
        public readonly bool $recordatorio,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->recordatorio ? 'Recordatorio: tienes un paquete en garita' : 'Tienes un paquete en garita')." · {$this->condominio}");
    }

    public function content(): Content
    {
        return new Content(view: 'correos.paquete-en-garita');
    }
}
