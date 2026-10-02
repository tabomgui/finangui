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

## Frontend

- React 19 + Vite + TS, Tailwind 4, shadcn/ui (new-york/neutral), TanStack Query, react-router (data router), react-hook-form + zod.
- Cliente da API: `src/api/client.ts` (`openapi-fetch`) tipado por `src/api/schema.d.ts`, gerado por `make types`. Nunca editar o schema à mão; regenerar depois de mudar a API.
- Use `unwrap`/`expectOk` para chamadas; erros viram `ApiError` (status, code, fieldErrors). Em formulários, `applyFieldErrors` + `notifyError`.
- `toApiError` só confia na `message` do corpo em 422 (validação) ou quando vem `code` (erro de domínio nosso); fora isso usa mensagem padrão em português. Validação do backend já vem em pt_BR (`backend/lang/pt_BR`, `laravel-lang/common` como dependência de dev).
- Hooks de dados ficam em `src/api/queries/<domínio>.ts`. Telas em `src/features/<domínio>/`. Layout em `src/components/layout`, peças reutilizáveis em `src/components/shared`.
- Toda página usa `PageHeader` (faixa esmeralda) + `PageBody` (conteúdo sobreposto). Cards: `rounded-2xl shadow-card`.
- Dinheiro sempre em centavos: `MoneyText` para exibir, `MoneyInput` para editar, `lib/money.ts` para formatar/parsear. Datas da API são "YYYY-MM-DD": usar `lib/date.ts` (nunca `new Date('YYYY-MM-DD')`).
- Cores de valor: `text-income` / `text-expense`. Ícones de categoria: `CategoryIcon`, que resolve pelo mapa curado em `src/lib/category-icons.ts` (imports estáticos do `lucide-react`; nunca `lucide-react/dynamic`, que explode o bundle). Ícone novo entra nesse mapa.
- `Field` recebe exatamente um elemento filho e já cuida de `aria-describedby`/`aria-invalid`.
- shadcn/ui: CLI fixada em `4.21.1`. O componente gerado por `npx shadcn@4.21.1 add <x>` importa `cn` do pacote npm "cn" (a CLI adiciona essa dependência) — troque o import para `from "@/lib/utils"` e remova a dependência "cn". O `tsconfig.json` da raiz tem os `paths` que fazem o arquivo cair em `src/components/ui`.
- `package.json` tem `overrides` fixando o peer `typescript` do `openapi-typescript` na nossa versão do TS; não usar `--legacy-peer-deps`.
- Testes: Vitest + Testing Library, `globals` desligado (importe `describe`/`it`/`expect`); `src/test/setup.ts` registra a limpeza (`cleanup`) entre testes.
- Nenhuma regra de negócio no frontend.
- Antes de commit: `make front-check`.

## UI

- Sem emoji na interface. Ícones sempre do lucide (`lucide-react`); categorias guardam o nome do ícone lucide.
- shadcn/ui estilo new-york, base neutral.

## Fluxo de trabalho

- TDD com Pest. `make test` e `make lint` antes de cada commit.
- Specs e planos ficam em `docs/` (ignorado pelo git, local).
- O Securo (AGPL) serve de inspiração de ideias; não copie código dele.
- Repositório público: nunca commite dados bancários reais.
