<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Storage\ArchivosCondominio;
use App\Models\User;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Finanzas\Services\DestinatariosFinanzas;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Reservas\Mail\DanosEnAreaMail;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Reservas\Models\ReservaDanoFoto;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Caso de uso del guardia: recibir el área al terminar el uso. Si reporta daños, se guardan en la reserva (con hasta 5 fotos) y se
 * avisa a la administración para que decida si descuenta de la garantía o los cobra a la cuenta de la unidad. Las fotos solo
 * acompañan a un reporte de daños: sin descripción no hay fotos.
 */
final class RecibirAreaAction
{
    public function __construct(private readonly DestinatariosFinanzas $destinatarios, private readonly ArchivosCondominio $archivos) {}

    /** @param list<UploadedFile> $fotos */
    public function execute(User $guardia, int $reservaId, ?string $danos, array $fotos = []): Reserva
    {
        if ($fotos !== [] && $danos === null) {
            throw new ApiException('FOTOS_SIN_DANOS', 'Describe el daño para adjuntar fotos.', 422);
        }
        if (count($fotos) > Reserva::MAXIMO_FOTOS_DANOS) {
            throw new ApiException('DEMASIADAS_FOTOS', 'Puedes adjuntar hasta '.Reserva::MAXIMO_FOTOS_DANOS.' fotos.', 422);
        }

        $rutas = array_map(fn (UploadedFile $f): string => $this->archivos->guardar($f, 'reservas-danos'), $fotos);

        try {
            $reserva = $this->finalizar($guardia, $reservaId, $danos, $rutas);
        } catch (\Throwable $e) {
            foreach ($rutas as $ruta) {
                $this->archivos->eliminar($ruta);
            }

            throw $e;
        }

        if ($danos !== null) {
            $this->avisar($reserva, $danos, count($rutas));
        }

        return $reserva;
    }

    /** @param list<string> $rutas */
    private function finalizar(User $guardia, int $reservaId, ?string $danos, array $rutas): Reserva
    {
        return DB::transaction(function () use ($guardia, $reservaId, $danos, $rutas): Reserva {
            $reserva = Reserva::query()->lockForUpdate()->findOrFail($reservaId);
            if ($reserva->estado !== Reserva::EN_USO) {
                throw new ApiException('RESERVA_NO_EN_USO', 'Solo se recibe un área que está en uso.', 409);
            }
            $reserva->forceFill(['estado' => Reserva::FINALIZADA, 'entrega_en' => now(), 'entrega_por' => $guardia->id, 'danos' => $danos])->save();
            foreach ($rutas as $orden => $ruta) {
                ReservaDanoFoto::create(['reserva_id' => $reserva->id, 'ruta' => $ruta, 'orden' => $orden + 1]);
            }

            return $reserva;
        });
    }

    private function avisar(Reserva $reserva, string $danos, int $fotos): void
    {
        $correos = $this->destinatarios->correos();
        if ($correos === []) {
            return;
        }

        Mail::to($correos)->send(new DanosEnAreaMail(
            (string) (Condominio::query()->whereKey($reserva->condominio_id)->value('nombre') ?? 'tu condominio'),
            (string) (CondominioAmenidad::query()->whereKey($reserva->amenidad_id)->value('nombre') ?? 'Área común'),
            (string) (Unidad::query()->whereKey($reserva->unidad_id)->value('codigo') ?? ''),
            $reserva->fecha->format('d/m/Y').' · '.substr($reserva->inicio, 0, 5).' a '.substr($reserva->fin, 0, 5),
            $danos,
            $fotos,
        ));
    }
}
