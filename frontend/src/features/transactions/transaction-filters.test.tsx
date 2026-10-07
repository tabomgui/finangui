import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { TransactionFilters as Filters } from '@/api/query-keys'
import { filtersFromParams } from './filters'
import { TransactionFilters } from './transaction-filters'

vi.mock('@/api/queries/tags', () => ({ useTags: () => ({ data: [] }) }))
vi.mock('@/api/queries/accounts', () => ({ useAccounts: () => ({ data: [] }) }))
vi.mock('@/api/queries/categories', () => ({
  useCategories: () => ({
    data: [
      { id: 1, name: 'Alimentação', kind: 'expense', color: null, icon: null, parent_id: null, is_archived: false, is_transfer: false, is_transfer_effective: false },
    ],
  }),
}))

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location">{location.search}</div>
}

function renderFilters(filters: Filters, initialEntry = '/') {
  render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <LocationProbe />
      <TransactionFilters filters={filters} />
    </MemoryRouter>,
  )
  fireEvent.click(screen.getByRole('button', { name: /Filtros/ }))
}

function locationFilters(): Filters {
  const search = screen.getByTestId('location').textContent ?? ''
  return filtersFromParams(new URLSearchParams(search))
}

describe('TransactionFilters', () => {
  it('mostra "Sem categoria" no gatilho quando no_category está marcado', () => {
    renderFilters({ no_category: true }, '/?sem_categoria=1')

    expect(screen.getByLabelText('Categoria')).toHaveTextContent('Sem categoria')
  })

  it('sem no_category e sem categoria escolhida, o gatilho mostra o placeholder, não "Sem categoria"', () => {
    renderFilters({})

    expect(screen.getByLabelText('Categoria')).toHaveTextContent('Todas')
  })

  it('o X de categoria só aparece com categoria ou "sem categoria" ativos', () => {
    renderFilters({})
    expect(screen.queryByRole('button', { name: 'Limpar categoria' })).not.toBeInTheDocument()
  })

  it('o X de categoria limpa categoria, category_exact e no_category de uma vez', () => {
    renderFilters({ category_id: 1, category_exact: true }, '/?categoria=1&exata=1')

    fireEvent.click(screen.getByRole('button', { name: 'Limpar categoria' }))

    expect(locationFilters()).toEqual({})
  })

  it('o checkbox "Não incluir subcategorias" só aparece com uma categoria escolhida (nunca com "Sem categoria")', () => {
    renderFilters({ no_category: true }, '/?sem_categoria=1')
    expect(screen.queryByText('Não incluir subcategorias')).not.toBeInTheDocument()
  })

  it('mostra a linha de relatório/moeda e remove os dois ao clicar no X, sem afetar outros filtros', () => {
    renderFilters({ reportable: true, currency: 'BRL', category_id: 1 }, '/?categoria=1&relatorio=1&moeda=BRL')

    expect(screen.getByText('Só lançamentos que entram em relatórios (BRL)')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Remover filtro de relatório/moeda' }))

    expect(locationFilters()).toEqual({ category_id: 1 })
  })

  it('sem relatório nem moeda ativos, a linha não aparece', () => {
    renderFilters({})
    expect(screen.queryByText(/entram em relatórios/)).not.toBeInTheDocument()
  })
})
