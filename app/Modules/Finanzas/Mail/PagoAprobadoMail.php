<?php

namespace App\Modules\Finanzas\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso al residente de que su pago fue aprobado. El recibo en PDF se descarga desde Mi cuenta.
 */
class PagoAprobadoMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $meses
     */
    public function __construct(
        public readonly string $nombre,
        public readonly string $condominio,
        public readonly string $unidad,
        public readonly string $monto,
        public readonly string $recibo,
        public readonly array $meses,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Pago aprobado · recibo N.º {$this->recibo} · {$this->condominio}");
    }

    public function content(): Content
    {
        return new Content(view: 'correos.pago-aprobado');
    }
}
