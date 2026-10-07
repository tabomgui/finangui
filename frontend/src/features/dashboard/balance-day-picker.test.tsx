import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { BalanceDayPicker } from './balance-day-picker'

function dayButton(container: HTMLElement, isoDate: string): HTMLElement {
  const cell = container.querySelector(`[data-day="${isoDate}"]`)
  if (!cell) throw new Error(`dia ${isoDate} não está no calendário renderizado`)
  const button = cell.querySelector('button')
  if (!button) throw new Error(`dia ${isoDate} não tem botão (fora do mês exibido?)`)
  return button
}

// `today` vem sempre explícito por prop (ver dashboard-page.tsx: a fonte é data.today do
// servidor, não o relógio do navegador) — nenhum teste aqui depende do relógio real da máquina.
const TODAY = '2026-10-06'

describe('BalanceDayPicker', () => {
  it('abre no mês do dia selecionado', () => {
    render(<BalanceDayPicker selected="2026-08-15" today={TODAY} onSelect={vi.fn()} onBackToToday={vi.fn()} />)

    expect(screen.getByText(/agosto 2026/i)).toBeInTheDocument()
  })

  it('dias futuros (em relação a "today") ficam desabilitados', () => {
    const { container } = render(<BalanceDayPicker selected="2026-10-06" today={TODAY} onSelect={vi.fn()} onBackToToday={vi.fn()} />)

    expect(dayButton(container, '2026-10-07')).toBeDisabled()
    expect(dayButton(container, '2026-10-06')).not.toBeDisabled()
  })

  it('escolher um dia habilitado chama onSelect com "YYYY-MM-DD"', () => {
    const onSelect = vi.fn()
    const { container } = render(<BalanceDayPicker selected="2026-10-06" today={TODAY} onSelect={onSelect} onBackToToday={vi.fn()} />)

    dayButton(container, '2026-10-02').click()

    expect(onSelect).toHaveBeenCalledWith('2026-10-02')
  })

  it('não é possível escolher um dia futuro', () => {
    const onSelect = vi.fn()
    const { container } = render(<BalanceDayPicker selected="2026-10-06" today={TODAY} onSelect={onSelect} onBackToToday={vi.fn()} />)

    dayButton(container, '2026-10-07').click()

    expect(onSelect).not.toHaveBeenCalled()
  })

  it('dia selecionado é "today": não mostra "Voltar para hoje"', () => {
    render(<BalanceDayPicker selected="2026-10-06" today={TODAY} onSelect={vi.fn()} onBackToToday={vi.fn()} />)

    expect(screen.queryByText('Voltar para hoje')).not.toBeInTheDocument()
  })

  it('dia selecionado é diferente de "today": mostra e aciona "Voltar para hoje"', () => {
    const onBackToToday = vi.fn()
    render(<BalanceDayPicker selected="2026-09-30" today={TODAY} onSelect={vi.fn()} onBackToToday={onBackToToday} />)

    screen.getByText('Voltar para hoje').click()

    expect(onBackToToday).toHaveBeenCalled()
  })
})
