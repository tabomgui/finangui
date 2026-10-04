import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { MonthlyEvolutionMonth } from '@/api/types'
import { EvolutionChart } from './evolution-chart'

const months: MonthlyEvolutionMonth[] = [
  { month: '2026-09', income: 500000, expense: 300000, net: 200000 },
  { month: '2026-10', income: 150000, expense: 80000, net: 70000 },
]

describe('EvolutionChart', () => {
  it('expõe um resumo acessível via aria-describedby, sem role="img" (evita conflito com as barras focáveis)', () => {
    const { container } = render(<EvolutionChart months={months} currency="BRL" />)

    expect(screen.queryByRole('img')).not.toBeInTheDocument()

    const table = screen.getByRole('table', { hidden: true })
    const describedBy = container.querySelector('[aria-describedby]')
    expect(describedBy).toHaveAttribute('aria-describedby', table.id)
    expect(table.id).not.toBe('')

    const rows = within(table).getAllByRole('row', { hidden: true })
    // cabeçalho + 2 meses
    expect(rows).toHaveLength(3)
    expect(within(table).getByText('Setembro de 2026')).toBeInTheDocument()
    expect(within(table).getByText('Outubro de 2026')).toBeInTheDocument()
    expect(within(table).getByText(/R\$\s*5\.000,00/)).toBeInTheDocument()
    expect(within(table).getByText(/R\$\s*1\.500,00/)).toBeInTheDocument()
  })
})
