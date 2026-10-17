<?php

namespace App\Modules\Comunicacion\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso a los residentes de un anuncio de la administración (o su recordatorio si aún falta confirmar).
 */
class AnuncioMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nombre,
        public readonly string $condominio,
        public readonly string $titulo,
        public readonly string $extracto,
        public readonly bool $requiereConfirmacion,
        public readonly bool $recordatorio,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->recordatorio ? 'Recordatorio: ' : '').$this->titulo." · {$this->condominio}");
    }

    public function content(): Content
    {
        return new Content(view: 'correos.anuncio');
    }
}
