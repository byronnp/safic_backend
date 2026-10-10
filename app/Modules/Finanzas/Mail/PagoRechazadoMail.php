<?php

namespace App\Modules\Finanzas\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso al residente de que su comprobante fue rechazado, con el motivo, para que pueda volver a enviarlo.
 */
class PagoRechazadoMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nombre,
        public readonly string $condominio,
        public readonly string $unidad,
        public readonly string $monto,
        public readonly string $motivo,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Tu comprobante no fue aprobado · {$this->condominio}");
    }

    public function content(): Content
    {
        return new Content(view: 'correos.pago-rechazado');
    }
}
