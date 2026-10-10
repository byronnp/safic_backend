# SAFIC · Documentos de arquitectura

Los documentos vivos están en Claude (privados; compártelos con el equipo desde el menú Compartir).
Copias en Markdown dentro del repo, para que Claude Code las lea sin conexión:

- [`docs/arquitectura/backend.md`](arquitectura/backend.md) — Arquitectura backend (Laravel), exportada el 9-oct-2026.


| Documento | Enlace |
| --- | --- |
| Fase 1 · Arquitectura multi-condominio | https://claude.ai/code/artifact/30aa5014-1fec-438c-9ca7-0fb72f9950bf |
| Fase 2 · Finanzas | https://claude.ai/code/artifact/357937c0-03df-4745-b5f3-7e9168e18a5d |
| Fase 3 · Áreas comunes | https://claude.ai/code/artifact/b09d6469-795e-4501-ab2c-ee617670198d |
| Fase 4 · Seguridad y comunicación | https://claude.ai/code/artifact/ef6d8b2f-97b0-405d-b6f8-a53007b2efa6 |
| Fase 5 · Asambleas y votaciones | https://claude.ai/code/artifact/5bd426f4-6aec-4cdb-9a55-4ba5d14a39ba |
| Fase 6 · Cobro de la plataforma | https://claude.ai/code/artifact/8f5a9b2a-fd22-4578-9838-0e1e6b2f70b1 |
| Arquitectura backend (Laravel) | https://claude.ai/code/artifact/d830ac1f-ab1d-4d2d-8c0e-1e1f52de9adc |
| Arquitectura frontend (Quasar) | https://claude.ai/code/artifact/e2d6df92-c495-46c9-b6fe-7c8a8a58b49a |
| Campos por pantalla | https://claude.ai/code/artifact/625fe66d-4ef1-4a02-b029-3a745b6dd40f |
| Plan de construcción | https://claude.ai/code/artifact/5b860c89-3ee3-41ed-be31-ce5ecc908dae |
| Mockups de pantallas | https://claude.ai/artifact/Y7EAqdBNNMZ92M856HfevN |

Plan: Sprint 0 (este esqueleto) → S1 alta de condominio → S2 unidades y residentes → S3 usuarios, cargos y amenidades → S4 residente y staging → piloto.

## Decisiones recientes

### Perfil de plataforma al iniciar sesión (3-oct-2026)

- Los roles de plataforma (super admin, soporte, cobranza, contador de plataforma) viven en el equipo `0` de spatie y **no** tienen membresía en condominios.
- `POST /auth/login`, `POST /auth/refresh` y `GET /auth/me` devuelven `usuario.plataforma = { roles, permisos }` o `null`.
- El frontend decide la entrada por perfil: solo plataforma → `/plataforma` (primera pantalla que permitan sus permisos); con condominios → selector o condominio principal; con ambos → puede cambiar de ámbito desde el menú de usuario.
- Las rutas de plataforma (`meta.plataforma`) se validan con los permisos de plataforma, nunca con los del condominio. Los permisos de plataforma no se mezclan con `/me/contexto`.
- El super admin **no** entra a un condominio por el header sin membresía (`CONDOMINIO_NO_PERMITIDO`). Las rutas `/api/v1/plataforma/*` llevarán su propio middleware que fija el equipo `0` (pendiente, con el módulo Plataforma).

### Menú y pantallas por perfil (S1, semana 1)

- El menú es un catálogo global del super admin: `menu_items` (ámbito `condominio` o `plataforma`, ícono `sym_r_*` obligatorio, permiso por hoja) y `menu_item_rol` (qué perfiles ven cada hoja). Se eligió **asignación manual por perfil** porque las pantallas y menús están definidos por perfil.
- Una hoja se muestra si está asignada a un perfil del usuario en el equipo activo **y** el usuario tiene su permiso; los grupos solo si les queda alguna hoja. La API sigue exigiendo el permiso en cada ruta.
- `GET /me/menu` (condominio del header) y `GET /plataforma/me/menu` (equipo 0). El frontend usa el mismo formato `ItemMenu`; en desarrollo suma las pantallas en vista previa del menú local.
- `MenuSeeder` solo crea pantallas con API y asigna perfiles a los ítems nuevos según los permisos por defecto de cada rol; no pisa cambios del super admin.
- Rutas `/api/v1/plataforma/*`: middleware `plataforma` (equipo 0); cada una con su permiso de plataforma.

### Reconciliación de S1 (3-oct-2026)

S1 se programó en dos ramas paralelas (`s1/semana-1` y `s1/alta-condominio`) y el merge dejó `main` roto (dos `ResolvePlataforma`, tablas `planes`/`amenidades_catalogo` creadas dos veces, rutas del alta perdidas). Decisión:
- Se queda el **alta completa** de `s1/alta-condominio`: tablas `ubicacion_*` (INEC con coordenadas, desde `database/data`), `planes` con `codigo`/`limite_administrativos`, amenidades, cobro, invitación y sus rutas.
- Se queda el **menú por perfil** de `s1/semana-1` (`menu_items`, `menu_item_rol`, `/me/menu`, `/plataforma/me/menu`) y su middleware `App\Core\Tenancy\Http\ResolvePlataforma`.
- Se retiran `/plataforma/catalogos`, `/plataforma/ubicaciones`, `safic:importar-dpa` y las migraciones `2026_10_12_000100…000500`: duplicaban `/plataforma/amenidades`, `/ubicaciones` y el catálogo INEC ya sembrado.

### Rutas por módulo (3 oct 2026)

- Cada módulo declara sus rutas en su carpeta `Routes/`, un archivo por ámbito:
  - `Routes/condominio.php` → `auth:api` + `condominio` + `throttle:api`, con `X-Condominio-Id`. Cada ruta con su permiso.
  - `Routes/plataforma.php` → `auth:api` + `plataforma` (equipo 0), prefijo `/plataforma`. Cada ruta con su permiso de plataforma.
  - `Routes/sesion.php` → `auth:api`, sin condominio: solo catálogos compartidos (ej. `GET /ubicaciones`).
  - Un módulo con pantallas en varios ámbitos (ej. Suscripciones: "Mi suscripción" y la cobranza del super admin) usa varios archivos.
- `routes/api.php` solo declara los grupos y las rutas transversales (auth, `/me/*`) y carga los archivos de los módulos solo; crear un módulo no obliga a editarlo.
- `RutasPorModuloTest` verifica la convención, el grupo y el permiso; `ContratoOpenApiTest`, que cada ruta esté en el contrato.


### Logs y auditoría por condominio (4 oct 2026)

- El super admin y soporte consultan los registros de todos los condominios desde el panel de plataforma, filtrados por condominio. El **administrador de cada condominio** ve los errores de su propio condominio, sin detalle técnico, con el código de soporte (`request_id`) para pasarlo a la plataforma. Ningún condominio ve registros de otro.
- Cuatro tipos: **registros del sistema** (`warning`+, jobs e integraciones; tabla `registros_sistema`, 90 días), **eventos de seguridad** (canal `seguridad`, 1 año), **auditoría de cambios** (`laravel-auditing`, con `condominio_id` y RLS, 5 años) y **bitácora del contador** (`registros_acceso`, 5 años). El detalle `info`/`debug` va solo a stderr en JSON.
- Cada línea lleva `request_id` (middleware `AsignarRequestId`, también en jobs), `condominio_id`, `usuario_id`, ámbito y ruta. `EnmascararDatosPersonales` quita cédula, RUC, teléfono, correo, contraseñas, tokens y cuentas antes de escribir.
- `registros_sistema` tiene `condominio_id`, `BelongsToCondominio` y RLS de doble ámbito: se lee si es del condominio del contexto o si la petición es de plataforma (`app.ambito = 'plataforma'`, que solo fija el middleware `plataforma`). Particionada por mes; la app inserta por la cola `logs` y, si la base falla, el registro queda en stderr.
- Visor: `GET /plataforma/registros`, `/plataforma/registros/{id}`, `/plataforma/registros/resumen` (permiso `plataforma.registros`: super admin y soporte) y `GET /plataforma/condominios/{id}/auditoria` (`plataforma.auditoria`: super admin). Cada consulta al visor queda como evento de seguridad.
- Vista del administrador: `GET /registros` y `GET /registros/{id}` (ruta de condominio, permiso `registros.ver`, por defecto del administrador). Sin `contexto` técnico ni enlace a Sentry; las acciones del equipo de la plataforma se muestran como "Equipo SAFIC". Pantalla "Registros de errores" en Configuración.
- Se construye en dos partes: la base (request id, contexto, enmascarado, JSON a stderr) ahora; la tabla, los dos visores y las alertas en S4.
