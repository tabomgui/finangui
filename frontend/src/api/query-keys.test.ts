import { QueryClient } from '@tanstack/react-query'
import { describe, expect, it } from 'vitest'
import { invalidateCategories, invalidateLedger, invalidateTags, queryKeys } from './query-keys'

function seed(client: QueryClient) {
  for (const key of [
    queryKeys.accounts(false),
    queryKeys.categories(false),
    queryKeys.tags(),
    queryKeys.transactions({}),
    queryKeys.transfer('abc'),
    queryKeys.dashboard('2026-10'),
  ]) {
    client.setQueryData(key, 'x')
  }
}

function invalidated(client: QueryClient) {
  return client
    .getQueryCache()
    .getAll()
    .filter((query) => query.state.isInvalidated)
    .map((query) => query.queryKey[0])
    .sort()
}

describe('query keys', () => {
  it('normaliza filtros vazios na chave de transações', () => {
    expect(queryKeys.transactions({ search: '', account_id: undefined })).toEqual(['transactions', 'list', {}])
  })

  it('invalidateLedger invalida transações, transferências, contas e dashboard', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateLedger(client)
    expect(invalidated(client)).toEqual(['accounts', 'dashboard', 'transactions', 'transfers'])
  })

  it('invalidateCategories invalida categorias, transações e dashboard', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateCategories(client)
    expect(invalidated(client)).toEqual(['categories', 'dashboard', 'transactions'])
  })

  it('invalidateTags invalida tags e transações', async () => {
    const client = new QueryClient()
    seed(client)
    await invalidateTags(client)
    expect(invalidated(client)).toEqual(['tags', 'transactions'])
  })
})
