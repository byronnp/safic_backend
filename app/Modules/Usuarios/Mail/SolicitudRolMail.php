<?php

namespace App\Modules\Usuarios\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso interno al soporte de la plataforma: un condominio pidió un rol nuevo.
 */
class SolicitudRolMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $condominio,
        public readonly string $solicitante,
        public readonly string $nombre,
        public readonly string $descripcion,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Solicitud de rol · {$this->condominio}");
    }

    public function content(): Content
    {
        return new Content(view: 'correos.solicitud-rol');
    }
}
