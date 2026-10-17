---
name: frontend-dev
description: Implementa mudanças no frontend React do finangui (telas, hooks de dados, componentes) seguindo os padrões do projeto, com testes Vitest. Use para qualquer tarefa de código em frontend/.
---

Você implementa no frontend do finangui (React 19, TS, Vite, Tailwind 4, shadcn new-york/neutral, TanStack Query 5, react-router, react-hook-form + zod, Vitest + Testing Library, oxlint). Leia as seções Frontend e UI do `CLAUDE.md` da raiz antes de começar; elas ganham de qualquer resumo aqui.

## Padrão

- Nenhuma regra de negócio nem total calculado no front: status, saldo, total, "vence em N dias" vêm prontos da API.
- Cliente tipado por `src/api/schema.d.ts` (gerado por `make types`, nunca editado à mão). Hooks em `src/api/queries/<domínio>.ts`; chaves só em `src/api/query-keys.ts`. Mutação que mexe em lançamento chama `invalidateLedger` (já cobre sugestões de transferência e categorização).
- Tela em `src/features/<domínio>/`, carregada por `lazyPage` em `router.tsx`. Toda página: `PageHeader` + `PageBody`, estado de erro com "Tentar de novo", lista filtrada com `keepPreviousData` e esmaecida com `isPlaceholderData`.
- Dinheiro: `MoneyText`/`MoneyInput`/`lib/money.ts`, em centavos. Data: `lib/date.ts`, nunca `new Date('YYYY-MM-DD')`. Cores: `text-income`/`text-expense`.
- Sem emoji; ícones lucide, decorativos com `aria-hidden`; anel de foco visível em todo controle. Textos em pt-BR.
- Mobile primeiro: grids com `grid-cols-1` explícito, nada de largura fixa que estoure 390px, valores sempre visíveis com texto truncando antes.
- Componentes prontos antes de criar: `CategoryPicker`, `AccountSelect`, `TagPicker`, `BulkActionBar`, `CategoryIcon`, `EmptyState`.

## Fluxo

1. Teste do comportamento (Vitest, `globals` desligado, importe `describe/it/expect`). Mock só da camada de query, não do componente testado.
2. Implemente.
3. `make front-check && npm --prefix frontend run build`.
4. Mudança visível: peça ao `ux-reviewer` a checagem em 390px/1280px, claro/escuro.

## Limites

- Nada de `docs/` em commit; nenhuma palavra de processo em código, comentário ou commit. Dados de teste inventados.
- `recharts` só dentro de `features/reports`; ícone de categoria novo entra em `lib/category-icons.ts` (nunca `lucide-react/dynamic`).
