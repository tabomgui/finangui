# finangui — convenções do projeto

Gerenciador financeiro pessoal. Backend Laravel 13 API + Postgres em `backend/`; frontend React/Vite/TS em `frontend/`. Tudo em Docker.

## Backend

- Pastas por domínio em `app/Domain/<Domínio>/{Models,Actions,Data,Enums,Queries,Errors,Support}`.
- Controllers finos (`app/Http/Controllers/Api/V1`): FormRequest valida, Action executa regra de negócio, JsonResource responde. CRUD trivial pode usar Eloquent direto no controller; qualquer regra vai para uma Action.
- Todo model de domínio usa `App\Models\Concerns\BelongsToUser`. O global scope falha fechado: sem usuário autenticado lança `MissingUserContext`. Fora de request (job, comando, scheduler), rode o trabalho dentro de `App\Support\UserContext::run($user, fn () => ...)`; acesso global legítimo (seeders, factories, importação) usa `withoutGlobalScopes()` explicitamente. Qualquer código enfileirado/agendado (jobs, listeners, notifications, closures do scheduler) que toque model de domínio também precisa rodar dentro de `UserContext::run()`. Jobs em `app/Domain/*/Jobs` precisam usar `UserContext::run(` (arch test). Toda rota de recurso fica atrás de `auth:sanctum`. `tests/Feature/RouteAuthenticationTest.php` garante isso automaticamente para toda rota `api/*`, exceto as da allowlist no próprio teste — rota pública nova precisa entrar nessa lista.
- Regras `exists`/`unique` que referenciam recursos do usuário são escopadas por `user_id`.
- Dinheiro: `bigint` em centavos, `App\Support\Money\Money` + `MoneyCast`. `amount` sempre positivo; sentido em `direction` (`in`/`out`). Nunca float.
- Valores derivados (saldos, totais) são calculados no backend, nunca gravados e nunca calculados no frontend.
- Model com coluna que tem default no banco (migration) declara o mesmo default em `protected $attributes`, para o valor já aparecer certo em memória logo depois de `create()`, sem precisar de `refresh()`. Ver `Account`, `Category`, `Transaction`.
- Models usam `casts()` (método) em vez da propriedade `$casts`; `phpstan.neon` tem `parseModelCastsMethod: true` para o Larastan enxergar os tipos vindos de lá (ex.: enums).
- Erros de regra de negócio: subclasse de `App\Domain\Shared\DomainError` → HTTP 409 `{code, message}`. `DomainError` está em `dontReport` (`bootstrap/app.php`): não poluir os logs com algo que é fluxo normal de negócio.
- Projeto novo: altere migrations só enquanto não houver deploy; depois disso, sempre migration nova.
- `make lint` roda Larastan em nível 6. Quando o apontamento é falso positivo e justificado, use `@phpstan-ignore <id> (motivo)` na linha, nunca um baseline.
- Worker de dev usa `queue:listen` (reflete mudança de código sem reiniciar o container); não usar `queue:work` no Docker Compose de desenvolvimento.
- Cartão de crédito é uma conta `credit_card`. A fatura de qualquer transação de cartão é decidida só por `App\Domain\Cards\Actions\AssignStatement` (criar/editar transação e transferência chamam ela); datas nominais vêm de `InvoiceCycle` (puro) e faturas já gravadas têm prioridade (`StatementResolver`). Total, pago, restante, status e limite são derivados (`CardStatement::scopeWithTotals`, `Account::scopeWithCardUsage`), nunca gravados.
- Pagar fatura é transferência (`PayStatement`), nunca despesa. Parcelas: conta/valor/data/tipo travados; excluir uma parcela exclui o parcelamento; cancelar exclui só as projetadas.
- Regras de categorização: núcleo puro e sem banco em `app/Domain/Rules/Support` (`TextNormalizer`, `RuleMatcher` — limita `pcre.backtrack_limit` ao avaliar `regex`, contra ReDoS —, `RuleEngine`, `RuleDefinitionValidator`, `RuleDefinitionCleaner`). Toda escrita do resultado de uma regra numa transação passa por `App\Domain\Rules\Actions\ApplyRuleOutcome` (prévia e aplicação retroativa reaproveitam a mesma instância, que memoiza categoria/tag). Pernas de transferência (`transfer_id` não nulo) nunca são tocadas por regra nem por histórico. `categorized_by` guarda `manual`, `history`, `pluggy` ou `rule:{id}`. O hook em `Transaction::booted()` mantém `description_key` ao salvar pelo model — uma atualização em massa de `description` fora desse caminho (query builder direto, sem `save()`) precisa atualizar `description_key` (`TextNormalizer::key()`) manualmente, senão o histórico desalinha.
- Condição de regra sobre `amount` vê o valor de cada parcela (a transação do parcelamento), nunca o total da compra original.
- Domínio `Imports`: parsers puros (`Parsers/*`) devolvem `ParsedRow`; `FormatDetector::parse()` é o único ponto de entrada — normaliza o conteúdo bruto uma única vez, detecta (ou usa) o formato e já parseia. `IngestionPlanner` é a única fonte das decisões de dedup (cascata: duplicada/atualizada por `external_id`, parcela, adoção de lançamento manual, troca de id de pending) e roda tanto na prévia (`Queries/ImportPreview`) quanto dentro de `Actions/IngestTransactions`, sob trava da conta e do próprio lote — os dois caminhos nunca podem mostrar números diferentes. `IngestTransactions` fecha o lote numa única transação (`stats`, `undo`, `created_statement_ids`). `Actions/RevertImportBatch` só reverte o lote `completed` mais recente de cada conta, e sobrescreve com o valor salvo no `undo` qualquer edição feita depois da importação nos campos restaurados. `Support/SyntheticId` cobre formatos sem id próprio e também um id dado pelo banco que se repete dentro do mesmo arquivo. Parcela é casada por plano + número em qualquer status, nunca só `posted` (a primeira parcela de uma compra manual já nasce lançada). `HistoryCategorizer::suggestMany()` resolve a sugestão por histórico do lote inteiro de uma vez, nunca por transação. O limite de upload que vale é o da validação (`StoreImportBatchRequest`, 2 MB); `docker/php/conf.d/uploads.ini` e o `client_max_body_size` do nginx do frontend só precisam ficar acima disso. Fixtures em `tests/Fixtures/imports` são inventadas (repositório público).
- Resposta de API: nunca tipar uma propriedade só como `null` — o `Readable<T>` do `openapi-fetch` remove do tipo do cliente qualquer chave cujo tipo seja exatamente `null` (`NonNullable<null>` vira `never`). Quando o valor pode estar ausente, omita a chave em vez de mandar `null` (ex.: `rule_id` em `TransactionResource` só aparece quando `source` é `'rule'`).

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
- Chaves de query só em `src/api/query-keys.ts`. Mutações que mexem em saldo chamam `invalidateLedger`; categorias, `invalidateCategories`; tags, `invalidateTags`.
- Telas de feature carregam sob demanda: rotas usam `lazyPage(() => import(...), 'NomeDaPagina')` em `router.tsx`.
- `Field` aceita render-prop `(control) => ...` para controles compostos (Select, combobox): espalhe `control` no gatilho.
- Seletores prontos em `components/shared`: `AccountSelect`, `CategoryPicker`, `TagPicker`, `ColorPicker`, `IconPicker`.
- Filtros de transações vivem na URL (`features/transactions/filters.ts`, nomes curtos em português: `conta`, `categoria`, `tag`, `de`, `ate`, `tipo`, `busca`).
- Seletores com busca (`CategoryPicker`, `TagPicker`) usam um filtro do cmdk que ignora acentuação (`lib/search.ts`, `accentInsensitiveFilter`); os itens do `Command` levam `value={String(id)}` e `keywords={[nome]}`.
- `CategoryPicker` abre o `Popover` com `modal`: necessário para o scroll da lista funcionar quando o picker fica dentro de um `Sheet` (o scroll-lock do Sheet senão intercepta a roda do mouse).
- Formulário em diálogo reseta ao abrir: `useForm` recebe `defaultValues` fixo e um `useEffect(() => { if (open) form.reset(defaultsFor(...)) }, [open, ...])`; nunca usar a prop `values` do react-hook-form (ela resetaria o form a cada render, inclusive durante a digitação).
- Para ler um campo só para exibição/condicional (sem re-render de tudo), use `useWatch`, nunca `form.watch` (que não é reativo do jeito que o React espera aqui).
- Campo opcional com zod `.nullable().refine(...)`: o tipo de entrada do form (`z.input<typeof schema>`) ainda tem `| null`, só o de saída (`z.output`) não — tipar o form com `z.input`, não com o tipo inferido default (`z.output`).
- Listas com filtro que troca de página/categoria usam `placeholderData: keepPreviousData` no `useQuery`/`useInfiniteQuery`, e a UI esmaece com `isPlaceholderData` enquanto a próxima página carrega, pra evitar flash de vazio.
- Toda página com dados mostra um estado de erro com botão "Tentar de novo" (refetch), não só um alerta.
- Qualquer superfície no topo do `PageBody` fica sobre a faixa esmeralda do `PageHeader`: precisa ser opaca (ex.: `bg-card` no input), senão a faixa verde aparece atrás.
- `CardHeader` do shadcn é um grid (pensado pra `CardAction`); quando o conteúdo é só título + algo à direita em linha, declare `flex flex-row items-center justify-between` explicitamente na própria instância.
- jsdom não implementa `ResizeObserver`, `scrollIntoView` nem a Pointer Capture API (`hasPointerCapture`/`setPointerCapture`/`releasePointerCapture`), usados por componentes radix-ui/cmdk (ex.: `Select` precisa da Pointer Capture API no trigger); os stubs ficam em `src/test/setup.ts`, não devem ser duplicados em testes individuais.
- Antes de commit: `make front-check`.
- Cartões em `features/cards`. Status e "vence em N dias" vêm prontos da API (`status`, `days_until_due`); `statement-labels.ts` só traduz para texto.
- Linha de transação: subtítulo montado por `transactionSubtitle`; o tipo de um lançamento em edição sai de `editKind` (entry/transfer/installment).
- Regras em `features/rules` (lista arrastável com `@dnd-kit`, editor, prévia ao vivo). `OPERATORS_BY_FIELD` (`rule-labels.ts`) é só configuração de formulário — quais operadores oferecer por campo —, não regra de negócio: o backend valida de novo na gravação.
- Importação de extrato em `features/imports`. Upload é multipart: `openapi-fetch` devolve o `FormData` como está quando o corpo já é uma instância dela (ver `useUploadStatement` em `api/queries/imports.ts`), sem precisar de `bodySerializer` nem tocar em `client.ts`. A resposta do upload já traz a prévia calculada e pré-popula o cache do detalhe (`queryKeys.importBatch`), para a tela de prévia abrir sem recalcular.
- `applyFieldErrors` aceita `'*'` no lugar da lista de campos para formulários com caminhos dinâmicos/aninhados (ex.: `conditions.0.value`); o quarto argumento (`isCoverable`) diz quais caminhos têm de fato um campo visível na tela, para só cair no toast genérico quando nenhum erro aplicado apareceu em lugar nenhum (ver `rule-editor-page.tsx`).
- `TooltipProvider` fica uma única vez na raiz (`main.tsx`), não por componente; teste que renderiza um componente com `Tooltip` isolado precisa envolver manualmente com `TooltipProvider` (ver `categorization-badge.test.tsx`).

## UI

- Sem emoji na interface. Ícones sempre do lucide (`lucide-react`); categorias guardam o nome do ícone lucide.
- shadcn/ui estilo new-york, base neutral.

## Fluxo de trabalho

- TDD com Pest. `make test` e `make lint` antes de cada commit.
- Specs e planos ficam em `docs/` (ignorado pelo git, local).
- O Securo (AGPL) serve de inspiração de ideias; não copie código dele.
- Repositório público: nunca commite dados bancários reais.
