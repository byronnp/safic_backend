<?php

namespace App\Modules\Comunicacion\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso a quien reportó una incidencia: la administración respondió o cambió su estado.
 */
class IncidenciaMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nombre,
        public readonly string $condominio,
        public readonly string $titulo,
        public readonly string $incidencia,
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
        return new Content(view: 'correos.incidencia');
    }
}
