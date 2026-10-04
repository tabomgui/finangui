import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Transaction } from '@/api/types'
import { PendingCard } from './pending-card'

const useTransferSuggestions = vi.fn()
const useOverdueOccurrences = vi.fn((): { data: Transaction[] } => ({ data: [] }))

vi.mock('@/api/queries/transfer-suggestions', () => ({
  useTransferSuggestions: () => useTransferSuggestions(),
}))

vi.mock('@/api/queries/recurrences', () => ({
  useOverdueOccurrences: () => useOverdueOccurrences(),
}))

function overdueOccurrence(id: number): Transaction {
  return {
    id,
    account_id: 1,
    account: { id: 1, name: 'Nubank', type: 'checking' as const, color: null, icon: null },
    date: '2026-09-20',
    amount: 1000,
    direction: 'out' as const,
    currency: 'BRL',
    description: 'Aluguel',
    original_description: 'Aluguel',
    description_locked: false,
    notes: null,
    payee: null,
    category_id: null,
    tags: [],
    status: 'projected' as const,
    source: 'recurrence' as const,
    categorized_by: null,
    categorization: null,
    is_ignored: false,
    transfer_id: null,
    statement_id: null,
    installment: null,
  }
}

beforeEach(() => {
  useOverdueOccurrences.mockReturnValue({ data: [] })
})

function renderCard(onOpenOverdue: () => void = vi.fn()) {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <PendingCard onOpenOverdue={onOpenOverdue} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('PendingCard', () => {
  it('sem sugestões e sem previstas atrasadas, não renderiza nada', () => {
    useTransferSuggestions.mockReturnValue({ data: { pages: [{ data: [], meta: { next_cursor: null } }] } })
    useOverdueOccurrences.mockReturnValue({ data: [] })

    const { container } = renderCard()

    expect(container).toBeEmptyDOMElement()
  })

  it('só com previstas atrasadas (sem sugestões de transferência), mostra a contagem', () => {
    useTransferSuggestions.mockReturnValue({ data: { pages: [{ data: [], meta: { next_cursor: null } }] } })
    useOverdueOccurrences.mockReturnValue({ data: [overdueOccurrence(1), overdueOccurrence(2)] })

    renderCard()

    expect(screen.getByText('2 lançamentos previstos não confirmados')).toBeInTheDocument()
  })

  it('uma previsão atrasada: singular', () => {
    useTransferSuggestions.mockReturnValue({ data: { pages: [{ data: [], meta: { next_cursor: null } }] } })
    useOverdueOccurrences.mockReturnValue({ data: [overdueOccurrence(1)] })

    renderCard()

    expect(screen.getByText('1 lançamento previsto não confirmado')).toBeInTheDocument()
  })

  it('com as duas fontes de pendência (sugestões e previstas atrasadas), mostra as duas', () => {
    useTransferSuggestions.mockReturnValue({
      data: { pages: [{ data: [{ id: 1 }, { id: 2 }], meta: { next_cursor: null } }] },
    })
    useOverdueOccurrences.mockReturnValue({ data: [overdueOccurrence(1)] })

    renderCard()

    expect(screen.getByText('2 sugestões de transferência')).toBeInTheDocument()
    expect(screen.getByText('1 lançamento previsto não confirmado')).toBeInTheDocument()
  })

  it('clicar na contagem de previstas atrasadas chama onOpenOverdue', () => {
    useTransferSuggestions.mockReturnValue({ data: { pages: [{ data: [], meta: { next_cursor: null } }] } })
    useOverdueOccurrences.mockReturnValue({ data: [overdueOccurrence(1)] })
    const onOpenOverdue = vi.fn()

    renderCard(onOpenOverdue)
    fireEvent.click(screen.getByText('1 lançamento previsto não confirmado'))

    expect(onOpenOverdue).toHaveBeenCalled()
  })

  it('uma sugestão: singular, sem "+"', () => {
    useTransferSuggestions.mockReturnValue({ data: { pages: [{ data: [{ id: 1 }], meta: { next_cursor: null } }] } })

    renderCard()

    expect(screen.getByText('1 sugestão de transferência')).toBeInTheDocument()
    expect(screen.getByRole('link')).toHaveAttribute('href', '/transferencias/sugestoes')
  })

  it('várias sugestões na primeira página, sem mais: plural exato', () => {
    useTransferSuggestions.mockReturnValue({
      data: { pages: [{ data: [{ id: 1 }, { id: 2 }], meta: { next_cursor: null } }] },
    })

    renderCard()

    expect(screen.getByText('2 sugestões de transferência')).toBeInTheDocument()
  })

  it('com cursor seguinte na primeira página, mostra "N+" (sem contar tudo)', () => {
    useTransferSuggestions.mockReturnValue({
      data: { pages: [{ data: [{ id: 1 }, { id: 2 }], meta: { next_cursor: 'abc' } }] },
    })

    renderCard()

    expect(screen.getByText('2+ sugestões de transferência')).toBeInTheDocument()
  })

  it('sem dado ainda (carregando), não renderiza nada', () => {
    useTransferSuggestions.mockReturnValue({ data: undefined })

    const { container } = renderCard()

    expect(container).toBeEmptyDOMElement()
  })
})
