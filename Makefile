# SAFIC backend · atajos. Ejecutar desde WSL en la carpeta del proyecto.
DC = docker compose
RUN = $(DC) exec api
APP = $(DC) exec -u www-data api

.PHONY: up down build setup install migrate fresh seed test lint fix shell logs

up:            ## Levanta todos los contenedores
	$(DC) up -d

down:          ## Detiene los contenedores
	$(DC) down

build:         ## Reconstruye la imagen de la API
	$(DC) build api

setup:         ## Primer arranque: .env, contenedores, dependencias, llaves JWT, migraciones y datos demo
	@test -f .env || cp .env.example .env
	$(DC) up -d --build
	$(APP) composer install
	$(APP) php artisan key:generate
	$(APP) php artisan safic:jwt-keys
	$(APP) php artisan migrate --database=pgsql_owner --seed --force
	$(DC) restart worker scheduler

install:       ## composer install
	$(APP) composer install

migrate:       ## Migraciones con el usuario dueño
	$(APP) php artisan migrate --database=pgsql_owner

fresh:         ## Borra y recrea la base con datos demo
	$(APP) php artisan migrate:fresh --database=pgsql_owner --seed

test:          ## Pruebas (Pest) contra la base safic_test
	$(APP) php vendor/bin/pest

lint:          ## Pint + Larastan
	$(APP) php vendor/bin/pint --test
	$(APP) php vendor/bin/phpstan analyse --memory-limit=1G

fix:           ## Formatea el código con Pint
	$(APP) php vendor/bin/pint

shell:         ## Consola dentro del contenedor
	$(APP) bash

logs:
	$(DC) logs -f api worker
