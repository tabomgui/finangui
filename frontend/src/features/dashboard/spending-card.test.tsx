import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { SpendingBreakdown } from '@/api/types'
import { SpendingCard } from './spending-card'

const useSpendingBreakdown = vi.fn()
const useTransactionsPreview = vi.fn()

vi.mock('@/api/queries/reports', () => ({
  useSpendingBreakdown: (...args: unknown[]) => useSpendingBreakdown(...args),
}))

vi.mock('@/api/queries/transactions', () => ({
  useTransactionsPreview: (...args: unknown[]) => useTransactionsPreview(...args),
}))

// O donut (recharts) não renderiza nada de útil em jsdom (ResponsiveContainer mede 0x0 — ver
// evolution-chart.test.tsx, que também não exercita o gráfico de verdade). Troca por um fake que
// expõe um botão por fatia, suficiente para testar clique/seleção sem depender do SVG.
vi.mock('./spending-donut', () => ({
  SpendingDonut: ({
    entries,
    totalLabel,
    total,
    onSelect,
  }: {
    entries: { key: string; name: string }[]
    totalLabel: string
    total: number
    onSelect: (entry: { key: string; name: string }) => void
  }) => (
    <div data-testid="donut">
      <span>{totalLabel}</span>
      <span data-testid="donut-total">{total}</span>
      {entries.map((entry) => (
        <button key={entry.key} type="button" onClick={() => onSelect(entry)}>
          {`fatia-${entry.name}`}
        </button>
      ))}
    </div>
  ),
}))

function breakdown(overrides: Partial<SpendingBreakdown> = {}): SpendingBreakdown {
  return { currency: 'BRL', total: 0, categories: [], ...overrides }
}

function renderCard(month = '2026-10') {
  return render(
    <MemoryRouter>
      <SpendingCard month={month} />
    </MemoryRouter>,
  )
}

/** "Gastos por categoria" tem as mesmas categorias/percentuais da legenda — escopa pra não ambiguar. */
function categoryList() {
  return within(screen.getByRole('list', { name: 'Gastos por categoria' }))
}

function legend() {
  return within(screen.getByRole('list', { name: 'Legenda do gráfico' }))
}

beforeEach(() => {
  useSpendingBreakdown.mockReset()
  useTransactionsPreview.mockReturnValue({ isPending: true, isError: false, data: undefined, refetch: vi.fn() })
})

describe('SpendingCard', () => {
  it('mostra esqueleto enquanto carrega', () => {
    useSpendingBreakdown.mockReturnValue({ data: undefined, isPending: true, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    renderCard()

    expect(screen.queryByTestId('donut')).not.toBeInTheDocument()
  })

  it('erro: mostra "Tentar de novo" que chama refetch', () => {
    const refetch = vi.fn()
    useSpendingBreakdown.mockReturnValue({ data: undefined, isPending: false, isError: true, isPlaceholderData: false, refetch })

    renderCard()
    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetch).toHaveBeenCalled()
  })

  it('vazio: "Nenhuma despesa neste mês."', () => {
    useSpendingBreakdown.mockReturnValue({ data: breakdown(), isPending: false, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    renderCard()

    expect(screen.getByText('Nenhuma despesa neste mês.')).toBeInTheDocument()
  })

  function withCategories() {
    return breakdown({
      total: 50000,
      categories: [
        {
          category_id: 1,
          name: 'Alimentação',
          color: null,
          icon: null,
          amount: 30000,
          count: 3,
          children: [
            { category_id: 2, name: 'Mercado', color: null, icon: null, amount: 20000, count: 2 },
            { category_id: 1, name: 'Alimentação', color: null, icon: null, amount: 10000, count: 1, direct: true },
          ],
        },
        { category_id: 3, name: 'Transporte', color: null, icon: null, amount: 20000, count: 1, children: [] },
      ],
    })
  }

  it('lista as categorias no nível de topo com valor e percentual', async () => {
    useSpendingBreakdown.mockReturnValue({ data: withCategories(), isPending: false, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    renderCard()
    await screen.findByTestId('donut')

    expect(categoryList().getByText('Alimentação')).toBeInTheDocument()
    expect(categoryList().getByText('Transporte')).toBeInTheDocument()
    expect(categoryList().getByText('R$ 300,00')).toBeInTheDocument()
    expect(legend().getByText('60%')).toBeInTheDocument() // 30000/50000, Alimentação
  })

  it('detalhar: clicar numa categoria com subcategorias mostra "Voltar", o detalhamento e anuncia o nível', async () => {
    useSpendingBreakdown.mockReturnValue({ data: withCategories(), isPending: false, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    renderCard()
    await screen.findByTestId('donut')

    fireEvent.click(screen.getByText('fatia-Alimentação'))

    expect(screen.getByRole('button', { name: /Voltar/ })).toBeInTheDocument()
    expect(categoryList().getByText('Mercado')).toBeInTheDocument()
    expect(categoryList().getByText('Alimentação (direto)')).toBeInTheDocument()
    expect(categoryList().queryByText('Transporte')).not.toBeInTheDocument()
    expect(screen.getByText('Mostrando subcategorias de Alimentação')).toBeInTheDocument()
  })

  it('voltar: sai do detalhamento e volta a mostrar o nível de topo', async () => {
    useSpendingBreakdown.mockReturnValue({ data: withCategories(), isPending: false, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    renderCard()
    await screen.findByTestId('donut')

    fireEvent.click(screen.getByText('fatia-Alimentação'))
    fireEvent.click(screen.getByRole('button', { name: /Voltar/ }))

    expect(screen.queryByRole('button', { name: /Voltar/ })).not.toBeInTheDocument()
    expect(categoryList().getByText('Transporte')).toBeInTheDocument()
    expect(screen.getByText('Mostrando categorias do mês')).toBeInTheDocument()
  })

  it('destacar: clicar numa categoria sem subcategorias não detalha, só alterna o destaque na legenda (clique de novo desfaz)', async () => {
    useSpendingBreakdown.mockReturnValue({ data: withCategories(), isPending: false, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    renderCard()
    await screen.findByTestId('donut')

    fireEvent.click(screen.getByText('fatia-Transporte'))
    expect(screen.queryByRole('button', { name: /Voltar/ })).not.toBeInTheDocument()
    const transporteChip = legend().getByRole('button', { name: /Transporte/ })
    expect(transporteChip).toHaveAttribute('aria-pressed', 'true')

    fireEvent.click(screen.getByText('fatia-Transporte'))
    expect(legend().getByRole('button', { name: /Transporte/ })).toHaveAttribute('aria-pressed', 'false')
  })
})
