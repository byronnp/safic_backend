<?php

namespace App\Modules\Finanzas\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso a administración y tesorería de que alguien pidió cambiar la cuenta bancaria de un
 * proveedor. No lleva el número de cuenta: solo el banco.
 */
class CambioCuentaProveedorMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $condominio,
        public readonly string $proveedor,
        public readonly string $solicitante,
        public readonly string $banco,
        public readonly string $activaEn,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Cambio de cuenta de {$this->proveedor} · {$this->condominio}");
    }

    public function content(): Content
    {
        return new Content(view: 'correos.cambio-cuenta-proveedor');
    }
}
