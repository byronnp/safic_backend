<!--
Copia en Markdown del documento vivo "SAFIC — Arquitectura backend (Laravel)":
https://claude.ai/code/artifact/d830ac1f-ab1d-4d2d-8c0e-1e1f52de9adc
Exportada el 9-oct-2026 para que Claude Code la lea sin salir del repositorio.

Precedencia si algo no coincide: 1) el código, 2) CLAUDE.md, 3) docs/arquitectura.md
(decisiones recientes), 4) este documento. Desfases conocidos al exportar:
- Donde dice /platform/* el prefijo real es /plataforma/* (middleware `plataforma`, equipo 0).
- Las rutas del super admin no usan withoutGlobalScope: escriben datos de un condominio
  dentro de TenantContext::run() con las Actions del módulo dueño.
- Cada módulo usa Routes/{condominio,plataforma,sesion}.php, no un único routes.php.
- Métodos de cobro en el código: general, tipo, alicuota, unidad.
Para actualizar esta copia: exportar de nuevo el documento vivo (no editarla a mano).
-->

# SAFIC — Arquitectura backend (Laravel)

Sep 26, 2026 · @BYRON

## Decisiones principales y stack

Un **monolito modular** en Laravel 13 (PHP 8.5): una sola aplicación y una sola base de datos, organizada por módulos de negocio que corresponden a las fases. Es más simple de operar que microservicios y cada módulo puede extraerse después si algún día lo necesita.

| Pieza | Paquete | Para qué |
| --- | --- | --- |
| Framework | Laravel 13 sobre PHP 8.5 | API REST; bug fixes hasta Q3 2027, seguridad hasta mar 2028 |
| Base de datos | PostgreSQL 16 | Row Level Security, `tstzrange` y restricciones de exclusión para reservas, JSONB |
| Caché, colas, sesiones | Redis | Colas con Laravel Horizon |
| Autenticación | php-open-source-saver/jwt-auth (Laravel 12 y 13, lcobucci/jwt 5) | JWT de acceso corto + refresh token rotativo, igual en web y móvil |
| Roles y permisos | spatie/laravel-permission (con *teams*) | Permisos por condominio |
| DTOs y validación tipada | spatie/laravel-data | Datos de entrada y salida tipados entre capas |
| Filtros y orden en listados | spatie/laravel-query-builder | `?filter[estado]=vencida&sort=-fecha` sin código repetido |
| Auditoría | owen-it/laravel-auditing | Quién cambió qué, antes y después |
| Tiempo real | laravel/reverb | WebSockets para garita, pagos y asambleas |
| PDFs | spatie/laravel-pdf | Recibos, estados de cuenta, actas |
| Excel | openspout/openspout | Importar unidades y estados de cuenta del banco, exportar reportes; bajo consumo de memoria |
| Push | FCM HTTP v1 vía canal de notificaciones | Avisos a la app móvil |
| IA (opcional, fase posterior) | Laravel AI SDK de Laravel 13 + API de Claude | Lectura de estados de cuenta en PDF |
| Calidad | Pest, Larastan (nivel 8), Pint | Pruebas, análisis estático, estilo |
| Monitoreo | Sentry + Laravel Pulse | Errores y rendimiento |

Las versiones exactas de cada paquete se fijan en `composer.lock` al crear el proyecto, usando la última estable compatible con Laravel 13; Renovate propone actualizaciones una vez al mes.

**Conexión a PostgreSQL**

```ini
# .env
DB_CONNECTION=pgsql
DB_HOST=safic-prod.xxxxxx.us-east-1.rds.amazonaws.com
DB_PORT=5432
DB_DATABASE=safic
DB_USERNAME=safic_app          # sin BYPASSRLS ni permisos DDL
DB_PASSWORD=                        # desde AWS Secrets Manager
DB_SSLMODE=verify-full
DB_SSLROOTCERT=/etc/ssl/rds-global-bundle.pem

DB_MIGRATION_USERNAME=safic_owner   # solo el pipeline de despliegue
```

```php
// config/database.php → connections.pgsql
'charset'     => 'utf8',
'search_path' => 'public',
'sslmode'     => env('DB_SSLMODE', 'prefer'),      // verify-full en producción
'sslrootcert' => env('DB_SSLROOTCERT'),            // certificado de RDS
'timezone'    => 'UTC',                            // todo se guarda en UTC
```

- **Dos usuarios de base de datos:** `safic_app` para la aplicación, sin `BYPASSRLS` ni permisos para cambiar estructura, así Row Level Security siempre aplica; `safic_owner` es dueño de las tablas y solo lo usa el pipeline para correr migraciones.
- **Contexto de condominio por transacción:** el middleware abre la transacción de la petición y ejecuta `SET LOCAL app.condominio_id = ?`. Al ser `LOCAL`, el valor muere con la transacción y nunca se filtra a otra petición aunque la conexión se reutilice (compatible con RDS Proxy o PgBouncer en modo transacción).
- **Conexión de solo lectura** opcional (`read` / `write` en la configuración) hacia una réplica de RDS para reportes y exportaciones del contador.
- **Extensiones** creadas en la primera migración: `btree_gist` (restricción de exclusión de reservas) y `pgcrypto` (UUID y hashes).
- **Local:** Laravel Sail con PostgreSQL 16, mismo motor y extensiones que producción.

## Estructura del proyecto

Lo transversal (multi-condominio, autenticación, archivos, auditoría) vive en `app/Core`; cada fase es un módulo en `app/Modules` con todas sus piezas juntas.

```
app/
  Core/
    Tenancy/            TenantContext, BelongsToCondominio (trait),
                        ResolveCondominio (middleware), CondominioScope
    Auth/               JWT, login, invitaciones, recuperación, segundo factor
    Permissions/        Seeder de roles y permisos, PermisoEnum
    Files/              Archivo (modelo), URLs prefirmadas S3, procesado de imágenes
    Audit/              Configuración de auditoría
    Notifications/      Canal FCM, canal base de datos, preferencias
    Support/            Money (value object), Cedula, Ruc, Placa, BusinessDays
    Banking/            Lectores de estado de cuenta (Pichincha, Produbanco,
                        Internacional), compartidos por Finanzas y Suscripciones
    Subscriptions/      Middlewares ModuloIncluido y SuscripcionActiva,
                        contratos LimiteUnidades y LimiteUsuariosAdmin
    Privacy/            Acuerdo de confidencialidad, acceso con vigencia,
                        enmascarado de datos, bitácora de lecturas y exportaciones
    Http/               Responses/ApiResponse, ApiException, formato de errores
  Modules/
    Plataforma/         Condominios, planes, alta con asistente (fase 1)
    Unidades/           Bloques, unidades, personas, ocupantes, vehículos,
                        mascotas, amenidades, importación (fase 1)
    Finanzas/           Cuotas, cargos, pagos, cuentas por pagar y pagos
                        a proveedores, caja chica, conciliación (fase 2)
    Reservas/           Áreas, reglas, reservas, bloqueos (fase 3)
    Garita/             Visitas, autorizaciones QR, accesos, paquetes (fase 4)
    Comunicacion/       Anuncios, incidencias (fase 4)
    Asambleas/          Asambleas, votaciones, actas (fase 5)
    Suscripciones/      Planes, suscripciones, facturación SRI, pagos a la
                        plataforma, cuenta corriente, configuración (fase 6)
database/
  migrations/           por módulo: 2026_10_01_000100_unidades_create_unidades.php
  seeders/              roles y permisos, catálogos (provincias, tipos de amenidad, feriados)
routes/
  api.php               grupos (condominio, plataforma) y rutas transversales;
                        carga solo las Routes/ de cada módulo, prefijo /api/v1
  channels.php          canales privados de Reverb
tests/
  Feature/<Módulo>/     pruebas de API
  Unit/<Módulo>/        reglas de negocio
  Tenancy/              pruebas de aislamiento entre condominios
```

Dentro de cada módulo:

```
app/Modules/Finanzas/
  Http/
    Controllers/        PagoController, ConciliacionController (delgados)
    Requests/           AprobarPagoRequest, ImportarEstadoCuentaRequest
    Resources/          PagoResource, CargoResource
  Actions/              casos de uso: AprobarPago, GenerarCuotasMensuales,
                        ImportarEstadoCuenta, CerrarPeriodo
  Services/             lógica de negocio reutilizable del módulo
    CalculadoraCuotasService.php   valor por método (general, tipo, alícuota), excepciones
    AplicacionPagoService.php      cuotas completas desde la más antigua, tolerancia, saldo a favor
    MorosidadService.php           marca en_mora, antigüedad de cartera, restricciones
    ConciliacionService.php        clasificación de movimientos, reglas, cuadre
    ReciboService.php              numeración secuencial y PDF del recibo
  Data/                 PagoData, ConciliacionData (spatie/laravel-data)
  Models/               Cargo, Pago, PagoAplicacion, Gasto, MovimientoBancario
  Policies/             PagoPolicy, ConciliacionPolicy
  Enums/                EstadoPago, TipoMovimiento, MetodoCobro
  Events/               PagoAprobado, PeriodoCerrado
  Listeners/            EnviarRecibo, InvalidarResumen
  Jobs/                 GenerarCuotasCondominio, ProcesarImportacionBanco
  Notifications/        PagoAprobadoNotification, RecordatorioVencimiento
  (lectores de estados de cuenta: en Core/Banking, compartidos)
  Routes/
    condominio.php      rutas del condominio (X-Condominio-Id)
    plataforma.php      rutas del panel del super admin (/plataforma), si las tiene
    sesion.php          catálogos compartidos con sesión y sin condominio, si los tiene
  FinanzasServiceProvider.php
```

**Rutas por módulo.** Cada módulo tiene sus propios archivos de rutas en `Routes/`, uno por ámbito: `condominio.php` (grupo `auth:api` + `condominio` + `throttle:api`, con el header `X-Condominio-Id`) y `plataforma.php` (grupo `auth:api` + `plataforma`, prefijo `/plataforma`, roles del equipo 0). Un módulo con pantallas en los dos ámbitos, como Suscripciones (Mi suscripción y la cobranza del super admin), usa los dos. `routes/api.php` solo declara los grupos y las rutas transversales (auth, `/me/*`) y carga solo los archivos de los módulos, así que crear un módulo no obliga a editarlo. Toda ruta de un módulo exige su permiso: la prueba `RutasPorModuloTest` falla si un archivo está fuera de la convención, si una ruta no tiene permiso o si está en el grupo equivocado, y `ContratoOpenApiTest` si falta en el contrato.

Hay un tercer ámbito, `sesion.php` (grupo `auth:api`, sin condominio), solo para catálogos compartidos que no exponen datos de un condominio, como `GET /ubicaciones`; esas rutas no piden permiso. La prueba admite solo estos tres nombres de archivo.

Servicios transversales en `app/Core/Services/`:

| Service | Responsabilidad |
| --- | --- |
| `FileStorageService` | URLs prefirmadas de S3, confirmación, URLs de descarga |
| `PdfService` | Renderizar plantillas Blade a PDF |
| `PushService` | Envío a FCM y limpieza de tokens inválidos |
| `JwtTokenService` | Emitir, rotar y revocar access y refresh tokens, lista negra |
| `SequenceService` | Números secuenciales por condominio (recibos, actas) con bloqueo de fila |
| `BusinessDaysService` | Días hábiles con feriados de Ecuador |
| `ExcelService` | Lectura y escritura por streaming con OpenSpout |

Regla: un módulo no usa modelos de otro directamente para escribir; se comunica por **Actions públicas** o **eventos** (ej. Reservas llama a `Finanzas\Actions\CrearCargo` para cobrar el uso de un área). Los Services de un módulo son internos; los de `app/Core/Services` los usan todos. Se controla con pruebas de arquitectura de Pest (`arch()`).

## Capas y flujo de una petición

Petición → middleware → **FormRequest** (valida y autoriza) → **Controller** (delgado) → **Action** (caso de uso, en transacción) → **Services** (lógica reutilizable e integraciones) → **Models** → evento → **Resource + ApiResponse** (respuesta JSON con envoltorio común).

| Capa | Responsabilidad | No hace |
| --- | --- | --- |
| Middleware | Sesión JWT, condominio activo (`ResolveCondominio`), límite de peticiones | Lógica de negocio |
| FormRequest | Validación de formato (cédula, montos, fechas) y `authorize()` con la Policy | Consultas complejas |
| Controller | Recibe el request, llama una Action (o un Service para lecturas simples), devuelve `ApiResponse` con un Resource | Reglas de negocio, `DB::` |
| Action | Un caso de uso completo con nombre de verbo (`AprobarPago`): abre la transacción, bloquea registros, coordina Services y emite eventos | Contener cálculos reutilizables; conocer HTTP |
| Service | Lógica de negocio reutilizable (cálculo de cuotas, aplicación de pagos, quórum) o integración externa (S3, PDF, FCM, JWT); sin estado, inyectado por el contenedor | Abrir transacciones propias ni emitir eventos de dominio (eso es de la Action) |
| Model | Relaciones, casts, scopes, trait de condominio | Reglas que involucran varios modelos |
| Policy | Quién puede hacer qué sobre un registro |  |
| Resource | Forma del JSON de salida; oculta campos según permisos (ej. cédula enmascarada para guardia) |  |
| Event / Listener / Job | Efectos secundarios: recibo PDF, notificaciones, invalidar cachés | Bloquear la respuesta al usuario |

**Action o Service:** si responde a "qué quiere hacer el usuario" (aprobar un pago, cerrar un mes, reservar) es una Action; si responde a "cómo se calcula o cómo se conecta" (cuánto es la cuota, a qué cuotas se aplica un pago, cómo se sube a S3) es un Service. Los Services se prueban con pruebas unitarias sin base de datos cuando es posible.

**Ejemplo: aprobar un pago**

```php
// Controller
public function aprobar(AprobarPagoRequest $request, Pago $pago, AprobarPago $action)
{
    $pago = $action->execute($pago, $request->user());
    return ApiResponse::ok(PagoResource::make($pago), 'Pago aprobado');
}

// Action
final class AprobarPago
{
    public function __construct(
        private AplicacionPagoService $aplicacion,
        private ReciboService $recibos,
    ) {}

    public function execute(Pago $pago, User $aprobador): Pago
    {
        return DB::transaction(function () use ($pago, $aprobador) {
            $pago = Pago::whereKey($pago->id)->lockForUpdate()->firstOrFail();
            throw_if($pago->estado !== EstadoPago::Pendiente, PagoNoPendiente::class);

            $this->aplicacion->aplicar($pago);        // Service: cuotas completas, tolerancia, saldo a favor
            $pago->update(['estado' => EstadoPago::Aprobado, 'aprobado_por' => $aprobador->id]);

            $this->recibos->emitir($pago);           // Service: número secuencial del recibo
            PagoAprobado::dispatch($pago);           // PDF, notificación, tiempo real (después del commit)
            return $pago->fresh();
        });
    }
}
```

Las Actions se reutilizan desde controladores, jobs, comandos de consola y otros módulos, y se prueban sin HTTP.

## Multi-condominio

Tres barreras independientes; si una falla, las otras siguen protegiendo los datos.

**1. Contexto del condominio (`Core/Tenancy`)**

- `ResolveCondominio` lee `X-Condominio-Id`, verifica en `condominio_user` que el usuario tiene pertenencia **activa** en un condominio **activo** (si no, 403) y guarda el id en `TenantContext` (singleton por petición).
- Fija el equipo de Spatie (`setPermissionsTeamId`) para que los permisos se evalúen en ese condominio.
- En jobs, el condominio viaja en el payload y un middleware de job restaura el contexto; en Octane o colas se limpia al terminar cada tarea.

**2. Scope global en Eloquent**

```php
trait BelongsToCondominio
{
    protected static function bootBelongsToCondominio(): void
    {
        static::addGlobalScope(new CondominioScope);           // WHERE condominio_id = ?
        static::creating(fn ($m) => $m->condominio_id ??= app(TenantContext::class)->id());
    }
}
```

Todo modelo de negocio usa el trait. Sin contexto de condominio, el scope lanza una excepción en lugar de devolver todo. Las rutas `/platform/*` del super admin lo desactivan de forma explícita con `withoutGlobalScope` y quedan auditadas.

**3. Row Level Security en PostgreSQL**

```sql
ALTER TABLE unidades ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON unidades
  USING (condominio_id = current_setting('app.condominio_id')::bigint);
```

- El middleware ejecuta `SET LOCAL app.condominio_id = ?` al inicio de la transacción de cada petición.
- La aplicación se conecta con un usuario de base de datos sin privilegio `BYPASSRLS`; las migraciones y el super admin usan otro usuario.
- Una migración base crea la política en cada tabla nueva con `condominio_id`.
- Excepción de catálogos mixtos (`tipos_amenidad`): `condominio_id` puede ser nulo (tipo global). Su política de lectura es `condominio_id IS NULL OR condominio_id = current_setting('app.condominio_id')::bigint`; la de escritura exige `condominio_id` igual al actual, así que los tipos globales solo se escriben con el usuario de plataforma. El modelo usa el trait `CatalogoMixto` en lugar de `BelongsToCondominio`.

**Pruebas de aislamiento obligatorias** (`tests/Tenancy`): por cada endpoint, un usuario del condominio A intenta leer, editar y borrar un registro del condominio B y debe recibir 403 o 404. Se generan automáticamente recorriendo las rutas registradas, así ningún endpoint nuevo queda sin probar.

## Autenticación, roles y permisos

**JWT (php-open-source-saver/jwt-auth)**

| Token | Formato | Duración | Dónde vive |
| --- | --- | --- | --- |
| Access token | JWT firmado con RS256 (llave privada en AWS Secrets Manager) | 15 minutos | Web: solo en memoria (store de Pinia, nunca `localStorage`). Móvil: memoria |
| Refresh token | JWT con `jti` único, rotativo | 30 días | Web: cookie `HttpOnly`, `Secure`, `SameSite=Strict`, con ruta `/api/v1/auth/refresh`. Móvil: almacenamiento seguro (Keychain / Keystore) |

- **Claims mínimos:** `sub` (id de usuario), `jti`, `iat`, `exp`, `iss`, `aud`. El token **no lleva roles ni condominio**: los permisos se evalúan en cada petición con `X-Condominio-Id`, así quitar un rol o desactivar una pertenencia aplica de inmediato sin esperar a que venza el token.
- **Rotación:** `POST /auth/refresh` entrega un access token y un refresh token nuevos e invalida el anterior. Si alguien reutiliza un refresh token ya usado (posible robo), se revocan todas las sesiones de ese usuario y se le avisa.
- **Lista negra en Redis:** logout, cambio de contraseña, bloqueo del usuario y "cerrar sesión en todos los dispositivos" agregan los `jti` activos a la lista negra hasta su vencimiento.
- **Tabla `sesiones`:** user\_id, jti del refresh, dispositivo, IP, último uso, revocada\_en; alimenta la pantalla "Mis dispositivos" y la revocación selectiva.
- **Guard `api` con driver `jwt`** en `config/auth.php`; la autorización de canales de Reverb (`/broadcasting/auth`) usa el mismo guard.
- **Rotación de llaves:** par RS256 con `kid` en el encabezado; se puede publicar una llave nueva sin cerrar las sesiones vigentes.
- Bloqueo tras 5 intentos fallidos en 15 minutos (`RateLimiter`); contraseñas con `Password::min(8)->uncompromised()`.
- Invitaciones y recuperación de contraseña: token aleatorio de un solo uso, guardado como hash, válido 72 horas (no son JWT).

**Roles y permisos (spatie/laravel-permission con teams)**

- El "team" de Spatie es el condominio: el mismo usuario tiene roles distintos en cada uno.
- Roles sembrados: `super_admin` (sin team), `soporte`, `cobranza`, `contador_plataforma`, `administrador`, `tesorero`, `contador`, `presidente`, `vicepresidente`, `secretario`, `guardia`, `residente`, `mantenimiento`.
- Permisos como enum `PermisoEnum` (`unidades.editar`, `finanzas.aprobar-pagos`, `finanzas.exportar`, `asambleas.instalar`...) para evitar errores de escritura.
- Las Policies combinan permiso y regla de negocio: `PagoPolicy::aprobar()` exige `finanzas.aprobar-pagos` y que el pago sea del condominio activo; `VotoPolicy::emitir()` exige ser propietario o apoderado y no estar en mora.
- `GET /me` devuelve la lista de permisos por condominio para que el frontend arme el menú.
- Asignar tesorero, presidente, vicepresidente o secretario valida que la persona no esté en mora (Decreto 462); un listener revisa diariamente si algún titular entró en mora y avisa al administrador.
- Subrogación: la tabla `ausencias_directiva` (persona, cargo, desde, hasta, motivo) activa para el vicepresidente los permisos del presidente mientras dure la ausencia; `SubrogacionService::quienFirma()` lo resuelve y cada acción guarda `en_subrogacion = true`.
- - Cargos únicos: presidente, vicepresidente, secretario y tesorero viven en `cargos_directiva`, no se asignan con `assignRole` directo. `Unidades\Actions\NombrarCargoAction` bloquea la fila con `lockForUpdate`, cierra el cargo anterior, crea el nuevo y sincroniza el rol de Spatie en la misma transacción. La base lo garantiza con dos índices únicos parciales:

  ```sql
  CREATE UNIQUE INDEX cargo_unico ON cargos_directiva (condominio_id, cargo) WHERE hasta IS NULL OR prorrogado;
  CREATE UNIQUE INDEX persona_un_cargo ON cargos_directiva (condominio_id, user_id) WHERE hasta IS NULL OR prorrogado;
  ```
  - Errores: `422 CARGO_OCUPADO` (trae quién lo ocupa y ofrece "Cambiar"), `422 PERSONA_YA_TIENE_CARGO`, `422 PERSONA_EN_MORA`. La elección de directiva de la fase 5 llama a la misma Action con el `acta_id`.

## Convenciones de la API REST

| Tema | Convención |
| --- | --- |
| Prefijo y versión | `/api/v1`; un cambio incompatible crea `/api/v2` y la v1 se mantiene 6 meses |
| Nombres | Recursos en español y plural (`/unidades`, `/pagos`); acciones como subrecurso con verbo (`POST /pagos/{id}/aprobar`) |
| Condominio | Header `X-Condominio-Id` obligatorio salvo `/auth`, `/me`, `/platform` y webhooks |
| Listados | Paginación `?page=&per_page=` (máx. 100); filtros `?filter[estado]=vencida`; orden `?sort=-fecha`; búsqueda `?filter[q]=` |
| Fechas | ISO 8601 en UTC (`2026-10-02T00:00:00Z`); el frontend convierte a la zona del condominio |
| Montos | String decimal con 2 cifras (`"80.00"`) y la moneda en el recurso del condominio; nunca float |
| Idempotencia | Header `Idempotency-Key` en crear pagos, subir comprobantes y votar; un reintento devuelve la misma respuesta |
| Concurrencia | Campo `version` en recursos editables; enviar uno desactualizado devuelve 409 |

**Capa de respuesta**

Dos piezas trabajan juntas: los **Resources** definen la forma de cada registro y **`ApiResponse`** (en `app/Core/Http/Responses`) define el envoltorio común. Ningún controlador devuelve `response()->json()` ni modelos directamente.

| Pieza | Ubicación | Responsabilidad |
| --- | --- | --- |
| `ApiResponse` | `Core/Http/Responses/ApiResponse.php` | Métodos `ok()`, `created()`, `accepted()`, `noContent()`, `paginated()`, `download()`; arma el envoltorio y el código HTTP |
| Resource | `Modules/<Módulo>/Http/Resources/` | Transforma un modelo en JSON: nombres de campo, montos como string, fechas ISO en UTC, campos ocultos según permisos (`$this->when(...)`) |
| ResourceCollection | Igual | Listas y paginación con el mismo formato |
| Handler de excepciones | `bootstrap/app.php` (`withExceptions`) | Convierte cualquier excepción en el formato de error único |

**Respuesta exitosa**

```json
{
  "data": { "id": 912, "unidad": "A-102", "monto": "160.00", "estado": "aprobado" },
  "message": "Pago aprobado",
  "meta": { "condominio_id": 12 }
}
```

**Respuesta paginada**

```json
{
  "data": [ { "id": 1, "codigo": "A-101" } ],
  "meta": { "current_page": 1, "per_page": 25, "total": 148, "last_page": 6 },
  "links": { "next": "/api/v1/unidades?page=2", "prev": null }
}
```

| Situación | Método | HTTP |
| --- | --- | --- |
| Consulta o actualización | `ApiResponse::ok($resource, 'mensaje')` | 200 |
| Creación | `ApiResponse::created($resource)` con header `Location` | 201 |
| Proceso en cola (importación, exportación) | `ApiResponse::accepted(['importacion_id' => ...])` | 202 |
| Eliminación o acción sin cuerpo | `ApiResponse::noContent()` | 204 |
| Listado | `ApiResponse::paginated(UnidadResource::collection($paginator))` | 200 |
| Archivo (PDF, Excel) | `ApiResponse::download($archivo)` devuelve una URL firmada de S3 por 10 minutos | 200 |

```php
public function aprobar(AprobarPagoRequest $request, Pago $pago, AprobarPago $action)
{
    $pago = $action->execute($pago, $request->user());

    return ApiResponse::ok(PagoResource::make($pago), 'Pago aprobado');
}
```

Reglas: `message` se muestra tal cual al usuario (en español); los Resources nunca incluyen relaciones no cargadas (`whenLoaded`) para evitar consultas N+1; y una prueba de arquitectura verifica que los controladores solo devuelvan `ApiResponse`.

**Formato de error único**

```json
{
  "error": {
    "code": "comprobante_duplicado",
    "message": "Este número de comprobante ya fue registrado.",
    "fields": { "numero_comprobante": ["Ya existe en el pago de B-112 del 20 sep."] }
  }
}
```

| Código HTTP | Uso |
| --- | --- |
| 401 | Sin sesión |
| 403 | Sin permiso o condominio no activo |
| 404 | No existe o es de otro condominio (no se distingue, para no revelar datos) |
| 409 | Conflicto de negocio: horario ocupado, comprobante duplicado, periodo cerrado |
| 422 | Validación de campos |
| 429 | Demasiadas peticiones |

La documentación OpenAPI se genera desde los FormRequests y Resources (Scramble o equivalente) y se publica en `/docs/api` solo en staging.

## Colas, tareas programadas, eventos y tiempo real

**Colas (Redis + Horizon)**, separadas por prioridad con el enrutamiento de colas de Laravel 13 (`Queue::route`):

| Cola | Trabajos | Prioridad |
| --- | --- | --- |
| `critical` | Validación de QR offline sincronizado, votos, notificación de visita en garita | Máxima |
| `default` | Recibos PDF, notificaciones push y correo | Normal |
| `imports` | Importar unidades desde Excel, importar estados de cuenta del banco | Baja, 1 a la vez por condominio |
| `reports` | Exportaciones a Excel, reportes pesados | Baja |

Cada job lleva el `condominio_id` y restaura el contexto; los jobs de dinero son idempotentes (clave única) y se reintentan con espera creciente.

**Tareas programadas** (scheduler, hora de cada condominio)

| Tarea | Frecuencia | Módulo |
| --- | --- | --- |
| Generar cuotas del mes (un job por condominio) | Día configurado, 02:00 | Finanzas |
| Recargos por mora y recordatorios de vencimiento | Diaria, 07:00 | Finanzas |
| Actualizar marca `en_mora` y restricciones (Decreto 462: aviso, 5 días, levantar en 24 h) | Cada hora | Finanzas / Reservas |
| Cancelar reservas impagas 24 h antes y vencer solicitudes de 48 h | Cada 15 min | Reservas |
| Recordatorio de paquetes con más de 7 días | Diaria, 09:00 | Garita |
| Anonimizar datos de visitantes vencidos (retención LOPDP) | Diaria, 03:00 | Garita |
| Borrar subidas a S3 no confirmadas | Diaria, 04:00 | Core |
| Aviso de contratos de arriendo por vencer (30 días) | Diaria, 08:00 | Unidades |
| Generar cuentas por pagar de gastos recurrentes | Diaria, 06:00 | Finanzas |
| Aviso de facturas de proveedor por vencer (3 días) y pendientes de aprobación | Diaria, 08:00 | Finanzas |
| Activar cambios de cuenta bancaria de proveedor cumplidas las 24 h | Cada 15 min | Finanzas |
| Revocar accesos de contador vencidos (acceso\_hasta) | Diaria, 00:05 | Core/Privacy |
| Aviso de fin de periodo de cargos de directiva (30 días y el día) y marcar prorrogados | Diaria, 08:00 | Unidades |
| Terminar mantenimientos de amenidades vencidos y avisar | Diaria, 06:00 | Unidades / Reservas |

Todas usan `withoutOverlapping()` y `onOneServer()`.

**Eventos y tiempo real (Reverb)**

- Los eventos de dominio (`PagoAprobado`, `ReservaConfirmada`, `VisitaIngreso`, `VotacionAbierta`) se emiten después del commit (`ShouldDispatchAfterCommit`), así nunca se notifica algo que luego se revierte.
- Los que interesan al frontend implementan `ShouldBroadcast` en canales privados `condominio.{id}`, `unidad.{id}` y `asamblea.{id}`, autorizados en `routes/channels.php` con la pertenencia activa.
- El payload solo lleva ids y tipo de cambio; el frontend vuelve a pedir los datos con permisos.

## Archivos, PDFs, Excel y notificaciones

**Archivos en S3 (us-east-1)**

- `POST /archivos/subida` devuelve una URL prefirmada de 5 minutos (`Storage::temporaryUploadUrl`) con ruta `condominios/{id}/{modulo}/{uuid}.{ext}`; `POST /archivos/{id}/confirmar` valida tipo y tamaño reales y registra el archivo.
- Job de procesado: convierte a WEBP, genera miniatura de 400 px y elimina EXIF (GPS).
- Descargas con `Storage::temporaryUrl` de 10 minutos tras pasar la Policy.

**PDFs** (spatie/laravel-pdf con plantillas Blade)

| Documento | Cuándo | Contenido clave |
| --- | --- | --- |
| Recibo de pago | Al aprobar un pago | N.º secuencial por condominio, RUC y dirección del condominio, pagador y cédula, cuotas pagadas, monto |
| Estado de cuenta | A pedido | Cargos, pagos y saldo por unidad en un rango |
| Acta de asamblea | Al cerrar | Asistencia, quórum, poderes, resultados, resoluciones |
| Constancia de convocatoria | A pedido | Lista de unidades con fecha de lectura |

La numeración de recibos y actas usa una tabla de secuencias por condominio con bloqueo de fila, para que no haya números repetidos ni saltos.

**Excel (OpenSpout)**

- Importaciones por streaming (miles de filas sin agotar memoria), validación de cada fila y reporte de errores descargable antes de confirmar; nada se guarda si el usuario no confirma.
- Lectores de estados de cuenta: interfaz `EstadoCuentaParser` con una clase por banco (Pichincha, Produbanco, Internacional); se construyen con archivos de ejemplo reales anonimizados.
- Exportaciones para el contador en la cola `reports`, con aviso cuando el archivo está listo.

**Notificaciones**

- Sistema de Notifications de Laravel con canales `database` (bandeja en la app), `fcm` (push) y `mail`.
- Preferencias por usuario; visitas y paquetes no se pueden silenciar.
- Textos en español con plantillas por evento; ningún push incluye cédulas ni montos de deuda en la vista previa de la pantalla bloqueada.

**Apariencia por condominio**

- `Unidades\Actions\ActualizarMarcaAction` (permiso `condominio.editar`, también super admin): valida formato hex, calcula el contraste con blanco y, si es menor a 4,5:1, guarda `color_primario_ajustado` oscurecido hasta cumplir; los logos se procesan con el servicio de archivos (máx. 1 MB, se generan versiones de 64 y 256 px).
- `ColorService` en `Core/Support` hace el cálculo de contraste y los tonos; lo usan la API, los PDF (`spatie/laravel-pdf` recibe el color y el logo en la plantilla) y los correos (layout Blade con el color del condominio).
- Endpoint: `PATCH /condominio/marca`; el valor viaja en `GET /me` para el frontend.

## Fase 6: cobro de la plataforma en el backend

El módulo `app/Modules/Suscripciones` es de **nivel plataforma**: sus tablas no usan el trait `BelongsToCondominio` ni Row Level Security, porque registran la relación entre la plataforma y cada condominio. Sus modelos extienden `PlatformModel` y una prueba de arquitectura impide usarlos fuera de este módulo y de `Core/Subscriptions`.

```
app/Modules/Suscripciones/
  Http/Controllers/
    Platform/           PlanController, SuscripcionController, FacturaController,
                        PagoPlataformaController, CobranzaController, ConfiguracionController
    MiSuscripcion/      MiSuscripcionController (vista del condominio)
  Actions/              CrearSuscripcion, CambiarPlan, CambiarValorUnidad,
                        CambiarTotalUnidades, EmitirFacturaMensual, RegistrarPagoPlataforma,
                        AprobarPagoPlataforma, EmitirNotaCredito, ActualizarEstadoSuscripcion,
                        CambiarCuentaBancaria
  Services/
    TarifaService.php                    total = total_unidades × valor por unidad, mínimo, IVA
    FacturacionElectronicaService.php    interfaz; adaptador del proveedor o firma propia ante el SRI
    AplicacionPagoPlataformaService.php  aplica pagos a facturas más antiguas, abonos, saldo a favor
    CuentaCorrienteService.php           movimientos debe/haber y saldo por condominio
    EstadoSuscripcionService.php         activa → aviso → solo lectura → suspendida → reactivada
  Models/               Plan, Suscripcion, SuscripcionPrecio, CorteUnidades, FacturaPlataforma,
                        PagoPlataforma, NotaCreditoPlataforma, MovimientoCuentaCondominio,
                        CuentaBancariaPlataforma, DatosPlataforma
  Jobs/                 EmitirFacturaCondominio, ProcesarRespuestaSri, ImportarEstadoCuentaPlataforma
  Notifications/        FacturaEmitida, RecordatorioPago, CambioDeEstadoSuscripcion,
                        CuentaBancariaCambiada
  routes.php            /platform/* (roles de plataforma) y /mi-suscripcion/* (condominio)
```

**Cómo se conecta con el resto del sistema**

| Punto | Mecanismo |
| --- | --- |
| Módulos por plan | Middleware `ModuloIncluido:reservas` en cada grupo de rutas; responde 403 si el plan del condominio activo no incluye el módulo. `GET /me` devuelve los módulos activos |
| Solo lectura y suspensión | Middleware `SuscripcionActiva`; en solo lectura deja pasar lecturas, exportaciones, garita y pagos de residentes, y responde **402** a lo demás |
| Límite de unidades | La Action `CrearUnidad` (y la importación) llama al contrato `LimiteUnidades` de `Core/Subscriptions`, que lee `total_unidades` con bloqueo de fila y lanza `LimiteUnidadesAlcanzado` (409) |
| Conciliación de la cuenta de la plataforma | Reutiliza los lectores de `Core/Banking`, igual que Finanzas |
| Numeración de facturas | `SequenceService` con secuencia por establecimiento y punto de emisión |
| Jobs de otros módulos | El scheduler salta condominios suspendidos y módulos no incluidos |

**Tareas programadas nuevas**

| Tarea | Frecuencia |
| --- | --- |
| Emitir facturas del día de corte (un job por condominio, idempotente por periodo) | Diaria, 03:00 |
| Reintentar facturas pendientes de autorización del SRI | Cada 30 min |
| Recordatorios de vencimiento y actualizar estados (aviso, solo lectura, suspendida) | Diaria, 06:00 |
| Activar cambios de cuenta bancaria cumplidas las 24 horas | Cada hora |
| Fin de pruebas gratuitas | Diaria, 06:00 |

**Seguridad**

- Roles de plataforma: `super_admin`, `cobranza` (registra y aprueba pagos) y `contador_plataforma` (solo lectura y exportación).
- `CambiarCuentaBancaria` exige contraseña + código de un solo uso, notifica a todos los super admin y deja el cambio pendiente 24 horas.
- Credenciales de facturación cifradas con el cast `encrypted` y guardadas en Secrets Manager en producción.
- El condominio solo ve lo suyo en `/mi-suscripcion`, filtrado por el condominio activo.

**Pruebas obligatorias:** facturación idempotente por periodo, cálculo con mínimo y descuento anual, cambio de valor con vigencia, aplicación de abonos y saldo a favor, transición de estados por días de vencimiento, límite de unidades con cargas simultáneas y bloqueo de módulos no incluidos.

## Límite de usuarios administrativos y controles del contador

**Límite por plan (2 · 3 · 4).** Vive en `Core/Subscriptions` junto a `LimiteUnidades`, con la misma forma:

```php
// Core/Subscriptions/Contracts/LimiteUsuariosAdmin.php
interface LimiteUsuariosAdmin
{
    public function maximo(int $condominioId): int;      // plan.max_usuarios_admin + extra
    public function usados(int $condominioId): int;      // personas distintas activas con administrador|tesorero|contador
    public function asegurarCupo(int $condominioId, User $user): void; // lanza LimiteUsuariosAdminException (422)
}
```

- `Unidades\Actions\AsignarRolAction` llama a `asegurarCupo()` dentro de la transacción con `lockForUpdate` sobre la suscripción, para que dos asignaciones simultáneas no pasen el límite.
- Si el usuario ya tiene otro rol administrativo en ese condominio, no consume cupo.
- Error `422` con código `LIMITE_USUARIOS_ADMIN` y `meta: {maximo, usados}`; el frontend muestra el aviso y el botón "Subir de plan".
- Bajar de plan nunca desactiva usuarios; solo bloquea nuevas asignaciones.

**Controles del contador** (módulo `Core/Privacy`):

| Pieza | Qué hace |
| --- | --- |
| Middleware `AcuerdoAceptado` | En rutas con rol contador, sin acuerdo vigente responde `403 ACUERDO_PENDIENTE` y el frontend muestra el acuerdo |
| Middleware `AccesoVigente` | Rechaza si `condominio_user.acceso_hasta` ya pasó; la tarea diaria `RevocarAccesosVencidos` desactiva el rol y notifica |
| Middleware `RegistrarLectura` | En rutas `finanzas.*` GET guarda en `registros_acceso` (asíncrono, cola `audit`) recurso, filtros, filas, IP |
| `MaskedResource` | Trait para Resources: si el usuario no tiene `residentes.ver_datos`, enmascara cédula, teléfono y correo |
| `ExportarReporteJob` | Genera el Excel/PDF con marca (nombre, fecha, código), lo sube a S3 y devuelve URL firmada de 15 min; registra `exportar` |
| Rate limiter `exportaciones` | 30 por día por usuario y condominio; al superar, `429` y aviso al administrador |
| 2FA | `Auth\Services\SegundoFactorService`; obligatorio si el usuario tiene rol contador en cualquier condominio |

- `registros_acceso` es solo inserción: el usuario `safic_app` tiene `INSERT` y `SELECT`, sin `UPDATE` ni `DELETE`; tiene RLS por condominio como el resto.
- Endpoint: `GET /condominio/usuarios/{id}/bitacora` (permiso `usuarios.auditar`, solo administrador).
- Pruebas obligatorias: contador sin acuerdo recibe 403; datos enmascarados en cada Resource de Finanzas; exportación 31 devuelve 429; asignar un 4.º administrativo en Profesional devuelve 422.

## Pagos a proveedores y catálogo de amenidades

**Pagos a proveedores** (`app/Modules/Finanzas`). La app no mueve dinero; registra, aprueba y concilia.

| Action | Qué hace | Reglas que aplica |
| --- | --- | --- |
| `RegistrarFacturaProveedorAction` | Crea la cuenta por pagar desde el XML del SRI (`SriXmlParser` en `Core/Support`) o a mano | Clave de acceso única por condominio; proveedor se crea o actualiza por RUC |
| `AprobarGastoAction` | Registra la aprobación de nivel 1 o 2 | Quien registró no aprueba; nivel 2 solo si total > umbral y lo da presidente o vicepresidente (`SubrogacionService`) |
| `RegistrarPagoProveedorAction` | Crea el pago y sus aplicaciones | `lockForUpdate` sobre las facturas; monto ≤ saldo; abonos se aplican a la más antigua; quien aprobó no paga (configurable) |
| `AnularPagoProveedorAction` | Anula con motivo y devuelve el saldo | Solo si no está conciliado; auditado |
| `SolicitarCambioCuentaProveedorAction` | Guarda el cambio con `activa_en = now()+24h` y notifica | Exige contraseña; se puede cancelar antes de activarse |

- Permisos nuevos: `gastos.registrar`, `gastos.aprobar-n1`, `gastos.aprobar-n2`, `gastos.pagar`, `proveedores.editar`.
- El conciliador de `Core/Banking` suma una estrategia: los débitos se cruzan con `pagos_proveedor` por monto y referencia; si no hay pago, el débito queda sin identificar y se puede vincular a una factura aprobada.
- Endpoints: `POST /gastos/importar-xml`, `POST /gastos/{id}/aprobar`, `POST /gastos/{id}/rechazar`, `GET/POST /pagos-proveedor`, `POST /pagos-proveedor/{id}/anular`, `POST /proveedores/{id}/cuenta-bancaria`.

**Catálogo de amenidades** (`app/Modules/Plataforma` para los globales, `app/Modules/Unidades` para los propios y las amenidades del condominio).

| Action | Qué hace | Reglas |
| --- | --- | --- |
| `GuardarTipoAmenidadGlobalAction` | Crea o edita un tipo global (super admin) | Nombre único entre globales; si está en uso solo se desactiva |
| `CrearTipoAmenidadPropiaAction` | El administrador crea un tipo solo para su condominio | Rechaza nombres que ya existen como globales (`422 TIPO_YA_EN_CATALOGO`) o propios |
| `AgregarAmenidadAction` | Agrega amenidades al condominio desde un tipo | Reservable con cantidad N crea N registros numerados siguiendo los existentes (BBQ 3, BBQ 4); no reservable crea uno con su cantidad |
| `PromoverTipoAmenidadAction` | Convierte un tipo propio en global | El condominio conserva sus amenidades sin cambios |
| `CambiarEstadoAmenidadAction` | Disponible, mantenimiento (con fecha fin) o inactiva | En mantenimiento bloquea reservas nuevas y avisa a las afectadas |

- Endpoints: `GET/POST/PATCH /platform/tipos-amenidad`, `POST /platform/tipos-amenidad/{id}/promover`, `GET/POST /tipos-amenidad` (globales + propios), `GET/POST/PATCH /amenidades`, `POST /amenidades/{id}/estado`.
- Pruebas obligatorias: un condominio no ve tipos propios de otro; nombre duplicado con un global devuelve 422; agregar 2 BBQ reservables crea 2 registros; pago mayor al saldo devuelve 422; quien registró una factura no puede aprobarla.

## Administración de roles, permisos y menú

Vive en `app/Core/Permissions` (sincronización, caché y reglas) y en `app/Modules/Plataforma` (pantallas del super admin). Los permisos nacen en el código; roles y menú se administran por pantalla.

| Action | Qué hace | Reglas |
| --- | --- | --- |
| `SincronizarPermisosCommand` | Crea o actualiza `permisos` y `permisos_meta` desde `PermisoEnum`; corre en cada despliegue | Nunca borra un permiso en uso: lo marca obsoleto |
| `CrearRolAction` | Solo super admin: crea un rol adicional global o limitado a condominios | Nombre único; solo permisos del ámbito condominio |
| `ActualizarPermisosRolAction` | Solo super admin: cambia permisos de cualquier rol | Si el rol pasa a ser administrativo, devuelve los condominios que quedarían sobre el límite y los bloquea para nuevas asignaciones |
| `DesactivarRolAction` / `EliminarRolAction` | Deja de ofrecer un rol o lo borra | Eliminar: `409 ROL_CON_USUARIOS` si tiene asignaciones en cualquier condominio |
| `SolicitarRolAction` | El administrador del condominio pide un rol nuevo | Crea una solicitud y notifica a la plataforma |
| `GuardarMenuItemAction` / `ReordenarMenuAction` | CRUD y orden del menú por ámbito | `ruta` debe existir en el manifiesto de rutas; `icono` obligatorio y del catálogo; ítems de sistema no se borran |

La política `RolPolicy` permite crear, editar y eliminar roles solo a `super_admin`; cualquier intento desde un condominio devuelve `403`.

**Cómo llega al frontend**

- `GET /me` devuelve permisos y `GET /me/menu` devuelve el menú ya filtrado para el usuario y el condominio activo (permiso + módulo del plan + activo), en árbol y ordenado.
- Permisos efectivos por usuario y condominio en caché de Redis (`perm:{user}:{condominio}`). Al cambiar un rol o un menú, el evento `PermisosCambiados` borra las claves afectadas y emite por Reverb `menu.actualizado` para que la sesión abierta recargue.
- El manifiesto de rutas (`routes-manifest.json`, generado en el build del frontend con nombre, título y permiso de cada página) se publica al desplegar y lo usa el backend para validar `menu_items.ruta`.

**Endpoints**

- Condominio (solo lectura): `GET /roles` (roles disponibles con sus permisos efectivos según el plan) y `POST /solicitudes-rol`.
- Plataforma: `GET/PATCH /platform/roles-plantilla/{id}`, `GET/POST/PATCH/DELETE /platform/menu/{ambito}`, `POST /platform/menu/{ambito}/orden`, `GET /platform/menu/{ambito}/vista-previa?rol=`.
- Pruebas obligatorias: un administrador de condominio recibe 403 al crear o editar un rol; un condominio Básico no puede usar permisos de Garita; un ítem con ruta inexistente devuelve 422; ocultar un ítem del menú no quita el acceso a la API ni lo da.

## Procedimiento para agregar un módulo nuevo

Compras se usa solo como ejemplo ilustrativo; no forma parte del alcance ni del plan de construcción. El módulo y sus permisos nacen en el código; quién lo tiene y quién lo ve se decide después en el panel, sin programar.

**En el código (desarrolladores, con la skill `nuevo-modulo`)**

1. Registrar el módulo en `ModuloEnum`: `COMPRAS = 'compras'`, con nombre y descripción.
2. Declarar sus permisos en `PermisoEnum` con su metadata (módulo, grupo, etiqueta, administrativo, escritura):

```php
case COMPRAS_VER = 'compras.ver';               // modulo: compras · lectura
case COMPRAS_SOLICITAR = 'compras.solicitar';   // crear requisiciones
case COMPRAS_APROBAR = 'compras.aprobar';       // administrativo
case COMPRAS_ORDENES = 'compras.ordenes';       // emitir órdenes de compra · administrativo
```

3. Crear `app/Modules/Compras` con el patrón de siempre (migraciones con RLS, Actions, Resources, pruebas de aislamiento). Las rutas llevan `ModuloIncluido:compras` y el permiso de cada acción.
4. En el frontend, `modules/compras` con sus páginas; cada ruta declara `meta: { permiso, modulo: 'compras' }` y el ícono se agrega a `icons.ts`.
5. Al desplegar: `SincronizarModulosCommand` agrega Compras al catálogo de módulos **apagado en todos los planes**, `SincronizarPermisosCommand` crea los 4 permisos **sin asignar a ningún rol**, y el build publica las rutas nuevas en el manifiesto. Ningún cliente ve nada todavía.

**En el panel (super admin, sin código)**

1. **Planes y módulos:** activar Compras en los planes que lo incluyen (ej. Completo).
2. **Roles y permisos:** aparece el grupo COMPRAS con sus 4 permisos; marcarlos por rol (ej. administrador todos, tesorero aprobar y ver, mantenimiento solicitar). Opcional: crear un rol adicional "Encargado de compras".
3. **Menú del sistema:** "Nuevo ítem" → Compras, pantalla `compras.index`, ícono `shopping_cart`, permiso `compras.ver`, módulo Compras; revisar con "Ver como" y publicar.

Resultado: en los condominios con plan Completo, los usuarios cuyos roles tienen `compras.ver` ven el ítem y entran; los demás no lo ven y la API les responde `403` o `402` (módulo no incluido).

## Logs y auditoría

Los registros se ven en dos lugares. El **super admin** (y soporte) consulta los de todos los condominios desde el panel de plataforma y los filtra por condominio, para diagnosticar un problema sin entrar a los servidores. El **administrador de cada condominio** ve los errores de su propio condominio, en una vista sin detalle técnico, para saber qué falló y pasar el código de soporte a la plataforma. Ningún condominio ve registros de otro.

**Cuatro tipos de registro, cada uno en su lugar**

| Tipo | Qué guarda | Dónde | Quién lo ve | Retención |
| --- | --- | --- | --- | --- |
| Registros del sistema | Avisos y errores (`warning` o más), fallos de jobs y de integraciones (correo, banco, SRI) | Tabla `registros_sistema` + stderr | Super admin y soporte, por condominio; el administrador, los de su condominio sin detalle técnico | 90 días |
| Eventos de seguridad | Logins fallidos, refresh reutilizado, 403 repetidos, cambios de rol, invitaciones, accesos de plataforma a un condominio | Tabla `registros_sistema`, canal `seguridad` | Super admin; el administrador, los de usuarios de su condominio y los accesos de la plataforma a su condominio | 1 año |
| Auditoría de cambios | Quién cambió qué registro, con valores antes y después | `owen-it/laravel-auditing` (tabla `audits`, con `condominio_id` y RLS) | Super admin por condominio; el administrador, los de su condominio | 5 años |
| Bitácora del contador | Cada lectura o exportación de finanzas | Tabla `registros_acceso` (solo inserción, RLS) | Administrador del condominio y super admin | 5 años |

El detalle completo de cada petición (nivel `info` y `debug`) sale solo a stderr en JSON, para el servicio de logs del servidor (CloudWatch cuando se use AWS). No se guarda en la base: sería demasiado volumen.

**Cómo se arma cada línea**

- Middleware `AsignarRequestId`: toma `X-Request-Id` o genera uno (UUID), lo devuelve en la respuesta y lo agrega al contexto de todos los logs. Los jobs guardan el `request_id` de quien los encoló y lo restauran, igual que el `condominio_id`.
- Procesador `ContextoLog` (Monolog): agrega a cada línea `request_id`, `condominio_id` (o nulo), `usuario_id`, ámbito (`condominio`, `plataforma`, `sesion`), ruta, método, estado HTTP, duración y versión desplegada.
- Procesador `EnmascararDatosPersonales`: reemplaza cédula, RUC, teléfono, correo, contraseñas, tokens y cuentas bancarias por `***` antes de escribir. Una prueba falla si un registro deja pasar uno de estos datos.
- Handler `RegistroSistemaHandler`: copia a `registros_sistema` lo que es `warning` o más, más los canales `seguridad`, `jobs` e `integraciones`. Escribe por la cola `logs`; si la base no responde, el registro queda igual en stderr (nunca se pierde ni rompe la petición).
- Sentry recibe los errores con las mismas etiquetas (`condominio_id`, `request_id`, ámbito), así un error del visor enlaza a su evento en Sentry.

**Tabla `registros_sistema` (de condominio, con lectura de plataforma)**

| Campo | Detalle |
| --- | --- |
| `id`, `ocurrido_en` | `timestamptz` en UTC; la tabla se particiona por mes |
| `condominio_id` | Nulo para eventos de plataforma o sin condominio (ej. un login fallido) |
| `nivel`, `canal` | `warning`, `error`, `critical` · `aplicacion`, `seguridad`, `jobs`, `integraciones` |
| `codigo`, `mensaje` | Código estable (ej. `GENERACION_CUOTAS_FALLO`) y mensaje ya enmascarado |
| `request_id`, `usuario_id`, `ruta`, `estado_http` | Para seguir el problema de principio a fin |
| `contexto` | `jsonb` con datos técnicos (excepción, archivo, línea, job, intento), sin datos personales |

- Tiene las tres barreras como toda tabla de condominio, con una política RLS de doble ámbito (igual que los catálogos mixtos): se lee si `condominio_id` es el del contexto **o** si la petición es de plataforma (`app.ambito = 'plataforma'`, que solo fija el middleware `plataforma`). Se escribe con `condominio_id` del contexto o nulo. El usuario de la app tiene `INSERT` y `SELECT`, sin `UPDATE`; solo la tarea de limpieza (usuario dueño) borra particiones vencidas.
- Índices: `(condominio_id, ocurrido_en desc)`, `(request_id)`, `(nivel, ocurrido_en desc)`.

**Visor en el panel de plataforma**

| Endpoint | Permiso | Qué hace |
| --- | --- | --- |
| `GET /plataforma/registros` | `plataforma.registros` | Lista paginada por cursor. Filtros: condominio (o "sin condominio"), nivel, canal, código, usuario, `request_id`, rango de fechas (máx. 31 días) y texto |
| `GET /plataforma/registros/{id}` | `plataforma.registros` | Detalle con el contexto técnico y el enlace a Sentry |
| `GET /plataforma/registros/resumen` | `plataforma.registros` | Conteo por condominio y nivel en las últimas 24 h, para ver qué condominio tiene problemas |
| `GET /plataforma/condominios/{id}/auditoria` | `plataforma.auditoria` | Cambios del condominio (auditoría), con antes y después |

- Permisos nuevos `plataforma.registros` (super admin y soporte) y `plataforma.auditoria` (super admin). Cobranza no ve registros.
- Mirar registros también deja huella: cada consulta al visor guarda un evento `seguridad` con quién consultó, qué condominio y qué filtros (LOPDP).
- Pantalla "Registros del sistema" en el menú de plataforma (filtros arriba, tabla con nivel por color, panel lateral de detalle) y una pestaña "Registros" en la cuenta de cada condominio con el filtro ya puesto.

**Vista del administrador del condominio**

| Endpoint | Permiso | Qué hace |
| --- | --- | --- |
| `GET /registros` | `registros.ver` | Errores y avisos del condominio del header, paginados. Filtros: nivel, canal, usuario, rango de fechas (máx. 31 días) |
| `GET /registros/{id}` | `registros.ver` | Detalle sin `contexto` técnico |

- Ruta de condominio (`Routes/condominio.php` del módulo): `X-Condominio-Id`, `BelongsToCondominio` y RLS. Un registro de otro condominio responde 404, como cualquier dato de condominio.
- Ve: fecha y hora en la zona del condominio, nivel, canal, mensaje en lenguaje claro (el frontend traduce el `codigo`), usuario que lo provocó, pantalla o proceso, y el **código de soporte** (`request_id`) con un botón para copiarlo.
- No ve: el `contexto` técnico (excepción, archivos, líneas), el enlace a Sentry, registros sin condominio ni los de otros condominios. Cuando el registro lo provoca alguien del equipo de la plataforma, se muestra "Equipo SAFIC" en lugar del nombre.
- Permiso nuevo `registros.ver`: por defecto para el administrador; el super admin puede dárselo a otro perfil (ej. presidente). Cuenta como permiso administrativo.
- Pantalla "Registros de errores" en Configuración, y un aviso en Inicio cuando hubo errores en las últimas 24 h.

**Alertas:** fallo en la generación de cuotas, una cola atrasada más de 10 minutos, errores 5xx sostenidos o más de 20 logins fallidos por minuto envían un correo al equipo de la plataforma (y luego a Slack), con el condominio y el `request_id`.

**Cuándo se construye:** la base (request id, contexto, enmascarado, JSON a stderr) va ahora, porque todo lo que se programe después la usa. La tabla, los dos visores (plataforma y administrador) y las alertas, en S4, junto con la auditoría y la salida a staging.

## Pruebas, infraestructura, despliegue y observabilidad

**Pruebas (Pest)**

| Tipo | Qué cubre | Obligatorio |
| --- | --- | --- |
| Unitarias | Value objects (cédula, RUC, placa, Money), cálculo de cuota, tolerancia, quórum, días hábiles | Sí |
| De Actions | Generar cuotas (idempotente), aplicar pago a cuotas completas, cerrar periodo, reservar con cruce | Sí |
| De API (Feature) | Cada endpoint: éxito, validación, permisos | Sí |
| Aislamiento | Acceso cruzado entre condominios en todas las rutas | Sí, bloquea el merge |
| Arquitectura | Controladores sin `DB::`, módulos sin dependencias cruzadas, modelos de negocio con el trait de condominio | Sí |

Las pruebas corren contra PostgreSQL real (no SQLite) para cubrir RLS y las restricciones de exclusión. Cobertura mínima de 80 % en Actions de Finanzas.

**Contenedores (Docker)**

Todo se levanta con Docker Compose. Hay tres imágenes propias: **base de datos** (PostgreSQL con el script que crea los dos usuarios), **backend** (Laravel) y **frontend** (Quasar). El backend necesita además Redis y tres procesos en segundo plano; esos procesos usan la **misma imagen del backend** con otro comando, así que se construye una sola vez.

```
safic/
  docker-compose.yml        entorno local completo
  .env                      contraseñas locales (no se sube a git)
  docker/
    postgres/init/01-roles.sh   crea safic_app sin BYPASSRLS
  api/                      repositorio Laravel + Dockerfile
  web/                      repositorio Quasar + Dockerfile
```

| Contenedor | Imagen | Puerto local | Para qué |
| --- | --- | --- | --- |
| `db` | postgres:16-alpine | 5432 | Base de datos con volumen persistente |
| `redis` | redis:7-alpine | — | Colas, caché y lista negra de JWT |
| `api` | safic/api (PHP 8.5 FPM + Nginx) | 8000 | API REST |
| `worker` | safic/api | — | `php artisan horizon` (colas) |
| `scheduler` | safic/api | — | `php artisan schedule:work` (tareas programadas) |
| `reverb` | safic/api | 8080 | `php artisan reverb:start` (tiempo real) |
| `web` | safic/web | 9000 | Quasar en modo desarrollo |
| `mailpit` | axllent/mailpit | 8025 | Solo local: ver los correos enviados |
| `minio` | minio/minio | 9001 | Solo local: reemplaza a S3 |

```yaml
# docker-compose.yml (local)
x-api: &api
  image: safic/api:dev
  build: { context: ./api, target: dev }
  env_file: ./api/.env
  volumes: ["./api:/var/www/html"]
  depends_on:
    db: { condition: service_healthy }
    redis: { condition: service_healthy }

services:
  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: safic
      POSTGRES_USER: safic_owner
      POSTGRES_PASSWORD: ${DB_OWNER_PASSWORD}
      APP_DB_PASSWORD: ${DB_APP_PASSWORD}
    volumes:
      - db_data:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    ports: ["5432:5432"]
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U safic_owner -d safic"]
      interval: 5s
      retries: 10

  redis:
    image: redis:7-alpine
    healthcheck: { test: ["CMD", "redis-cli", "ping"], interval: 5s }

  api:
    <<: *api
    ports: ["8000:8080"]

  worker:
    <<: *api
    command: php artisan horizon

  scheduler:
    <<: *api
    command: php artisan schedule:work

  reverb:
    <<: *api
    command: php artisan reverb:start --host=0.0.0.0 --port=8080
    ports: ["8080:8080"]

  web:
    build: { context: ./web, target: dev }
    volumes: ["./web:/app", "/app/node_modules"]
    ports: ["9000:9000"]
    command: npx quasar dev --hostname 0.0.0.0

  mailpit:
    image: axllent/mailpit
    ports: ["8025:8025"]

  minio:
    image: minio/minio
    command: server /data --console-address ":9001"
    ports: ["9001:9001"]
    volumes: ["minio_data:/data"]

volumes:
  db_data:
  minio_data:
```

**Dockerfile del backend** (multi-etapa): `base` con PHP 8.5 FPM, Nginx y las extensiones `pdo_pgsql`, `redis`, `intl`, `gd`, `zip`, `bcmath`, `pcntl`; `dev` agrega Xdebug y monta el código por volumen; `prod` copia el código, corre `composer install --no-dev --optimize-autoloader` y `php artisan optimize`, y ejecuta como usuario sin privilegios.

**Reglas**

- Dentro de Docker el backend usa `DB_HOST=db` y `REDIS_HOST=redis`; nunca `localhost`.
- Migraciones: `docker compose exec api php artisan migrate` (usa la conexión `pgsql_owner`); la app corre con `safic_app`.
- Primer arranque: `docker compose up -d`, luego `migrate --seed` para cargar roles, permisos, catálogos y el condominio de demostración.
- Las contraseñas van en `.env` locales; en la nube salen de Secrets Manager.
- **Producción:** las mismas imágenes `api` y `web` (etapa `prod`) se publican en Amazon ECR y corren en ECS Fargate. La base de datos y Redis **no** van en contenedor en producción: se usan RDS y ElastiCache por los respaldos automáticos, la alta disponibilidad y los parches. Con Docker, ECS es la opción natural frente a Forge.

**Infraestructura en AWS (us-east-1)**

| Componente | Servicio | Nota |
| --- | --- | --- |
| API y workers | Contenedores Docker en ECS Fargate (imágenes en ECR) | 2 tareas de API detrás de un balanceador; worker, scheduler y Reverb como servicios aparte con la misma imagen |
| Base de datos | Amazon RDS PostgreSQL 16 | Multi-AZ en producción, respaldos diarios con retención de 30 días |
| Redis | Amazon ElastiCache | Colas, caché, sesiones |
| WebSockets | Reverb en su propio contenedor | Escala horizontal con Redis |
| Archivos | S3 privado + CloudFront para logos | Versionado activado |
| Correo | Amazon SES |  |
| Secretos | AWS Secrets Manager | Credenciales de BD, FCM, bancos |

**Ambientes y despliegue**

- `local` (Docker Compose con todos los contenedores), `staging` y `production`.
- GitHub Actions: en cada pull request, Pint, Larastan y Pest dentro del contenedor; al unir a `main`, despliegue a staging con migraciones; con un tag `v*`, despliegue a producción sin tiempo de corte (migraciones compatibles hacia atrás y luego código nuevo).
- Seeders de demostración con un condominio ficticio para staging y pruebas del equipo.

**Observabilidad**

- Sentry para errores con el `condominio_id` y el usuario como contexto (sin datos personales).
- Laravel Pulse para consultas lentas, colas y endpoints más usados.
- Logs en JSON a stderr (CloudWatch cuando se use AWS) y avisos y errores en registros\_sistema, con visor por condominio en el panel de plataforma: ver "Logs y auditoría".

**Orden de construcción sugerido**

- [ ] Proyecto base: Laravel 13, PHP 8.5, PostgreSQL, Redis, Pest, Pint, Larastan, Sail.
- [ ] `Core/Tenancy` completo con RLS y pruebas de aislamiento.
- [ ] Autenticación JWT (access + refresh rotativo), invitaciones, roles y permisos sembrados.
- [ ] Módulo Plataforma: alta de condominio con asistente.
- [ ] Módulo Unidades: CRUD, ocupantes, importación Excel, directorio del guardia.
- [ ] Archivos S3, auditoría y CI/CD hacia staging.
