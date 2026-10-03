<?php

namespace App\Modules\Plataforma\Actions;

use App\Core\Auth\Services\InvitacionService;
use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Rol;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Amenidades\Actions\AsignarAmenidadesAction;
use App\Modules\Finanzas\Actions\GuardarConfiguracionCobroAction;
use App\Modules\Plataforma\Mail\InvitacionAdministradorMail;
use App\Modules\Plataforma\Models\AmenidadCatalogo;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Plataforma\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Alta de un condominio desde el asistente del super admin, todo o nada:
 * condominio, configuración de cobro, amenidades y administrador (nuevo con
 * invitación por correo, o existente asociado como administrador).
 */
final class CrearCondominioAction
{
    public const DIAS_PRUEBA = 30;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly GuardarConfiguracionCobroAction $guardarCobro,
        private readonly AsignarAmenidadesAction $asignarAmenidades,
        private readonly InvitacionService $invitaciones,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  Validados por CrearCondominioRequest
     * @return array{condominio: Condominio, administrador_existente: bool}
     */
    public function execute(array $datos, User $creadoPor): array
    {
        return DB::transaction(function () use ($datos, $creadoPor): array {
            $plan = Plan::query()->where('codigo', $datos['plan_codigo'])->where('activo', true)->firstOrFail();

            $condominio = Condominio::create([
                'codigo' => $this->siguienteCodigo(),
                'nombre' => $datos['nombre'],
                'tipo' => $datos['tipo'],
                'ruc' => $datos['ruc'],
                'razon_social' => $datos['razon_social'],
                'total_unidades' => $datos['total_unidades'],
                'plan_id' => $plan->id,
                'valor_unidad' => $datos['valor_unidad'],
                'provincia_codigo' => $datos['provincia_codigo'],
                'canton_codigo' => $datos['canton_codigo'],
                'parroquia_codigo' => $datos['parroquia_codigo'],
                'direccion' => $datos['direccion'],
                'telefono' => $datos['telefono'] ?? null,
                'email_contacto' => $datos['email_contacto'] ?? null,
                'latitud' => $datos['latitud'],
                'longitud' => $datos['longitud'],
                'estado' => Condominio::ESTADO_PRUEBA,
                'prueba_hasta' => now()->addDays(self::DIAS_PRUEBA)->toDateString(),
            ]);

            $this->tenant->run($condominio->id, function () use ($datos): void {
                $cobro = $datos['cobro'];
                $this->guardarCobro->execute([
                    'metodo' => $cobro['metodo'],
                    'cuota_general' => $cobro['cuota_general'] ?? null,
                    'presupuesto_mensual' => $cobro['presupuesto_mensual'] ?? null,
                    'valores_tipo' => $cobro['valores_tipo'] ?? [],
                    'dia_vencimiento' => (int) $cobro['dia_vencimiento'],
                    'aplica_desde' => $cobro['primera_cuota'].'-01',
                ]);

                $this->asignarAmenidades->execute($this->amenidadesDelCatalogo($datos['amenidades'] ?? []));
            });

            [$administrador, $existente] = $this->administrador($datos['administrador'], $condominio);

            if (! $existente) {
                $token = $this->invitaciones->crear($administrador, $condominio, $creadoPor);

                Mail::to($administrador->email)->queue(new InvitacionAdministradorMail(
                    nombre: $administrador->name,
                    condominio: $condominio->nombre,
                    enlace: config('safic.frontend_url').'/invitacion/'.$token,
                    diasVigencia: InvitacionService::DIAS_VIGENCIA,
                ));
            }

            return ['condominio' => $condominio, 'administrador_existente' => $existente];
        });
    }

    /**
     * Código correlativo SF-0001, SF-0002… El candado de transacción evita que dos
     * altas simultáneas obtengan el mismo código.
     */
    private function siguienteCodigo(): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [hexdec(substr(md5('condominios.codigo'), 0, 7))]);

        $ultimo = (int) DB::table('condominios')
            ->where('codigo', 'like', 'SF-%')
            ->max(DB::raw("CAST(SUBSTRING(codigo FROM 4) AS INTEGER)"));

        return sprintf('SF-%04d', $ultimo + 1);
    }

    /**
     * @param  list<array{amenidad_id: int, cantidad: int}>  $elegidas
     * @return list<array{amenidad_catalogo_id: int, nombre: string, cantidad: int, reservable: bool, esencial: bool, requiere_aprobacion: bool}>
     */
    private function amenidadesDelCatalogo(array $elegidas): array
    {
        $catalogo = AmenidadCatalogo::query()
            ->whereIn('id', array_column($elegidas, 'amenidad_id'))
            ->where('activa', true)
            ->get()
            ->keyBy('id');

        $amenidades = [];
        foreach ($elegidas as $elegida) {
            $item = $catalogo->get($elegida['amenidad_id']);
            if ($item === null) {
                continue;
            }

            $amenidades[] = [
                'amenidad_catalogo_id' => $item->id,
                'nombre' => $item->nombre,
                'cantidad' => (int) $elegida['cantidad'],
                'reservable' => $item->reservable,
                'esencial' => $item->esencial,
                'requiere_aprobacion' => $item->requiere_aprobacion,
            ];
        }

        return $amenidades;
    }

    /**
     * Un usuario existente (por correo) se asocia; si no existe se crea inactivo y
     * se le envía la invitación. La cédula no puede pertenecer a otra persona.
     *
     * @param  array{cedula: string, nombre: string, email: string, celular: string|null}  $datos
     * @return array{0: User, 1: bool}
     */
    private function administrador(array $datos, Condominio $condominio): array
    {
        $email = mb_strtolower($datos['email']);
        $user = User::query()->where('email', $email)->first();

        $otro = User::query()->where('cedula', $datos['cedula'])
            ->when($user !== null, fn ($q) => $q->whereKeyNot($user->id))
            ->exists();

        if ($otro) {
            throw new ApiException('CEDULA_EN_USO', 'Esa cédula ya pertenece a otro usuario con un correo distinto.', 422);
        }

        $existente = $user !== null;

        if ($user === null) {
            $user = User::create([
                'name' => $datos['nombre'],
                'cedula' => $datos['cedula'],
                'email' => $email,
                'celular' => $datos['celular'] ?? null,
                'password' => Str::random(48),
                'activo' => false,
            ]);
        } elseif ($user->cedula === null) {
            $user->forceFill(['cedula' => $datos['cedula']])->save();
        }

        $tienePrincipal = $user->membresias()->where('es_principal', true)->exists();
        $user->membresias()->create([
            'condominio_id' => $condominio->id,
            'es_principal' => ! $tienePrincipal,
            'activo' => true,
        ]);

        $anterior = getPermissionsTeamId();
        setPermissionsTeamId($condominio->id);
        $user->unsetRelation('roles');
        $user->assignRole(Rol::Administrador->value);
        setPermissionsTeamId($anterior);
        $user->unsetRelation('roles');

        return [$user, $existente];
    }
}
