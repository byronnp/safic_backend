# SAFIC · Backend (Laravel) — reglas para Claude y el equipo

SAFIC = Sistema de Administración Financiera de Condominios. SaaS multi-condominio para Ecuador.
Este repositorio es **solo la API** (`/api/v1`). El frontend (Quasar) vive en otro repositorio.
La arquitectura completa está en `docs/arquitectura.md` (enlaces a los documentos por fase).

## Stack
- PHP 8.5 · Laravel 13 · PostgreSQL 16 · Redis · Docker Compose (local) · AWS (ECS, RDS, S3, SES).
- Auth: JWT (`php-open-source-saver/jwt-auth`), access RS256 de 15 min + refresh rotativo de 30 días.
- Permisos: `spatie/laravel-permission` con teams = condominio. Pruebas: Pest. Estilo: Pint. Análisis: Larastan nivel 5 (subir a 6 cuando el código base esté estable).

## Comandos (siempre dentro de Docker, desde WSL)
- `make up` · `make setup` (primer arranque) · `make test` · `make lint` · `make fix` · `make shell`
- Migraciones: `php artisan migrate --database=pgsql_owner` (nunca con la conexión de la app).
- Pruebas: `php vendor/bin/pest` (usan la base `safic_test`).

## Estructura
```
app/Core/         Transversal: Tenancy, Auth, Http (ApiResponse, errores), Permissions, Console
app/Modules/<M>/  Un módulo por área: Models, Actions, Http/{Controllers,Requests,Resources}, Routes/
routes/api.php    Solo grupos y rutas transversales (auth, /me); carga solas las Routes/ de cada módulo
database/         migrations (una por tabla), factories, seeders
tests/Feature/    Pruebas de API por módulo · tests/Feature/Tenancy: aislamiento obligatorio
```
Módulos previstos: Plataforma, Unidades, Finanzas, Amenidades, Reservas, Garita, Comunicacion, Asambleas, Suscripciones.

## Capas (flujo de una petición)
FormRequest (valida) → Controller (delgado) → Action (caso de uso + transacción) → Services (lógica reutilizable) → Models → Resource + `ApiResponse`.
- Un controlador nunca tiene lógica de negocio ni hace `response()->json()`: usa `App\Core\Http\Responses\ApiResponse`.
- Errores de negocio: `throw new ApiException('CODIGO_ESTABLE', 'Mensaje en español', 422)`.
- Un módulo no llama a los modelos de otro módulo directamente: usa sus Actions públicas o eventos.

## Rutas por módulo
- Cada módulo tiene sus propios archivos de rutas, uno por ámbito, en `app/Modules/<M>/Routes/`:
  - `condominio.php`: grupo `auth:api` + `condominio` (header `X-Condominio-Id`). Cada ruta con su permiso.
  - `plataforma.php`: grupo `auth:api` + `plataforma` (equipo 0), prefijo `/plataforma`. Cada ruta con su permiso de plataforma.
  - `sesion.php`: grupo `auth:api`, sin condominio. Solo catálogos compartidos que no exponen datos de un condominio.
- `routes/api.php` los carga solos (orden alfabético) y solo declara los grupos y las rutas transversales (auth, `/me/*`): no se edita al crear un módulo.
- `tests/Feature/Contrato/RutasPorModuloTest.php` falla si un archivo está fuera de la convención, si una ruta de condominio o plataforma no tiene permiso o si está en el grupo equivocado.

## Multi-condominio (NO NEGOCIABLE)
Tres barreras; las tres son obligatorias en toda tabla con datos de un condominio:
1. **Middleware `condominio`** (`ResolveCondominio`): toma `X-Condominio-Id`, valida la membresía activa y fija el contexto. Toda ruta de negocio va dentro de `auth:api` + `condominio`.
2. **Trait `BelongsToCondominio`** en el modelo: filtra por el condominio activo y completa `condominio_id` al crear. Sin condominio activo no devuelve filas.
3. **Row Level Security**: la migración termina con `RowLevelSecurity::enable('tabla')`. El condominio se pasa a PostgreSQL con `SET LOCAL` (vive solo en la transacción): el middleware abre una transacción por petición (se confirma si la respuesta es < 400 y se deshace si no) y `TenantContext::run()` abre la suya. Fijar el condominio fuera de una transacción lanza `LogicException`.

Reglas:
- Toda tabla de condominio tiene `condominio_id` (FK) y sus índices únicos empiezan por `condominio_id`.
- Validaciones `unique`/`exists` siempre filtradas por `condominio_id`.
- Jobs y comandos: `app(TenantContext::class)->run($condominioId, fn () => ...)`. Un job guarda el `condominio_id` y lo restaura.
- Prohibido `withoutGlobalScope(CondominioScope::class)` fuera del módulo Plataforma; si se usa, comentar por qué.
- Cada módulo nuevo agrega pruebas de aislamiento como `tests/Feature/Tenancy/AislamientoEntreCondominiosTest.php`.
- Las tablas de plataforma (condominios, condominio_user, planes, suscripciones…) no llevan RLS ni el trait.

## Roles y permisos
- Los **permisos nacen en el código**: `App\Core\Permissions\Permiso` (enum). No se crean desde pantallas.
- Los **roles** son un catálogo global (`roles.condominio_id` nulo); solo el super admin los crea. Se asignan por condominio (`model_has_roles.condominio_id`; `0` = plataforma).
- Presidente, vicepresidente, secretario y tesorero son **cargos**: se asignan por la tabla de cargos (única persona por cargo), no con `assignRole` directo.
- Cada ruta exige su permiso: `->middleware('permission:unidades.editar')`. Ocultar un ítem del menú no es seguridad.
- Rutas del panel de plataforma: `auth:api` + `plataforma` (fija el equipo 0 de spatie) + `permission:plataforma.*`, sin `X-Condominio-Id`. Viven en `app/Modules/<M>/Routes/plataforma.php`. Si escriben datos de un condominio, lo hacen dentro de `TenantContext::run($id, ...)` y a través de las Actions públicas del módulo dueño.
- El JWT no lleva roles ni condominio.

## Convenciones
- Código (clases, métodos, tablas, columnas) en **español** para el dominio (`Condominio`, `cuotas`, `vence_en`); nombres técnicos de Laravel en inglés.
- Dinero: `decimal(12,2)` en la base, nunca `float`. Fechas en UTC; se muestran en la zona horaria del condominio.
- Códigos de error en MAYÚSCULAS_CON_GUION_BAJO y estables (el frontend los traduce).
- Mensajes al usuario en español de Ecuador, cortos y claros.
- Cada migración hace una sola cosa y tiene `down()`.
- Catálogos de plataforma (planes, amenidades, provincias/cantones/parroquias INEC) se cargan con `CatalogosSeeder`, idempotente, en cada despliegue. En pruebas: `sembrarCatalogos()` solo donde se usan.
- Cédula y RUC: reglas `App\Core\Validation\Rules\CedulaEc` y `RucEc`.

## Auditoría de cambios
- Todo modelo de condominio con datos de negocio implementa `OwenIt\Auditing\Contracts\Auditable` y usa el trait `Auditable` (hoy: módulo Unidades). Los cambios van a `audits` (modelo `App\Core\Audit\Auditoria`, con `condominio_id` y RLS).
- `audits` es de solo inserción: `safic_app` no tiene UPDATE, DELETE ni TRUNCATE. La retención (5 años) la ejecuta el dueño de la tabla.
- Datos personales fuera de la auditoría: `protected array $auditExclude = [...]` (ver `Persona`). Un modelo nuevo con cédula, teléfono, correo o cuentas debe excluirlos.
- El visor (`/plataforma/condominios/{id}/auditoria`) llega en S4.

## Archivos (S3)
- Todo archivo de un condominio se guarda con `App\Core\Storage\ArchivosCondominio` (disco `archivos`: S3 en AWS, MinIO en Docker). Ruta `condominios/{id}/{carpeta}/{uuid}.ext`; el prefijo sale del condominio activo, nunca de la petición.
- El bucket es privado: se entrega solo `urlTemporal()` (10 min). No guardar el nombre original del archivo ni usar `Storage::disk('s3')` directo.
- Variables (`.env`): `ARCHIVOS_DISK=s3`, `AWS_ENDPOINT=http://host.docker.internal:9100`, `AWS_USE_PATH_STYLE_ENDPOINT=true`, `AWS_TEMPORARY_URL_ENDPOINT=http://localhost:9100`, `AWS_BUCKET`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION=us-east-1`. El bucket se crea a mano en la consola (http://localhost:9101); este compose no levanta MinIO. En AWS real se dejan vacíos `AWS_ENDPOINT` y `AWS_TEMPORARY_URL_ENDPOINT`.
- Pruebas: `config(['filesystems.disco_archivos' => 'local'])` + `Storage::fake('local')`.

## Directorio de garita
- `GET /garita/directorio` (permiso `garita.directorio`: guardia y administrador) es la **única** ruta que entrega el teléfono sin enmascarar sin `residentes.ver_datos`. Devuelve una lista cerrada (nombre, relación, unidad, bloque, teléfono, vehículos); nunca cédula, correo ni ids de persona.
- Para que no sirva para volcar la lista de residentes: búsqueda de mínimo 2 letras o números, máximo 20 unidades y 30 consultas por minuto (`throttle:directorio`). No ampliar estos topes ni agregar campos sin revisión de seguridad.
- Los permisos nuevos no llegan a roles que ya existen (el seeder solo los da al crear el rol): una migración de datos se los da, como `2026_10_29_000100_dar_garita_directorio…`.

## Importación desde Excel
- `openspout/openspout` lee y escribe .xlsx en streaming (sin cargar el libro entero ni ejecutar fórmulas). Solo se usa dentro del módulo que importa (Unidades: `Services/LectorExcelUnidades`, `PlantillaUnidades`).
- Flujo de toda importación: vista previa sin guardar (errores por fila) → `confirmar=1` crea todo o nada en una transacción. Cada fila se valida con las mismas reglas del formulario y respeta los límites del plan. Máx. 2 MB y 500 filas.

## Marca del condominio
- Los colores (primario y acento) y los logos viven en `condominios.marca`. El cliente nunca ve la ruta interna: `App\Core\Marca\MarcaPublica` devuelve colores y enlaces `/api/v1/marca/{codigo}/logo/{claro|oscuro}`.
- Esa ruta de logo es **pública** (sale en el login, recibos y correos): solo sirve PNG validados al subirlos (máx. 1 MB, 64–2000 px). No aceptar SVG: puede traer scripts y se serviría desde nuestro dominio.
- El administrador edita nombre, contacto, ubicación y colores en `PATCH /condominio`; RUC, razón social, tipo y plan los cambia la plataforma.

## Usuarios y cupo del plan
- `usuarios.gestionar` abre `/usuarios`: el administrador suma, cambia de perfil, vence y desactiva a su equipo. Perfiles asignables: `Rol::asignables()` (administrador, contador, guardia, mantenimiento); los cargos de directiva van por su tabla y `PerfilesUsuario` nunca los pierde al cambiar de perfil.
- Cupo de usuarios administrativos (`planes.limite_administrativos`): `Rol::cuentaParaCupo()` (administrador, contador, tesorero). Siempre con `LimiteUsuarios::asegurarCupo()` dentro de la transacción (409 `LIMITE_USUARIOS`). Una invitación pendiente reserva su lugar.
- Reglas fijas: nadie cambia su propio acceso (`USUARIO_PROPIO`), el condominio no se queda sin administrador (`ULTIMO_ADMINISTRADOR`) y el contador siempre tiene `acceso_hasta`. Las personas se resuelven por membresía del condominio activo: otro condominio responde 404.
- Directiva (`cargos_directiva`, con RLS y auditoría): un cargo, una persona; una persona, un cargo (índices parciales + `AsignarCargoAction`). Solo propietarios con correo. Cambiar al titular cierra su periodo y le quita solo ese cargo. El módulo Usuarios consulta Unidades solo por sus Actions públicas (`PropietariosVigentesAction`, `ResumenPersonasAction`). Pendiente: la regla «sin mora» (Decreto 462) cuando exista Finanzas.
- Roles (`GET /roles`, solo lectura): los define la plataforma; el condominio los ve con permisos, personas y menú (`MenuService::paraPerfil`) y pide uno nuevo con `POST /roles/solicitudes` (tabla `solicitudes_rol`; aviso al correo `SAFIC_SOPORTE_EMAIL` si está configurado; 5 por hora). Las etiquetas y el grupo de cada permiso viven en `Permiso::etiqueta()` / `grupo()`: todo permiso nuevo las define o `match` falla.
- Los cambios de membresía no pasan por `audits` (tabla de plataforma, sin `condominio_id`): pendiente decidir su bitácora.

## Menú de producción
- El menú que ve cada perfil sale de `menu_items` (`MenuSeeder`). **Una pantalla entra al seeder cuando pasa de vista previa a datos reales** (en el mismo PR que la conecta), nunca antes: el frontend solo muestra las vistas previas en desarrollo. `MenuPorPerfilTest` fija la lista de rutas permitidas.
- `MenuSeeder`, `RolesYPermisosSeeder` y `CatalogosSeeder` son idempotentes (solo crean lo que falta) y corren en cada despliegue (`.github/workflows/staging.yml`). En local, tras traer cambios: `php artisan db:seed --class=MenuSeeder`.

## Amenidades del condominio
- `condominio_amenidades` (RLS + auditoría) copia los valores del catálogo al agregar. Reservable con varias unidades → registros separados numerados («Área BBQ 1», «Área BBQ 2», la numeración sigue donde iba); no reservable → un registro con su cantidad («Ascensor (3)»). Una propia no puede llamarse como una del catálogo.
- Catálogo global (super admin, `/plataforma/catalogo-amenidades`): al agregar, el condominio recibe una **copia** de los valores; editar el catálogo no altera a quien ya la tiene. Un tipo en uso solo se desactiva (`AMENIDAD_EN_USO`). Las amenidades propias de todos los condominios se leen recorriendo cada condominio en su contexto (`ResumenAmenidadesPorCondominioAction`): la plataforma nunca ve tablas con RLS sin contexto. Promover una propia crea el tipo y la vincula.
- «Desactivar» es `activa = false` (no se borra: otras fases, como Reservas, referenciarán la amenidad). El mantenimiento termina solo al pasar `mantenimiento_hasta`.

## Contrato OpenAPI
- `docs/openapi.yaml` (OpenAPI 3.1) es el contrato con el frontend. Toda ruta nueva o cambiada se documenta ahí en el mismo pull request.
- Cada operación con permiso declara `x-permiso: <permiso>`; la prueba `tests/Feature/Contrato/ContratoOpenApiTest.php` falla si una ruta falta en el contrato, si sobra una operación o si el permiso no coincide.
- Formato del archivo: rutas a 2 espacios bajo `paths:` y métodos a 4 (la prueba lo lee así).
- Lint: `npx @redocly/cli@2.57.0 lint docs/openapi.yaml` (también corre en CI).

## Definición de terminado
Migración + endpoint + Resource + pruebas (incluida la de aislamiento y la de permisos) + `make lint` sin errores + contrato OpenAPI actualizado (`docs/openapi.yaml` + su lint).

## Claude Code en este repo (`.claude/`)
- Skill `nuevo-modulo`: receta para una entidad o ruta nueva (migración con RLS → pruebas → contrato).
- Subagente `revisor-seguridad`: revisa el diff antes del PR (aislamiento, permisos, datos personales, dinero).
- Hooks: Pint formatea cada PHP editado; al terminar, si hay cambios en PHP, corren Pint --test, Larastan y Pest en Docker y un fallo se devuelve a Claude para que lo corrija. Requieren `make up`.
- `.claude/settings.local.json` es personal y no se sube.

## Staging
- `.github/workflows/staging.yml` construye la imagen de producción en cada cambio a `main`. El despliegue a AWS (ECR + ECS) está apagado hasta que `vars.STAGING_HABILITADO` sea `true`; guía y recursos necesarios en `docs/staging.md`. Sin llaves de AWS en GitHub: acceso por OIDC.

## Seguridad
- Nunca subir `.env`, llaves JWT (`storage/jwt/*.pem`) ni datos reales de residentes.
- Datos personales (cédula, teléfono, correo) se enmascaran para roles sin `residentes.ver_datos`.
- No agregar paquetes sin justificarlo en el pull request.
