<?php

namespace App\Modules\Usuarios\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo a una persona que la administración de un condominio sumó al equipo, con el
 * enlace para crear su contraseña. Se encola después del commit.
 */
class InvitacionUsuarioMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nombre,
        public readonly string $condominio,
        public readonly string $perfil,
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
        return new Content(view: 'correos.invitacion-usuario');
    }
}
