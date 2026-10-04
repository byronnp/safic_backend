<?php

namespace App\Modules\Plataforma\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de bienvenida al administrador de un condominio nuevo con el enlace para
 * crear su contraseña. Se encola después del commit de la transacción de alta.
 */
class InvitacionAdministradorMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nombre,
        public readonly string $condominio,
        public readonly string $enlace,
        public readonly int $diasVigencia,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Tu acceso a SAFIC · {$this->condominio}");
    }

    public function content(): Content
    {
        return new Content(view: 'correos.invitacion-administrador');
    }
}
