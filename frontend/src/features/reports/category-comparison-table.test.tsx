import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CategoryComparisonItem } from '@/api/types'
import { CategoryComparisonTable } from './category-comparison-table'

const items: CategoryComparisonItem[] = [
  { category_id: 1, name: 'Moradia', icon: 'house', color: null, a: 100000, b: 150000, delta: 50000, delta_percent: 50 },
  { category_id: 2, name: 'Lazer', icon: null, color: null, a: 20000, b: 10000, delta: -10000, delta_percent: -50 },
  { category_id: null, name: 'Sem categoria', icon: null, color: null, a: 0, b: 5000, delta: 5000 },
]

const totals = { a: 120000, b: 165000, delta: 45000, delta_percent: 38 }

function renderTable() {
  return render(<CategoryComparisonTable items={items} totals={totals} currency="BRL" labelA="Set/26" labelB="Out/26" />)
}

describe('CategoryComparisonTable — tabela (sm e acima)', () => {
  it('não força rolagem horizontal nem duplica o cartão (sem min-w/shadow-card próprios)', () => {
    renderTable()

    const table = screen.getByRole('table')
    expect(table).toHaveClass('table-fixed')
    expect(table.className).not.toMatch(/min-w/)
    expect(table.parentElement?.className).not.toMatch(/shadow-card/)
  })

  it('fica oculta abaixo de sm', () => {
    renderTable()

    expect(screen.getByRole('table').closest('div')).toHaveClass('hidden', 'sm:block')
  })

  it('lista as categorias com os dois períodos e a diferença', () => {
    renderTable()
    const table = screen.getByRole('table')

    expect(within(table).getByText('Moradia')).toBeInTheDocument()
    expect(within(table).getByText('Lazer')).toBeInTheDocument()
    expect(within(table).getByText('Sem categoria')).toBeInTheDocument()
    expect(within(table).getByText('Set/26')).toBeInTheDocument()
    expect(within(table).getByText('Out/26')).toBeInTheDocument()
  })

  it('mostra o percentual de variação só quando a API devolve', () => {
    renderTable()
    const table = screen.getByRole('table')

    expect(within(table).getByText('(+50%)')).toBeInTheDocument()
    expect(within(table).getByText('(-50%)')).toBeInTheDocument()
    expect(within(table).queryByText(/sem categoria/i)?.closest('tr')?.textContent).not.toMatch(/%/)
  })

  it('colore a diferença por aumento (pior) ou queda (melhor) de gasto', () => {
    renderTable()
    const table = screen.getByRole('table')

    const increaseRow = within(table).getByText('Moradia').closest('tr')
    const decreaseRow = within(table).getByText('Lazer').closest('tr')
    expect(increaseRow?.querySelector('.text-expense')).not.toBeNull()
    expect(decreaseRow?.querySelector('.text-income')).not.toBeNull()
  })

  it('mostra a linha de total com o delta e o percentual já prontos da API', () => {
    renderTable()
    const table = screen.getByRole('table')

    const totalRow = within(table).getByText('Total').closest('tr')
    expect(totalRow).not.toBeNull()
    expect(totalRow?.textContent).toMatch(/\+38%/)
  })

  it('omite o percentual do total quando a API não devolve', () => {
    render(
      <CategoryComparisonTable
        items={items}
        totals={{ a: 0, b: 5000, delta: 5000 }}
        currency="BRL"
        labelA="Set/26"
        labelB="Out/26"
      />,
    )

    const table = screen.getByRole('table')
    expect(within(table).getByText('Total').closest('tr')?.textContent).not.toMatch(/%/)
  })
})

describe('CategoryComparisonTable — lista (abaixo de sm)', () => {
  it('fica oculta a partir de sm', () => {
    renderTable()

    expect(screen.getByRole('list')).toHaveClass('sm:hidden')
  })

  it('cada categoria vira nome na primeira linha e "A → B" com a diferença na segunda', () => {
    renderTable()
    const list = screen.getByRole('list')
    const row = within(list).getByText('Moradia').closest('li')

    expect(row).not.toBeNull()
    expect(within(row as HTMLElement).getByText(/Set\/26/)).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText(/Out\/26/)).toBeInTheDocument()
    expect(row?.textContent).toMatch(/R\$\s*1\.000,00.*→.*R\$\s*1\.500,00/)
    expect(row?.querySelector('.text-expense')).not.toBeNull()
  })

  it('traz a linha de total em destaque, com os mesmos dois períodos e a diferença', () => {
    renderTable()
    const list = screen.getByRole('list')
    const totalRow = within(list).getByText('Total').closest('li')

    expect(totalRow).not.toBeNull()
    expect(totalRow).toHaveClass('font-semibold')
    expect(totalRow?.textContent).toMatch(/\+38%/)
  })

  it('mostra o percentual de variação só quando a API devolve', () => {
    renderTable()
    const list = screen.getByRole('list')

    expect(within(list).getByText('(+50%)')).toBeInTheDocument()
    expect(within(list).getByText('(-50%)')).toBeInTheDocument()
    expect(within(list).queryByText(/sem categoria/i)?.closest('li')?.textContent).not.toMatch(/%/)
  })
})
