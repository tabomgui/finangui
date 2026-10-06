import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { BalanceHero } from './balance-hero'

// Mocka o módulo lazy-loaded (calendário real/react-day-picker): o comportamento do calendário
// em si é testado em `balance-day-picker.test.tsx`. Aqui só interessa que o `BalanceHero` monta o
// popover com as props certas e reage às callbacks dele.
vi.mock('./balance-day-picker', () => ({
  BalanceDayPicker: ({
    selected,
    onSelect,
    onBackToToday,
  }: {
    selected: string
    onSelect: (day: string) => void
    onBackToToday: () => void
  }) => (
    <div data-testid="day-picker" data-selected={selected}>
      <button type="button" onClick={() => onSelect('2026-10-15')}>
        escolher-15
      </button>
      <button type="button" onClick={onBackToToday}>
        Voltar para hoje
      </button>
    </div>
  ),
}))

function renderHero(overrides: Partial<Parameters<typeof BalanceHero>[0]> = {}) {
  const onSelectDay = vi.fn()
  const onBackToToday = vi.fn()
  const utils = render(
    <BalanceHero
      totalBalance={100000}
      currency="BRL"
      balanceDate="2026-10-04"
      onSelectDay={onSelectDay}
      onBackToToday={onBackToToday}
      {...overrides}
    />,
  )
  return { ...utils, onSelectDay, onBackToToday }
}

describe('BalanceHero', () => {
  it('mostra o saldo do dia informado', () => {
    renderHero()

    expect(screen.getByText('Saldo em 04/10/2026')).toBeInTheDocument()
    expect(screen.getByText('R$ 1.000,00')).toBeInTheDocument()
    expect(screen.queryByText(/Previsto/)).not.toBeInTheDocument()
  })

  it('saldo negativo muda a cor do valor', () => {
    renderHero({ totalBalance: -5000 })

    const balance = screen.getByText('-R$ 50,00')
    expect(balance).toHaveClass('text-red-200')
  })

  it('antes do clique, não monta o calendário (nem o chunk lazy)', () => {
    renderHero()

    expect(screen.queryByTestId('day-picker')).not.toBeInTheDocument()
  })

  it('clicar no botão de data abre o calendário com o dia atual do saldo', async () => {
    renderHero({ balanceDate: '2026-10-04' })

    fireEvent.click(screen.getByText('Saldo em 04/10/2026'))

    const picker = await screen.findByTestId('day-picker')
    expect(picker).toHaveAttribute('data-selected', '2026-10-04')
  })

  it('escolher um dia no calendário chama onSelectDay e fecha o popover', async () => {
    const { onSelectDay } = renderHero()

    fireEvent.click(screen.getByText('Saldo em 04/10/2026'))
    await screen.findByTestId('day-picker')

    fireEvent.click(screen.getByText('escolher-15'))

    expect(onSelectDay).toHaveBeenCalledWith('2026-10-15')
    expect(screen.queryByTestId('day-picker')).not.toBeInTheDocument()
  })

  it('"Voltar para hoje" chama onBackToToday e fecha o popover', async () => {
    const { onBackToToday } = renderHero()

    fireEvent.click(screen.getByText('Saldo em 04/10/2026'))
    await screen.findByTestId('day-picker')

    fireEvent.click(screen.getByText('Voltar para hoje'))

    expect(onBackToToday).toHaveBeenCalled()
    expect(screen.queryByTestId('day-picker')).not.toBeInTheDocument()
  })
})
