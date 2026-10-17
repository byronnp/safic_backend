<?php

namespace App\Modules\Finanzas\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso a administración y tesorería: llegó el día de un gasto recurrente y falta adjuntar su factura.
 */
class FacturaPorAdjuntarMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $condominio,
        public readonly string $gasto,
        public readonly string $proveedor,
        public readonly ?string $monto,
        public readonly string $fecha,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Falta la factura de {$this->gasto} · {$this->condominio}");
    }

    public function content(): Content
    {
        return new Content(view: 'correos.factura-por-adjuntar');
    }
}
