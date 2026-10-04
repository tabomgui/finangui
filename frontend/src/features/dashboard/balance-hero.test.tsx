import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { BalanceHero } from './balance-hero'

describe('BalanceHero', () => {
  it('sem saldo previsto, mostra só o saldo atual', () => {
    render(<BalanceHero totalBalance={100000} currency="BRL" balanceDate="2026-10-04" month="2026-10" currentMonth="2026-10" />)

    expect(screen.getByText('Saldo em 04/10/2026')).toBeInTheDocument()
    expect(screen.queryByText(/Previsto/)).not.toBeInTheDocument()
  })

  it('mês atual: rotula com o dia/mês do fim do mês mostrado', () => {
    render(
      <BalanceHero
        totalBalance={100000}
        currency="BRL"
        balanceDate="2026-10-04"
        projectedBalance={80000}
        month="2026-10"
        currentMonth="2026-10"
      />,
    )

    expect(screen.getByText('Previsto para 31/10:')).toBeInTheDocument()
    expect(screen.getByText('R$ 800,00')).toBeInTheDocument()
  })

  it('mês seguinte: rotula com o nome do mês ("fim de novembro")', () => {
    render(
      <BalanceHero
        totalBalance={100000}
        currency="BRL"
        balanceDate="2026-10-04"
        projectedBalance={-5000}
        month="2026-11"
        currentMonth="2026-10"
      />,
    )

    expect(screen.getByText('Previsto para o fim de novembro:')).toBeInTheDocument()
  })

  it('isPlaceholderData: esconde o saldo previsto (ainda é do mês anterior)', () => {
    render(
      <BalanceHero
        totalBalance={100000}
        currency="BRL"
        balanceDate="2026-10-04"
        projectedBalance={80000}
        month="2026-11"
        currentMonth="2026-10"
        isPlaceholderData
      />,
    )

    expect(screen.queryByText(/Previsto/)).not.toBeInTheDocument()
  })
})
