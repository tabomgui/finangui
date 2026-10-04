import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ComparisonPeriodPicker } from './comparison-period-picker'

describe('ComparisonPeriodPicker', () => {
  it('mostra os dois meses e avança/recua cada um independentemente', () => {
    const onChangeA = vi.fn()
    const onChangeB = vi.fn()
    render(<ComparisonPeriodPicker monthA="2026-09" monthB="2026-10" onChangeA={onChangeA} onChangeB={onChangeB} />)

    expect(screen.getByText('Período A')).toBeInTheDocument()
    expect(screen.getByText('Período B')).toBeInTheDocument()
    expect(screen.getByText('Setembro de 2026')).toBeInTheDocument()
    expect(screen.getByText('Outubro de 2026')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Período A: próximo mês' }))
    expect(onChangeA).toHaveBeenCalledWith('2026-10')

    fireEvent.click(screen.getByRole('button', { name: 'Período B: mês anterior' }))
    expect(onChangeB).toHaveBeenCalledWith('2026-09')
  })
})
