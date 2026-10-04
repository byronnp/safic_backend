# SAFIC · Backend

API de **SAFIC — Sistema de Administración Financiera de Condominios**. Laravel 13 · PHP 8.5 · PostgreSQL 16 · Redis · Docker.

Este esqueleto corresponde al **Sprint 0 (base técnica)** del plan de construcción:

- Separación entre condominios con tres barreras: middleware, scope de Eloquent y Row Level Security en PostgreSQL.
- Login con JWT (access 15 min RS256) y refresh token rotativo con detección de reutilización.
- Roles y permisos por condominio (spatie/laravel-permission con teams).
- Formato común de respuestas y errores.
- Primer módulo de ejemplo: **bloques** del módulo Unidades.
- Pruebas de aislamiento y de autenticación (Pest), CI en GitHub Actions.

## Requisitos
- Windows con **WSL 2** (Ubuntu) y **Docker Desktop** con integración WSL activada.
- Git en WSL. El proyecto debe estar dentro de WSL (no en `C:\`) para que Docker sea rápido.
- Un **S3 externo** corriendo en tu Docker (este proyecto no levanta uno). En `.env` pon sus credenciales y `AWS_ENDPOINT=http://host.docker.internal:<puerto>`, y crea el bucket de `AWS_BUCKET`.

## Primer arranque
```bash
cd ~/proyectos/proyectos_laravel
git clone https://github.com/byronnp/safic_backend.git
cd safic_backend
cp .env.example .env          # ajusta UID/GID (id -u ; id -g) y los datos AWS_* de tu S3
make setup                    # levanta contenedores, instala dependencias, llaves JWT, migra y carga datos demo
```

La primera vez `composer install` resuelve las versiones exactas de los paquetes y crea `composer.lock`: **haz commit de `composer.lock`**.

## Ubicaciones del Ecuador (INEC)
`make setup` siembra las 24 provincias. Los cantones y parroquias salen del archivo oficial de la
División Político-Administrativa del INEC (Clasificador Geográfico Estadístico): guárdalo como CSV
con las columnas `DPA_PROVIN, DPA_DESPRO, DPA_CANTON, DPA_DESCAN, DPA_PARROQ, DPA_DESPAR` en
`storage/app/dpa.csv` y corre:

```bash
docker compose exec api php artisan safic:importar-dpa storage/app/dpa.csv
```

Se puede correr de nuevo cuando el INEC publique cambios: actualiza por código y no duplica.

## Servicios locales
| Servicio | URL |
| --- | --- |
| API | http://localhost:8000/api/v1 |
| Salud | http://localhost:8000/up |
| Correos (Mailpit) | http://localhost:8025 |
| PostgreSQL | `localhost:5432` · base `safic` · usuario `safic_app` |

## Usuarios de demostración
Contraseña de todos: `Safic2026!`

| Correo | Rol |
| --- | --- |
| admin@safic.ec | Super admin de la plataforma |
| maria@jardinesdelvalle.ec | Administradora de Jardines del Valle (principal) y Los Arupos |
| diego@correo.ec | Residente de Jardines del Valle |

## Probar la API
```bash
# 1. Iniciar sesión
curl -s -c cookies.txt -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"maria@jardinesdelvalle.ec","password":"Safic2026!"}'

# 2. Con el access_token y el id del condominio (data.usuario.condominios[].id)
curl -s http://localhost:8000/api/v1/bloques \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" -H 'X-Condominio-Id: 1'
```

| Método | Ruta | Descripción |
| --- | --- | --- |
| POST | /api/v1/auth/login | Access token + cookie `safic_refresh` (móvil: header `X-Client-Type: mobile` y refresh en el cuerpo) |
| POST | /api/v1/auth/refresh | Rota el refresh token y entrega un access token nuevo |
| POST | /api/v1/auth/logout | Revoca el refresh y pone el access token en lista negra |
| GET | /api/v1/auth/me | Usuario, condominios activos (para el selector) y perfil de plataforma |
| GET | /api/v1/me/contexto | Roles y permisos en el condominio del header |
| GET/POST | /api/v1/bloques | Bloques del condominio (permiso `unidades.ver` / `unidades.editar`) |
| GET | /api/v1/me/menu | Menú del perfil en el condominio del header |
| GET | /api/v1/plataforma/me/menu | Menú del perfil de plataforma |
| GET | /api/v1/plataforma/planes | Planes activos (permiso `plataforma.condominios`) |
| GET | /api/v1/plataforma/catalogos | Tipos de condominio, métodos de cobro y amenidades (`plataforma.condominios`) |
| GET | /api/v1/plataforma/ubicaciones | Provincias, cantones y parroquias del INEC (`plataforma.condominios`) |

## Comandos
| Comando | Qué hace |
| --- | --- |
| `make up` / `make down` | Levanta o detiene los contenedores |
| `make test` | Pruebas (base `safic_test`) |
| `make lint` / `make fix` | Pint + Larastan / formatea |
| `make migrate` / `make fresh` | Migra / recrea la base con datos demo |
| `make shell` | Consola dentro del contenedor |

## Arquitectura
Reglas para el equipo y para Claude en [`CLAUDE.md`](CLAUDE.md). Documentos completos en [`docs/arquitectura.md`](docs/arquitectura.md).
