<?php

namespace App\Modules\Finanzas\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso al responsable de pago de una unidad: su cuota está por vencer, vence hoy o está atrasada.
 */
class RecordatorioCuotaMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nombre,
        public readonly string $condominio,
        public readonly string $unidad,
        public readonly string $tipo,
        public readonly string $saldo,
        public readonly string $vence,
        public readonly int $cuotas,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $asunto = match ($this->tipo) {
            'antes' => 'Tu cuota está por vencer',
            'dia' => 'Tu cuota vence hoy',
            default => 'Tienes cuotas pendientes',
        };

        return new Envelope(subject: "{$asunto} · {$this->condominio}");
    }

    public function content(): Content
    {
        return new Content(view: 'correos.recordatorio-cuota');
    }
}
