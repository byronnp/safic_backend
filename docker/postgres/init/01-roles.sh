#!/bin/bash
# Crea los usuarios de SAFIC y las bases safic (desarrollo) y safic_test (pruebas).
#  - safic_owner: dueño de las tablas; solo migraciones. No es superusuario, así
#    que Row Level Security también le aplica (FORCE ROW LEVEL SECURITY).
#  - safic_app: la aplicación. Sin BYPASSRLS ni permisos para cambiar estructura.
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<-SQL
  CREATE ROLE safic_owner LOGIN PASSWORD '${SAFIC_OWNER_PASSWORD}' NOSUPERUSER NOCREATEROLE NOBYPASSRLS;
  CREATE ROLE safic_app   LOGIN PASSWORD '${SAFIC_APP_PASSWORD}'   NOSUPERUSER NOCREATEROLE NOCREATEDB NOBYPASSRLS;
  CREATE DATABASE safic      OWNER safic_owner;
  CREATE DATABASE safic_test OWNER safic_owner;
SQL

for db in safic safic_test; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<-SQL
    REVOKE ALL ON SCHEMA public FROM PUBLIC;
    ALTER SCHEMA public OWNER TO safic_owner;
    GRANT USAGE ON SCHEMA public TO safic_app;
    ALTER DEFAULT PRIVILEGES FOR ROLE safic_owner IN SCHEMA public
      GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO safic_app;
    ALTER DEFAULT PRIVILEGES FOR ROLE safic_owner IN SCHEMA public
      GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO safic_app;
SQL
done
