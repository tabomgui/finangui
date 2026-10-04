import { QueryClient } from '@tanstack/react-query'
import { describe, expect, it } from 'vitest'
import {
  invalidateBankConnections,
  invalidateBudgets,
  invalidateCategories,
  invalidateGoals,
  invalidateImportBatchesList,
  invalidateImports,
  invalidateLedger,
  invalidateRules,
  invalidateTags,
  queryKeys,
} from './query-keys'

function seed(client: QueryClient) {
  for (const key of [
    queryKeys.accounts(false),
    queryKeys.categories(false),
    queryKeys.tags(),
    queryKeys.transactions({}),
    queryKeys.transfer('abc'),
    queryKeys.transferSuggestions(),
    queryKeys.dashboard('2026-10'),
    queryKeys.cards(false),
    queryKeys.card(1),
    queryKeys.cardStatements(1),
    queryKeys.statementPreview(1, '2026-10-10'),
    queryKeys.installmentPlans(1),
    queryKeys.rules(),
    queryKeys.rulePreview({ match: 'all' }),
    queryKeys.importBatches(),
    queryKeys.importBatch(1),
    queryKeys.bankConnections(),
    queryKeys.budgets('2026-10'),
    queryKeys.goals(),
    queryKeys.goalContributions(1),
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

  it('invalidateLedger invalida transações, transferências, sugestões de transferência, contas, dashboard, cartões e a prévia de regra', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateLedger(client)
    expect(invalidated(client)).toEqual([
      'accounts',
      'bank-connections',
      'budgets',
      'card-statements',
      'cards',
      'dashboard',
      'goals',
      'installment-plans',
      'rule-preview',
      'transactions',
      'transfer-suggestions',
      'transfers',
    ])
  })

  it('invalidateCategories invalida categorias, transações, dashboard, parcelamentos e a prévia de regra', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateCategories(client)
    expect(invalidated(client)).toEqual(['categories', 'dashboard', 'installment-plans', 'rule-preview', 'transactions'])
  })

  it('invalidateTags invalida tags, transações e a prévia de regra', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateTags(client)
    expect(invalidated(client)).toEqual(['rule-preview', 'tags', 'transactions'])
  })

  it('invalidateRules invalida só as regras', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateRules(client)
    expect(invalidated(client)).toEqual(['rules'])
  })

  it('invalidateImports invalida só os lotes de importação', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateImports(client)
    expect(invalidated(client)).toEqual(['import-batches'])
  })

  it('invalidateImportBatchesList invalida a listagem, nunca o detalhe de um lote', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateImportBatchesList(client)

    const cache = client.getQueryCache()
    expect(cache.find({ queryKey: queryKeys.importBatches() })?.state.isInvalidated).toBe(true)
    expect(cache.find({ queryKey: queryKeys.importBatch(1) })?.state.isInvalidated).toBe(false)
  })

  it('invalidateBankConnections invalida só as conexões bancárias', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateBankConnections(client)
    expect(invalidated(client)).toEqual(['bank-connections'])
  })

  it('invalidateBudgets invalida só o orçamento, nunca transações/contas/etc.', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateBudgets(client)
    expect(invalidated(client)).toEqual(['budgets'])
  })

  it('invalidateGoals invalida metas e seus aportes (mesmo prefixo), nunca o resto do razão', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateGoals(client)
    expect(invalidated(client)).toEqual(['goals'])
  })
})
