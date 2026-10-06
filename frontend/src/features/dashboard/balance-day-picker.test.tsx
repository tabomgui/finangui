import { render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { BalanceDayPicker } from './balance-day-picker'

function dayButton(container: HTMLElement, isoDate: string): HTMLElement {
  const cell = container.querySelector(`[data-day="${isoDate}"]`)
  if (!cell) throw new Error(`dia ${isoDate} não está no calendário renderizado`)
  const button = cell.querySelector('button')
  if (!button) throw new Error(`dia ${isoDate} não tem botão (fora do mês exibido?)`)
  return button
}

describe('BalanceDayPicker', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-06T12:00:00-03:00'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('abre no mês do dia selecionado', () => {
    render(<BalanceDayPicker selected="2026-08-15" onSelect={vi.fn()} onBackToToday={vi.fn()} />)

    expect(screen.getByText(/agosto 2026/i)).toBeInTheDocument()
  })

  it('dias futuros ficam desabilitados', () => {
    const { container } = render(<BalanceDayPicker selected="2026-10-06" onSelect={vi.fn()} onBackToToday={vi.fn()} />)

    expect(dayButton(container, '2026-10-07')).toBeDisabled()
    expect(dayButton(container, '2026-10-06')).not.toBeDisabled()
  })

  it('escolher um dia habilitado chama onSelect com "YYYY-MM-DD"', () => {
    const onSelect = vi.fn()
    const { container } = render(<BalanceDayPicker selected="2026-10-06" onSelect={onSelect} onBackToToday={vi.fn()} />)

    dayButton(container, '2026-10-02').click()

    expect(onSelect).toHaveBeenCalledWith('2026-10-02')
  })

  it('não é possível escolher um dia futuro', () => {
    const onSelect = vi.fn()
    const { container } = render(<BalanceDayPicker selected="2026-10-06" onSelect={onSelect} onBackToToday={vi.fn()} />)

    dayButton(container, '2026-10-07').click()

    expect(onSelect).not.toHaveBeenCalled()
  })

  it('dia selecionado é hoje: não mostra "Voltar para hoje"', () => {
    render(<BalanceDayPicker selected="2026-10-06" onSelect={vi.fn()} onBackToToday={vi.fn()} />)

    expect(screen.queryByText('Voltar para hoje')).not.toBeInTheDocument()
  })

  it('dia selecionado não é hoje: mostra e aciona "Voltar para hoje"', () => {
    const onBackToToday = vi.fn()
    render(<BalanceDayPicker selected="2026-09-30" onSelect={vi.fn()} onBackToToday={onBackToToday} />)

    screen.getByText('Voltar para hoje').click()

    expect(onBackToToday).toHaveBeenCalled()
  })
})
