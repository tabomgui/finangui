import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'
import type { Category, Transaction } from '@/api/types'
import { EntryForm } from './entry-form'
import { entryDefaults } from './form-values'

function category(overrides: Partial<Category>): Category {
  return {
    id: 1,
    parent_id: null,
    name: 'Mercado',
    kind: 'expense',
    icon: null,
    color: null,
    is_transfer: false,
    is_transfer_effective: false,
    is_archived: false,
    ...overrides,
  }
}

const transaction = {
  id: 1,
  account_id: 3,
  date: '2026-10-01',
  amount: 1000,
  direction: 'in',
  currency: 'BRL',
  description: 'Reembolso',
  original_description: 'Reembolso',
  description_locked: false,
  notes: null,
  payee: null,
  category_id: 9,
  tags: [],
  status: 'posted',
  source: 'manual',
  categorized_by: 'manual',
  is_ignored: false,
  transfer_id: null,
} as Transaction

describe('EntryForm', () => {
  it('mantém a categoria ao editar uma receita com categoria de despesa', () => {
    const client = new QueryClient()
    client.setQueryData(queryKeys.categories(true), [category({ id: 9, name: 'Mercado', kind: 'expense' })])

    render(
      <QueryClientProvider client={client}>
        <EntryForm defaultValues={entryDefaults({ transaction })} onSubmit={vi.fn()} submitLabel="Salvar" />
      </QueryClientProvider>,
    )

    expect(screen.getByText('Mercado')).toBeInTheDocument()
  })
})
