DC = docker compose
EXEC = $(DC) exec backend

.PHONY: up down logs sh art test lint fix fresh

up: ; $(DC) up -d --build
down: ; $(DC) down
logs: ; $(DC) logs -f backend worker scheduler
sh: ; $(EXEC) sh
art: ; $(EXEC) php artisan $(c)
test: ; $(EXEC) ./vendor/bin/pest $(f)
lint: ; $(EXEC) ./vendor/bin/pint --test && $(EXEC) ./vendor/bin/phpstan analyse --memory-limit=1G
fix: ; $(EXEC) ./vendor/bin/pint
fresh: ; $(EXEC) php artisan migrate:fresh --seed
