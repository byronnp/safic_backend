<?php

namespace App\Modules\Reservas\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Reporte del guardia a la administración: daños o novedades al recibir un área común.
 */
class DanosEnAreaMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $condominio,
        public readonly string $area,
        public readonly string $unidad,
        public readonly string $cuando,
        public readonly string $danos,
        public readonly int $fotos = 0,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Daños reportados en {$this->area} · {$this->condominio}");
    }

    public function content(): Content
    {
        return new Content(view: 'correos.danos-en-area');
    }
}
