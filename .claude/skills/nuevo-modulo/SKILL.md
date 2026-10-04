---
name: nuevo-modulo
description: Crea o amplía un módulo de la API de SAFIC (tabla de condominio, modelo, endpoint, permiso, pruebas y contrato) con el patrón del proyecto. Úsala al agregar una entidad o ruta nueva en app/Modules.
---

# Nuevo módulo o entidad en SAFIC (backend)

Sigue `CLAUDE.md`. El ejemplo vivo es **Bloques** (`app/Modules/Unidades`): cópialo, no inventes otro patrón.
Trabaja en modo plan primero: lista archivos, migraciones, permisos y pruebas, y espera el visto bueno.

## Antes de escribir código
1. Lee la sección del documento de la fase (enlaces en `docs/arquitectura.md`) y la pantalla en "Campos por pantalla".
2. Decide si la tabla es **de condominio** (casi siempre) o **de plataforma** (planes, condominios, suscripciones: sin RLS ni trait).
3. Define el permiso: ¿existe en `App\Core\Permissions\Permiso`? Si no, agrégalo ahí (nunca desde pantallas) y su asignación por defecto en `Rol::permisosPorDefecto()`. Si cuenta para el límite de administrativos, inclúyelo en `Permiso::esAdministrativo()`.
4. Escribe primero las pruebas que fallan (paso 8) con las reglas del documento.

## Archivos (módulo `<M>`, entidad `<E>`, tabla `<es>`)

1. **Migración** `database/migrations/AAAA_MM_DD_HHMMSS_create_<es>_table.php` — una sola tabla, con `down()`:
   - `foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete()`.
   - Todo índice único **empieza por `condominio_id`**: `$table->unique(['condominio_id', 'codigo'])`.
   - Dinero `decimal('monto', 12, 2)`; nunca `float`. Fechas en `timestampTz` (UTC).
   - Termina con `RowLevelSecurity::enable('<es>')`; `down()` llama a `RowLevelSecurity::disable` antes del `dropIfExists`.
   - Se corre con `php artisan migrate --database=pgsql_owner`.
2. **Modelo** `app/Modules/<M>/Models/<E>.php`: `use BelongsToCondominio, HasFactory;`, `$fillable` **sin** `condominio_id`, `casts()` (`'monto' => 'decimal:2'`), PHPDoc `@property`.
3. **Factory** `database/factories/<E>Factory.php` (se usa dentro de `enCondominio(...)`).
4. **FormRequest** `Http/Requests/Guardar<E>Request.php`: `authorize()` devuelve `true` (el permiso va en la ruta). Toda regla `unique`/`exists` se filtra con `->where('condominio_id', app(TenantContext::class)->require())`.
5. **Action** `Actions/Crear<E>Action.php` (`final`, `execute(array $datos)`, dentro de `DB::transaction`). Reglas de negocio aquí o en un Service; errores con `throw new ApiException('CODIGO_ESTABLE', 'Mensaje corto en español.', 422)`.
6. **Resource** `Http/Resources/<E>Resource.php`: solo campos públicos; dinero como string `"125.50"`; fechas ISO 8601. Datos personales enmascarados si el usuario no tiene `residentes.ver_datos`.
7. **Controller** delgado y **rutas** en el archivo del ámbito: `app/Modules/<M>/Routes/condominio.php` (rutas del condominio) o `app/Modules/<M>/Routes/plataforma.php` (panel del super admin, prefijo `/plataforma`), cada una con `->middleware('permission:'.Permiso::X->value)`. `routes/api.php` los carga solos: no lo edites. Respuestas siempre con `ApiResponse` (`ok`, `created`, `paginated`).
8. **Pruebas** (Pest):
   - Aislamiento en `tests/Feature/Tenancy/` (o un archivo `<M>AislamientoTest.php` ahí): lista solo lo del condominio del header; no ve ni modifica por id lo de otro condominio (404); RLS filtra con `DB::table` y bloquea escribir `condominio_id` ajeno; unique repetido en otro condominio sí se permite.
   - Permisos en `tests/Feature/<M>/`: un rol sin el permiso recibe 403 `SIN_PERMISO`; un rol con él pasa.
   - Reglas de negocio: un caso por regla del documento (ej. `LIMITE_UNIDADES`).
9. **Contrato** `docs/openapi.yaml`: cada operación nueva con `x-permiso`, parámetro `CondominioId`, esquemas en `components/schemas` y respuestas de error (`CondominioRequerido`, `NoAutenticado`, `SinPermisoOCondominio`, `Validacion`). `ContratoOpenApiTest` falla si falta.

## Cerrar
- `make fix`, `make lint`, `make test` y `npx @redocly/cli@2.57.0 lint docs/openapi.yaml` sin errores.
- Pide al subagente `revisor-seguridad` que revise el diff.
- Si cambió una decisión de arquitectura, actualiza el documento de la fase y `docs/arquitectura.md`.
