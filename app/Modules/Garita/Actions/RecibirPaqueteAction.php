<?php

namespace App\Modules\Garita\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Storage\ArchivosCondominio;
use App\Models\User;
use App\Modules\Garita\Mail\PaqueteEnGaritaMail;
use App\Modules\Garita\Models\Paquete;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Actions\OcupantesVigentesAction;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Caso de uso del guardia: recibir un paquete para una unidad, con su foto, y avisar por correo a quienes viven ahí.
 */
final class RecibirPaqueteAction
{
    public function __construct(private readonly ArchivosCondominio $archivos, private readonly OcupantesVigentesAction $ocupantes) {}

    public function execute(User $guardia, string $unidadCodigo, string $empresa, ?string $descripcion, UploadedFile $foto): Paquete
    {
        $unidad = Unidad::query()->whereRaw('lower(codigo) = ?', [mb_strtolower(trim($unidadCodigo))])->first()
            ?? throw new ApiException('UNIDAD_NO_ENCONTRADA', 'No existe una unidad con ese código.', 422);

        $ruta = $this->archivos->guardar($foto, 'paquetes');

        try {
            $paquete = DB::transaction(fn (): Paquete => Paquete::create([
                'unidad_id' => $unidad->id, 'empresa' => $empresa, 'descripcion' => $descripcion, 'foto_ruta' => $ruta,
                'estado' => Paquete::EN_GARITA, 'recibido_en' => now(), 'recibido_por' => $guardia->id,
            ]));
        } catch (\Throwable $e) {
            $this->archivos->eliminar($ruta);

            throw $e;
        }

        $this->avisar($paquete, $unidad->codigo, false);

        return $paquete;
    }

    /** Correo a cada ocupante vigente con correo. Un fallo al enviar no deshace la recepción. */
    public function avisar(Paquete $paquete, string $unidad, bool $recordatorio): void
    {
        $condominio = (string) (Condominio::query()->whereKey($paquete->condominio_id)->value('nombre') ?? 'tu condominio');
        $dias = (int) $paquete->recibido_en->diffInDays(now());
        foreach ($this->ocupantes->execute($paquete->unidad_id) as $o) {
            if ($o['email'] === null || $o['email'] === '') {
                continue;
            }
            Mail::to($o['email'])->send(new PaqueteEnGaritaMail($o['nombre'], $condominio, $unidad, $paquete->empresa, $paquete->recibido_en->format('d/m/Y H:i'), $dias, $recordatorio));
        }
    }
}
