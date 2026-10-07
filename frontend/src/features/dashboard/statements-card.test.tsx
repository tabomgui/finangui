import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { Card } from '@/api/types'
import { StatementsCard } from './statements-card'

let mockData: Card[] | undefined

vi.mock('@/api/queries/cards', () => ({
  useCards: () => ({ data: mockData }),
}))

function renderCard() {
  return render(
    <MemoryRouter>
      <StatementsCard />
    </MemoryRouter>,
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

describe('StatementsCard', () => {
  it('lista cartões com fatura atual não paga: nome, restante, prazo e link', () => {
    mockData = [nubank]

    renderCard()

    expect(screen.getByText('Nubank')).toBeInTheDocument()
    expect(screen.getByText('R$ 1.200,00')).toBeInTheDocument()
    expect(screen.getByText('Vence em 14 dias')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Nubank/ })).toHaveAttribute('href', '/cartoes/1')
  })

  it('fatura vencida mostra o badge "Vencida"', () => {
    mockData = [
      {
        ...nubank,
        current_statement: { ...nubank.current_statement!, status: 'closed', days_until_due: -2, is_overdue: true },
      },
    ]

    renderCard()

    expect(screen.getByText('Vencida')).toBeInTheDocument()
  })

  it('faturas pagas não aparecem', () => {
    mockData = [
      {
        ...nubank,
        current_statement: { ...nubank.current_statement!, status: 'paid', remaining: 0 },
      },
    ]

    const { container } = renderCard()

    expect(container).toBeEmptyDOMElement()
  })

  it('fatura aberta com restante zero não aparece', () => {
    mockData = [
      {
        ...nubank,
        current_statement: { ...nubank.current_statement!, remaining: 0 },
      },
    ]

    const { container } = renderCard()

    expect(container).toBeEmptyDOMElement()
  })

  it('cartões sem fatura atual não aparecem', () => {
    mockData = [{ ...nubank, current_statement: null }]

    const { container } = renderCard()

    expect(container).toBeEmptyDOMElement()
  })

  it('sem nenhuma fatura a mostrar, não renderiza nada', () => {
    mockData = []

    const { container } = renderCard()

    expect(container).toBeEmptyDOMElement()
  })
})
