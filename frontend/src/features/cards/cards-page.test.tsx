import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { Card } from '@/api/types'
import { CardsPage } from './cards-page'

const refetch = vi.fn()

let mockData: Card[] | undefined
let mockIsPending = false
let mockIsError = false

vi.mock('@/api/queries/cards', () => ({
  useCards: () => ({ data: mockData, isPending: mockIsPending, isError: mockIsError, refetch }),
}))

function renderPage() {
  const client = new QueryClient()

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/cartoes']}>
        <CardsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const nubank: Card = {
  id: 1,
  name: 'Nubank',
  currency: 'BRL',
  color: null,
  icon: null,
  is_archived: false,
  last_four: '1234',
  credit_limit: 500000,
  closing_day: 10,
  due_day: 17,
  balance: -120000,
  limit: { used: 150000, projected: 0, available: 350000 },
  used_limit: 150000,
  available_limit: 350000,
  current_statement: {
    id: 10,
    account_id: 1,
    closing_date: '2026-10-10',
    due_date: '2026-10-17',
    reported_total: null,
    total: 120000,
    computed_total: 120000,
    paid: 0,
    remaining: 120000,
    status: 'open',
    days_until_due: 14,
    is_overdue: false,
    has_divergence: false,
  },
}

describe('CardsPage', () => {
  it('mostra o cartão com a fatura atual e link para o detalhe', () => {
    mockData = [nubank]
    mockIsPending = false
    mockIsError = false

    renderPage()

    expect(screen.getByText('Nubank')).toBeInTheDocument()
    expect(screen.getByText('•••• 1234')).toBeInTheDocument()
    expect(screen.getByText('R$ 1.200,00')).toBeInTheDocument()
    expect(screen.getByText('Vence em 14 dias')).toBeInTheDocument()
    expect(screen.getByRole('link')).toHaveAttribute('href', '/cartoes/1')
  })

  it('cartão sem fatura atual mostra mensagem', () => {
    mockData = [{ ...nubank, current_statement: null }]
    mockIsPending = false
    mockIsError = false

    renderPage()

    expect(screen.getByText('Sem fatura em aberto')).toBeInTheDocument()
  })

  it('lista vazia mostra o estado vazio', () => {
    mockData = []
    mockIsPending = false
    mockIsError = false

    renderPage()

    expect(screen.getByText('Nenhum cartão ainda')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Cadastrar cartão' })).toBeInTheDocument()
  })

  it('erro mostra opção de tentar de novo', () => {
    mockData = undefined
    mockIsPending = false
    mockIsError = true

    renderPage()

    expect(screen.getByText('Não foi possível carregar os cartões.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetch).toHaveBeenCalled()
  })
})
