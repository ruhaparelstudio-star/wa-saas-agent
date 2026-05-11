up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose up -d --build

bash:
	docker compose exec app sh

test:
	docker compose exec app php artisan test

fresh:
	docker compose exec app php artisan migrate:fresh --seed

logs:
	docker compose logs -f

horizon-logs:
	docker compose logs -f horizon

tinker:
	docker compose exec app php artisan tinker

ps:
	docker compose ps
