import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { Card, CardStatement } from '@/api/types'
import { StatementSummary } from './statement-summary'

type LimitProps = Pick<Card, 'used_limit' | 'available_limit' | 'credit_limit'>

function statement(overrides: Partial<CardStatement> = {}): CardStatement {
  return {
    id: 10,
    account_id: 1,
    closing_date: '2026-10-10',
    due_date: '2026-10-17',
    reported_total: null,
    total: 120000,
    computed_total: 120000,
    paid: 0,
    remaining: 0,
    status: 'open',
    days_until_due: 14,
    is_overdue: false,
    has_divergence: false,
    ...overrides,
  }
}

function renderSummary(target: CardStatement, limit: LimitProps = { used_limit: 0, available_limit: undefined, credit_limit: 0 }) {
  return render(<StatementSummary statement={target} currency="BRL" limit={limit} onPay={vi.fn()} onEditDates={vi.fn()} />)
}

describe('StatementSummary', () => {
  it('fatura aberta com restante zero: "Pagar fatura" fica habilitado (sempre pode pagar uma fatura aberta)', () => {
    renderSummary(statement({ status: 'open', remaining: 0 }))

    expect(screen.getByRole('button', { name: 'Pagar fatura' })).toBeEnabled()
  })

  it('fatura paga/fechada com restante zero: "Pagar fatura" fica desabilitado', () => {
    renderSummary(statement({ status: 'paid', remaining: 0 }))
    expect(screen.getByRole('button', { name: 'Pagar fatura' })).toBeDisabled()

    renderSummary(statement({ status: 'closed', remaining: 0 }))
    expect(screen.getAllByRole('button', { name: 'Pagar fatura' }).at(-1)).toBeDisabled()
  })

  it('restante maior que zero: "Pagar fatura" fica habilitado independente do status', () => {
    renderSummary(statement({ status: 'partial', remaining: 5000 }))

    expect(screen.getByRole('button', { name: 'Pagar fatura' })).toBeEnabled()
  })

  it('aviso de divergência mostra o total calculado (computed_total), não o exibido', () => {
    renderSummary(
      statement({ status: 'closed', reported_total: 150000, total: 150000, computed_total: 142000, has_divergence: true }),
    )

    expect(screen.getByText(/O banco informou/)).toHaveTextContent('R$ 1.500,00')
    expect(screen.getByText(/O banco informou/)).toHaveTextContent('R$ 1.420,00')
  })

  it('mostra o limite usado do cartão ao lado do total', () => {
    renderSummary(statement(), { used_limit: 52000, available_limit: 748000, credit_limit: 800000 })

    expect(screen.getByText('Limite usado R$ 520,00 de R$ 8.000,00')).toBeInTheDocument()
  })

  it('sem nenhum limite cadastrado ou do banco, o bloco de limite não aparece', () => {
    renderSummary(statement(), { used_limit: 0, available_limit: undefined, credit_limit: 0 })

    expect(screen.queryByText(/Limite usado/)).not.toBeInTheDocument()
  })
})
