import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { BalanceHero } from './balance-hero'

describe('BalanceHero', () => {
  it('mostra o saldo do dia informado', () => {
    render(<BalanceHero totalBalance={100000} currency="BRL" balanceDate="2026-10-04" />)

    expect(screen.getByText('Saldo em 04/10/2026')).toBeInTheDocument()
    expect(screen.getByText('R$ 1.000,00')).toBeInTheDocument()
    expect(screen.queryByText(/Previsto/)).not.toBeInTheDocument()
  })

  it('saldo negativo muda a cor do valor', () => {
    render(<BalanceHero totalBalance={-5000} currency="BRL" balanceDate="2026-10-04" />)

    const balance = screen.getByText('-R$ 50,00')
    expect(balance).toHaveClass('text-red-200')
  })
})
