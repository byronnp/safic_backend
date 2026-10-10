<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Auth\Services\DobleFactorService;
use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Finanzas\Mail\CambioCuentaProveedorMail;
use App\Modules\Finanzas\Models\Proveedor;
use App\Modules\Finanzas\Models\ProveedorCuenta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Cuenta bancaria de un proveedor. Cambiarla es el punto donde más se defrauda: exige la
 * contraseña de quien lo pide, avisa a administración y tesorería, y espera 24 horas; mientras
 * tanto se paga a la cuenta vigente y el cambio se puede detener. La primera cuenta de un proveedor
 * (no hay a quién desviar) queda vigente al instante.
 */
final class CambiarCuentaProveedorAction
{
    public const HORAS_DE_ESPERA = 24;

    public function __construct(private readonly DobleFactorService $contrasena) {}

    /**
     * @param  array{banco: string, tipo: string, numero: string, titular: string}  $datos
     */
    public function solicitar(User $user, int $proveedorId, array $datos, string $contrasena): ProveedorCuenta
    {
        $this->contrasena->exigirContrasena($user, $contrasena);

        $cuenta = DB::transaction(function () use ($user, $proveedorId, $datos): ProveedorCuenta {
            $proveedor = Proveedor::query()->lockForUpdate()->findOrFail($proveedorId);

            if (ProveedorCuenta::query()->where('proveedor_id', $proveedor->id)->where('estado', 'pendiente')->exists()) {
                throw new ApiException('CAMBIO_EN_ESPERA', 'Ya hay un cambio de cuenta en espera. Detenlo o espera a que se active.', 409);
            }

            $hayVigente = ProveedorCuenta::query()->where('proveedor_id', $proveedor->id)->where('estado', 'activa')->exists();

            $cuenta = ProveedorCuenta::create([
                'proveedor_id' => $proveedor->id,
                'banco' => $datos['banco'], 'tipo' => $datos['tipo'], 'numero' => $datos['numero'], 'titular' => $datos['titular'],
                'estado' => $hayVigente ? 'pendiente' : 'activa',
                'activa_en' => now()->addHours($hayVigente ? self::HORAS_DE_ESPERA : 0),
                'solicitada_por' => $user->id,
            ]);

            if ($hayVigente) {
                $this->avisar($proveedor, $user, $cuenta);
            }

            return $cuenta;
        });

        return $cuenta;
    }

    /** Detiene un cambio que aún no se activó. La cuenta vigente sigue igual. */
    public function detener(int $proveedorId): void
    {
        $actualizadas = ProveedorCuenta::query()
            ->where('proveedor_id', $proveedorId)->where('estado', 'pendiente')
            ->update(['estado' => 'cancelada']);

        if ($actualizadas === 0) {
            throw new ApiException('SIN_CAMBIO_EN_ESPERA', 'No hay un cambio de cuenta en espera.', 404);
        }
    }

    /**
     * Activa los cambios cuyas 24 h ya se cumplieron (tarea programada, dentro del condominio).
     * La cuenta vigente pasa a reemplazada. Devuelve cuántos activó.
     */
    public function activarVencidos(): int
    {
        $n = 0;
        $vencidos = ProveedorCuenta::query()->where('estado', 'pendiente')->where('activa_en', '<=', now())->get();

        foreach ($vencidos as $nueva) {
            DB::transaction(function () use ($nueva): void {
                ProveedorCuenta::query()->where('proveedor_id', $nueva->proveedor_id)->where('estado', 'activa')->update(['estado' => 'reemplazada']);
                $nueva->forceFill(['estado' => 'activa'])->save();
            });
            $n++;
        }

        return $n;
    }

    private function avisar(Proveedor $proveedor, User $solicitante, ProveedorCuenta $cuenta): void
    {
        $condominioId = $proveedor->condominio_id;
        $destinatarios = DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->join('users as u', 'u.id', '=', 'mr.model_id')
            ->where('mr.model_type', (new User)->getMorphClass())
            ->where('mr.condominio_id', $condominioId)
            ->whereIn('r.name', ['administrador', 'tesorero'])
            ->whereNotNull('u.email')
            ->distinct()->pluck('u.email');

        $nombreCondominio = (string) DB::table('condominios')->where('id', $condominioId)->value('nombre');

        foreach ($destinatarios as $correo) {
            Mail::to($correo)->queue(new CambioCuentaProveedorMail(
                condominio: $nombreCondominio,
                proveedor: $proveedor->razon_social,
                solicitante: $solicitante->name,
                banco: $cuenta->banco,
                activaEn: $cuenta->activa_en?->setTimezone('America/Guayaquil')->format('d/m/Y H:i') ?? '',
            ));
        }
    }
}
