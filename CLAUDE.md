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
3. **Row Level Security**: la migración termina con `RowLevelSecurity::enable('tabla')`.

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

## Seguridad
- Nunca subir `.env`, llaves JWT (`storage/jwt/*.pem`) ni datos reales de residentes.
- Datos personales (cédula, teléfono, correo) se enmascaran para roles sin `residentes.ver_datos`.
- No agregar paquetes sin justificarlo en el pull request.
