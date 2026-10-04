import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CategoryComparisonItem } from '@/api/types'
import { CategoryComparisonTable } from './category-comparison-table'

const items: CategoryComparisonItem[] = [
  { category_id: 1, name: 'Moradia', icon: 'house', color: null, a: 100000, b: 150000, delta: 50000, delta_percent: 50 },
  { category_id: 2, name: 'Lazer', icon: null, color: null, a: 20000, b: 10000, delta: -10000, delta_percent: -50 },
  { category_id: null, name: 'Sem categoria', icon: null, color: null, a: 0, b: 5000, delta: 5000 },
]

const totals = { a: 120000, b: 165000, delta: 45000, delta_percent: 38 }

describe('CategoryComparisonTable', () => {
  it('não força rolagem horizontal nem duplica o cartão (sem min-w/shadow-card próprios)', () => {
    render(
      <CategoryComparisonTable items={items} totals={totals} currency="BRL" labelA="Set/2026" labelB="Out/2026" />,
    )

    expect(screen.getByRole('table')).toHaveClass('table-fixed')
    expect(screen.getByRole('table').className).not.toMatch(/min-w/)
    expect(screen.getByRole('table').parentElement?.className).not.toMatch(/shadow-card/)
  })

  it('lista as categorias com os dois períodos e a diferença', () => {
    render(
      <CategoryComparisonTable items={items} totals={totals} currency="BRL" labelA="Set/2026" labelB="Out/2026" />,
    )

    expect(screen.getByText('Moradia')).toBeInTheDocument()
    expect(screen.getByText('Lazer')).toBeInTheDocument()
    expect(screen.getByText('Sem categoria')).toBeInTheDocument()
    expect(screen.getByText('Set/2026')).toBeInTheDocument()
    expect(screen.getByText('Out/2026')).toBeInTheDocument()
  })

  it('mostra o percentual de variação só quando a API devolve', () => {
    render(
      <CategoryComparisonTable items={items} totals={totals} currency="BRL" labelA="Set/2026" labelB="Out/2026" />,
    )

    expect(screen.getByText('(+50%)')).toBeInTheDocument()
    expect(screen.getByText('(-50%)')).toBeInTheDocument()
    expect(screen.queryByText(/sem categoria/i)?.closest('tr')?.textContent).not.toMatch(/%/)
  })

  it('colore a diferença por aumento (pior) ou queda (melhor) de gasto', () => {
    render(
      <CategoryComparisonTable items={items} totals={totals} currency="BRL" labelA="Set/2026" labelB="Out/2026" />,
    )

    const increaseRow = screen.getByText('Moradia').closest('tr')
    const decreaseRow = screen.getByText('Lazer').closest('tr')
    expect(increaseRow?.querySelector('.text-expense')).not.toBeNull()
    expect(decreaseRow?.querySelector('.text-income')).not.toBeNull()
  })

  it('mostra a linha de total com o delta e o percentual já prontos da API', () => {
    render(
      <CategoryComparisonTable items={items} totals={totals} currency="BRL" labelA="Set/2026" labelB="Out/2026" />,
    )

    const totalRow = screen.getByText('Total').closest('tr')
    expect(totalRow).not.toBeNull()
    expect(totalRow?.textContent).toMatch(/\+38%/)
  })

  it('omite o percentual do total quando a API não devolve', () => {
    render(
      <CategoryComparisonTable
        items={items}
        totals={{ a: 0, b: 5000, delta: 5000 }}
        currency="BRL"
        labelA="Set/2026"
        labelB="Out/2026"
      />,
    )

    expect(screen.getByText('Total').closest('tr')?.textContent).not.toMatch(/%/)
  })
})
