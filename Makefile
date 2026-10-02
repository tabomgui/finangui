DC = docker compose
EXEC = $(DC) exec backend

.PHONY: up down logs sh art test lint fix fresh openapi types front-check

up: ; $(DC) up -d --build
down: ; $(DC) down
logs: ; $(DC) logs -f backend worker scheduler
sh: ; $(EXEC) sh
art: ; $(EXEC) php artisan $(c)
test: ; $(EXEC) ./vendor/bin/pest $(f)
lint: ; $(EXEC) ./vendor/bin/pint --test && $(EXEC) ./vendor/bin/phpstan analyse --memory-limit=1G
fix: ; $(EXEC) ./vendor/bin/pint
fresh: ; $(EXEC) php artisan migrate:fresh --seed
openapi: ; $(EXEC) php artisan scramble:export --path=storage/app/openapi.json
types: ; $(MAKE) openapi && cd frontend && npm run types
front-check: ; cd frontend && npm run lint && npm run typecheck && npm test
