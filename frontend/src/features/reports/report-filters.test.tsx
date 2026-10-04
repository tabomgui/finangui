import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ReportFilters } from './report-filters'

describe('ReportFilters', () => {
  it('lista as opções de período e troca ao selecionar', () => {
    const onRangeChange = vi.fn()
    render(<ReportFilters range="last12" onRangeChange={onRangeChange} basis="purchase" onBasisChange={() => {}} />)

    fireEvent.click(screen.getByRole('combobox'))
    fireEvent.click(screen.getByRole('option', { name: 'Ano atual' }))

    expect(onRangeChange).toHaveBeenCalledWith('year')
  })

  it('alterna a base de data entre compra e fatura', () => {
    const onBasisChange = vi.fn()
    render(<ReportFilters range="last12" onRangeChange={() => {}} basis="purchase" onBasisChange={onBasisChange} />)

    fireEvent.click(screen.getByRole('radio', { name: 'Vencimento da fatura' }))

    expect(onBasisChange).toHaveBeenCalledWith('statement')
  })
})
