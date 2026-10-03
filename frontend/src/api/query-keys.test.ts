import { QueryClient } from '@tanstack/react-query'
import { describe, expect, it } from 'vitest'
import { invalidateCategories, invalidateLedger, invalidateRules, invalidateTags, queryKeys } from './query-keys'

function seed(client: QueryClient) {
  for (const key of [
    queryKeys.accounts(false),
    queryKeys.categories(false),
    queryKeys.tags(),
    queryKeys.transactions({}),
    queryKeys.transfer('abc'),
    queryKeys.dashboard('2026-10'),
    queryKeys.cards(false),
    queryKeys.card(1),
    queryKeys.cardStatements(1),
    queryKeys.statementPreview(1, '2026-10-10'),
    queryKeys.installmentPlans(1),
    queryKeys.rules(),
    queryKeys.rulePreview({ match: 'all' }),
  ]) {
    client.setQueryData(key, 'x')
  }
}

function invalidated(client: QueryClient) {
  const roots = client
    .getQueryCache()
    .getAll()
    .filter((query) => query.state.isInvalidated)
    .map((query) => query.queryKey[0] as string)
  return [...new Set(roots)].sort()
}

describe('query keys', () => {
  it('normaliza filtros vazios na chave de transações', () => {
    expect(queryKeys.transactions({ search: '', account_id: undefined })).toEqual(['transactions', 'list', {}])
  })

  it('invalidateLedger invalida transações, transferências, contas, dashboard, cartões e a prévia de regra', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateLedger(client)
    expect(invalidated(client)).toEqual([
      'accounts',
      'card-statements',
      'cards',
      'dashboard',
      'installment-plans',
      'rule-preview',
      'transactions',
      'transfers',
    ])
  })

  it('invalidateCategories invalida categorias, transações, dashboard e parcelamentos', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateCategories(client)
    expect(invalidated(client)).toEqual(['categories', 'dashboard', 'installment-plans', 'transactions'])
  })

  it('invalidateTags invalida tags e transações', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateTags(client)
    expect(invalidated(client)).toEqual(['tags', 'transactions'])
  })

  it('invalidateRules invalida só as regras', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateRules(client)
    expect(invalidated(client)).toEqual(['rules'])
  })
})
