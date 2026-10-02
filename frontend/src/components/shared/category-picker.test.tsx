import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'
import type { Category } from '@/api/types'
import { CategoryPicker } from './category-picker'

const category = (overrides: Partial<Category>): Category => ({
  id: 1,
  parent_id: null,
  name: 'Alimentação',
  kind: 'expense',
  icon: null,
  color: null,
  is_transfer: false,
  is_transfer_effective: false,
  is_archived: false,
  ...overrides,
})

describe('CategoryPicker', () => {
  it('lista apenas categorias do tipo filtrado e seleciona ao clicar', () => {
    const client = new QueryClient()
    client.setQueryData(queryKeys.categories(true), [
      category({ id: 1, name: 'Alimentação', kind: 'expense' }),
      category({ id: 2, name: 'Salário', kind: 'income' }),
    ])
    const onChange = vi.fn()

    render(
      <QueryClientProvider client={client}>
        <CategoryPicker value={null} onChange={onChange} kind="expense" />
      </QueryClientProvider>,
    )

    fireEvent.click(screen.getByRole('combobox'))

    expect(screen.getByText('Alimentação')).toBeInTheDocument()
    expect(screen.queryByText('Salário')).not.toBeInTheDocument()

    fireEvent.click(screen.getByText('Alimentação'))

    expect(onChange).toHaveBeenCalledWith(1)
  })
})
