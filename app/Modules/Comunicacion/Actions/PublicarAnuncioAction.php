<?php

namespace App\Modules\Comunicacion\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Storage\ArchivosCondominio;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Comunicacion\Mail\AnuncioMail;
use App\Modules\Comunicacion\Models\Anuncio;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Actions\DestinatariosDeAnuncioAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Caso de uso de la administración: publicar un anuncio. Se fija la lista de unidades que lo reciben en ese momento (las que
 * tienen a alguien viviendo) y se avisa por correo a quienes tienen correo. Quien llega a vivir después ve el anuncio vigente al
 * entrar, pero no cuenta en su porcentaje de lectura.
 */
final class PublicarAnuncioAction
{
    public function __construct(
        private readonly DestinatariosDeAnuncioAction $destinatarios,
        private readonly ArchivosCondominio $archivos,
        private readonly Calendario $calendario,
    ) {}

    /** @param array{titulo: string, mensaje: string, destino_tipo: string, bloque_id?: int|null, unidades?: list<string>|null, fijar: bool, confirmacion: bool, expira?: string|null} $d */
    public function execute(User $admin, array $d, ?UploadedFile $adjunto): Anuncio
    {
        if (isset($d['expira']) && $d['expira'] < $this->calendario->hoy()) {
            throw new ApiException('EXPIRA_PASADO', 'La fecha en que expira no puede ser pasada.', 422);
        }
        $alcance = $this->destinatarios->execute($d['destino_tipo'], $d['bloque_id'] ?? null, $d['unidades'] ?? null);
        if ($alcance['unidades'] === []) {
            throw new ApiException('SIN_DESTINATARIOS', 'Ese destino no tiene unidades con residentes: el anuncio no llegaría a nadie.', 422);
        }

        $ruta = $adjunto === null ? null : $this->archivos->guardar($adjunto, 'anuncios');

        try {
            $anuncio = DB::transaction(function () use ($admin, $d, $alcance, $ruta, $adjunto): Anuncio {
                $a = Anuncio::create([
                    'titulo' => $d['titulo'], 'mensaje' => $d['mensaje'], 'destino_tipo' => $d['destino_tipo'], 'destino_texto' => $alcance['texto'],
                    'fijado' => $d['fijar'], 'requiere_confirmacion' => $d['confirmacion'], 'expira_en' => $d['expira'] ?? null,
                    'adjunto_ruta' => $ruta, 'adjunto_nombre' => $adjunto === null ? null : Str::limit($adjunto->getClientOriginalName(), 120, ''), 'publicado_por' => $admin->id,
                ]);
                $ahora = now();
                foreach (array_chunk($alcance['unidades'], 500) as $lote) {
                    DB::table('anuncio_destinatarios')->insert(array_map(fn (array $u): array => [
                        'condominio_id' => $a->condominio_id, 'anuncio_id' => $a->id, 'unidad_id' => $u['id'], 'created_at' => $ahora, 'updated_at' => $ahora,
                    ], $lote));
                }

                return $a;
            });
        } catch (\Throwable $e) {
            if ($ruta !== null) {
                $this->archivos->eliminar($ruta);
            }

            throw $e;
        }

        self::notificar($anuncio, $alcance['personas'], false);

        return $anuncio;
    }

    /**
     * Correo a cada persona con correo de las unidades indicadas.
     *
     * @param  list<array{unidad_id: int, nombre: string, email: string|null, tiene_cuenta: bool}>  $personas
     */
    public static function notificar(Anuncio $anuncio, array $personas, bool $recordatorio): void
    {
        $condominio = (string) (Condominio::query()->whereKey($anuncio->condominio_id)->value('nombre') ?? 'tu condominio');
        $extracto = Str::limit(trim($anuncio->mensaje), 280);
        foreach ($personas as $p) {
            if ($p['email'] === null || $p['email'] === '') {
                continue;
            }
            Mail::to($p['email'])->send(new AnuncioMail($p['nombre'], $condominio, $anuncio->titulo, $extracto, $anuncio->requiere_confirmacion, $recordatorio));
        }
    }
}
