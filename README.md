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

## Primer arranque
```bash
cd ~/proyectos/proyectos_laravel
git clone https://github.com/byronnp/safic_backend.git safic_back
cd safic_back
cp .env.example .env          # ajusta UID/GID con: id -u ; id -g
make setup                    # levanta contenedores, instala dependencias, llaves JWT, migra y carga datos demo
```

La primera vez `composer install` resuelve las versiones exactas de los paquetes y crea `composer.lock`: **haz commit de `composer.lock`**.

## Servicios locales
| Servicio | URL |
| --- | --- |
| API | http://localhost:8000/api/v1 |
| Salud | http://localhost:8000/up |
| Correos (Mailpit) | http://localhost:8025 |
| Archivos (MinIO) | http://localhost:9001 (usuario `safic`) |
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
| GET | /api/v1/auth/me | Usuario y condominios activos (para el selector) |
| GET | /api/v1/me/contexto | Roles y permisos en el condominio del header |
| GET/POST | /api/v1/bloques | Bloques del condominio (permiso `unidades.ver` / `unidades.editar`) |

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
