<?php

namespace App\Modules\Finanzas\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notificación del Decreto 462: con el detalle de la deuda, avisa desde qué día se restringe el uso
 * de las áreas comunes y cómo evitarlo (pagar o firmar un convenio).
 */
class RestriccionAreasMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nombre,
        public readonly string $condominio,
        public readonly string $unidad,
        public readonly string $saldo,
        public readonly int $cuotas,
        public readonly int $meses,
        public readonly string $restringeDesde,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Aviso de restricción de áreas comunes · {$this->condominio}");
    }

    public function content(): Content
    {
        return new Content(view: 'correos.restriccion-areas');
    }
}
