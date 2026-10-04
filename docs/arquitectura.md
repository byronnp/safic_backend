# SAFIC · Documentos de arquitectura

Los documentos vivos están en Claude (privados; compártelos con el equipo desde el menú Compartir):

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

### Rutas por módulo (3 oct 2026)

- Cada módulo declara sus rutas en su propia carpeta `Routes/`, un archivo por ámbito:
  - `app/Modules/<Módulo>/Routes/condominio.php` → grupo `auth:api` + `condominio` (+ `throttle:api`), con `X-Condominio-Id`.
  - `app/Modules/<Módulo>/Routes/plataforma.php` → grupo `auth:api` + `plataforma`, prefijo `/plataforma` (equipo 0).
  - Un módulo con pantallas en los dos ámbitos (ej. Suscripciones: "Mi suscripción" y la cobranza del super admin) usa los dos archivos.
- `routes/api.php` solo declara los grupos y las rutas transversales (auth, `/me/*`) y carga los archivos de los módulos de forma automática. Crear un módulo no obliga a editarlo.
- Toda ruta de un módulo exige su permiso; `RutasPorModuloTest` lo verifica junto con el grupo correcto, y `ContratoOpenApiTest` que esté en el contrato.

