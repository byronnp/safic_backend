# Staging (AWS) · cómo activar el despliegue

El workflow `.github/workflows/staging.yml` está **preparado pero apagado**. Mientras la variable `STAGING_HABILITADO` no sea `true`, en cada cambio a `main` solo construye la imagen de producción (sin publicarla) para comprobar que compila. No toca AWS.

## Qué hace cuando se enciende

1. Construye la imagen (`docker/php/Dockerfile`, etapa `prod`) y la publica en ECR con el SHA del commit como etiqueta.
2. Registra una nueva revisión de la task definition de cada servicio con esa imagen.
3. Corre las migraciones como tarea de un solo uso (`migrate --database=pgsql_owner`) y los seeders idempotentes `CatalogosSeeder`, `RolesYPermisosSeeder` y `MenuSeeder` (catálogos, permisos nuevos y menú; solo crean lo que falta, no pisan lo que el super admin ajustó). Si fallan, **no** se actualiza ningún servicio.
4. Actualiza los servicios ECS y espera a que queden estables.

## Lo que tienes que crear en AWS

| Recurso | Notas |
|---|---|
| Repositorio ECR | Mismo nombre que `ECR_REPOSITORY`. |
| Clúster ECS (Fargate) | Con servicios `api`, `worker` y `scheduler`. Cada uno con una task definition de la misma familia que su nombre; el contenedor se llama `api`. |
| RDS PostgreSQL 16 | Los roles `safic_owner` y `safic_app` como en `docker/postgres/init/01-roles.sh`. |
| Redis (ElastiCache) | Cola y caché. |
| Bucket S3 privado | Variables `AWS_BUCKET`; en AWS real `AWS_ENDPOINT` y `AWS_TEMPORARY_URL_ENDPOINT` van vacíos. El rol de la tarea necesita `s3:GetObject`, `PutObject`, `DeleteObject` y `ListBucket` solo sobre ese bucket. |
| Secrets Manager | `APP_KEY`, llaves JWT, contraseñas de `safic_app` y `safic_owner`. Las task definitions los leen como secretos del contenedor, no como texto. |
| Rol IAM para GitHub (OIDC) | Confía en `token.actions.githubusercontent.com` para `repo:byronnp/safic_backend:environment:staging`. Permisos: ECR (push), ECS (`RegisterTaskDefinition`, `RunTask`, `UpdateService`, `Describe*`) e `iam:PassRole` de los roles de las tareas. |

## Lo que tienes que configurar en GitHub

En **Settings → Environments → `staging`** (conviene exigir aprobación manual):

| Variable (`vars`) | Ejemplo |
|---|---|
| `STAGING_HABILITADO` | `true` (el interruptor) |
| `AWS_REGION` | `us-east-1` |
| `AWS_ROLE_STAGING` | `arn:aws:iam::<cuenta>:role/safic-staging-github` |
| `ECR_REPOSITORY` | `safic-api` |
| `ECS_CLUSTER` | `safic-staging` |
| `ECS_SERVICES` | `api,worker,scheduler` |
| `ECS_SUBREDES` | `subnet-aaa,subnet-bbb` (privadas, para la tarea de migración) |
| `ECS_GRUPO_SEGURIDAD` | `sg-xxxx` (con acceso a RDS) |

No hay secretos de AWS en GitHub: el acceso es por OIDC.

## Probarlo antes de encender

- `make` no cambia. La imagen se puede construir en local: `docker build -f docker/php/Dockerfile --target prod -t safic/api:prueba .`
- Para un ensayo, ejecuta el workflow a mano (**Actions → Staging → Run workflow**) con `STAGING_HABILITADO` en `false`: solo corre el job `imagen`.

## Pendiente (no incluido)

- Dominio, HTTPS y balanceador (ALB) delante de `api`.
- Alertas y visor de registros (S4).
- Rollback automático: hoy se vuelve a la revisión anterior de la task definition desde la consola de ECS.
