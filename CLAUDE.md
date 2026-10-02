# finangui — convenções do projeto

Gerenciador financeiro pessoal. Backend Laravel 13 API + Postgres em `backend/`; frontend React/Vite/TS em `frontend/`. Tudo em Docker.

## Backend

- Pastas por domínio em `app/Domain/<Domínio>/{Models,Actions,Data,Enums,Queries,Errors,Support}`.
- Controllers finos (`app/Http/Controllers/Api/V1`): FormRequest valida, Action executa regra de negócio, JsonResource responde. CRUD trivial pode usar Eloquent direto no controller; qualquer regra vai para uma Action.
- Todo model de domínio usa `App\Models\Concerns\BelongsToUser`. Toda rota de recurso fica atrás de `auth:sanctum`. `tests/Feature/RouteAuthenticationTest.php` garante isso automaticamente para toda rota `api/*`, exceto as da allowlist no próprio teste — rota pública nova precisa entrar nessa lista.
- Regras `exists`/`unique` que referenciam recursos do usuário são escopadas por `user_id`.
- Dinheiro: `bigint` em centavos, `App\Support\Money\Money` + `MoneyCast`. `amount` sempre positivo; sentido em `direction` (`in`/`out`). Nunca float.
- Valores derivados (saldos, totais) são calculados no backend, nunca gravados e nunca calculados no frontend.
- Model com coluna que tem default no banco (migration) declara o mesmo default em `protected $attributes`, para o valor já aparecer certo em memória logo depois de `create()`, sem precisar de `refresh()`. Ver `Account`, `Category`, `Transaction`.
- Models usam `casts()` (método) em vez da propriedade `$casts`; `phpstan.neon` tem `parseModelCastsMethod: true` para o Larastan enxergar os tipos vindos de lá (ex.: enums).
- Erros de regra de negócio: subclasse de `App\Domain\Shared\DomainError` → HTTP 409 `{code, message}`. `DomainError` está em `dontReport` (`bootstrap/app.php`): não poluir os logs com algo que é fluxo normal de negócio.
- Projeto novo: altere migrations só enquanto não houver deploy; depois disso, sempre migration nova.
- `make lint` roda Larastan em nível 6. Quando o apontamento é falso positivo e justificado, use `@phpstan-ignore <id> (motivo)` na linha, nunca um baseline.
- Worker de dev usa `queue:listen` (reflete mudança de código sem reiniciar o container); não usar `queue:work` no Docker Compose de desenvolvimento.

## UI

- Sem emoji na interface. Ícones sempre do lucide (`lucide-react`); categorias guardam o nome do ícone lucide.
- shadcn/ui estilo new-york, base neutral.

## Fluxo de trabalho

- TDD com Pest. `make test` e `make lint` antes de cada commit.
- Specs e planos em `docs/superpowers/`.
- O Securo (AGPL) serve de inspiração de ideias; não copie código dele.
- Repositório público: nunca commite dados bancários reais.
