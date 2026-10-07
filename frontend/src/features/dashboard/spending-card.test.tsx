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
      <SpendingCard key={month} month={month} />
    </MemoryRouter>,
  )
}

/** Escopa pra "Gastos por categoria"/"Subcategorias de ..." sem depender do texto exato do rótulo (ver item 7). */
function categoryList() {
  return within(screen.getByTestId('spending-category-list'))
}

function legend() {
  return within(screen.getByRole('list', { name: 'Legenda do gráfico' }))
}

beforeEach(() => {
  useSpendingBreakdown.mockReset()
  useTransactionsPreview.mockReturnValue({ isPending: true, isError: false, data: undefined, refetch: vi.fn() })
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

describe('SpendingCard', () => {
  it('mostra esqueleto do donut e das linhas enquanto carrega', () => {
    useSpendingBreakdown.mockReturnValue({ data: undefined, isPending: true, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    renderCard()

    expect(screen.queryByTestId('donut')).not.toBeInTheDocument()
    expect(screen.getByTestId('spending-donut-skeleton')).toBeInTheDocument()
    expect(screen.getAllByTestId('spending-row-skeleton')).toHaveLength(3)
  })

  it('chama useSpendingBreakdown com o intervalo do mês inteiro (monthRange)', () => {
    useSpendingBreakdown.mockReturnValue({ data: undefined, isPending: true, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    renderCard('2026-10')

    expect(useSpendingBreakdown).toHaveBeenCalledWith('2026-10-01', '2026-10-31')
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

  it('a região viva começa vazia e só ganha texto depois de detalhar ou "Voltar"', async () => {
    useSpendingBreakdown.mockReturnValue({ data: withCategories(), isPending: false, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    renderCard()
    await screen.findByTestId('donut')

    expect(screen.getByRole('status')).toHaveTextContent('')

    fireEvent.click(screen.getByText('fatia-Alimentação'))
    expect(screen.getByRole('status')).toHaveTextContent('Mostrando subcategorias de Alimentação')

    fireEvent.click(screen.getByRole('button', { name: /Voltar/ }))
    expect(screen.getByRole('status')).toHaveTextContent('Mostrando categorias do mês')
  })

  it('lista as categorias no nível de topo com valor e percentual', async () => {
    useSpendingBreakdown.mockReturnValue({ data: withCategories(), isPending: false, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    renderCard()
    await screen.findByTestId('donut')

    expect(categoryList().getByText('Alimentação')).toBeInTheDocument()
    expect(categoryList().getByText('Transporte')).toBeInTheDocument()
    expect(categoryList().getByText('R$ 300,00')).toBeInTheDocument()
    expect(legend().getByText('60%')).toBeInTheDocument() // 30000/50000, Alimentação
  })

  it('detalhar: clicar numa categoria com subcategorias muda o título, mostra "Voltar", o detalhamento e anuncia o nível', async () => {
    useSpendingBreakdown.mockReturnValue({ data: withCategories(), isPending: false, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    renderCard()
    await screen.findByTestId('donut')

    fireEvent.click(screen.getByText('fatia-Alimentação'))

    expect(screen.getByRole('heading', { name: 'Distribuição de gastos · Alimentação' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Voltar/ })).toBeInTheDocument()
    expect(screen.getByText('Subcategorias de Alimentação')).toBeInTheDocument()
    expect(categoryList().getByText('Mercado')).toBeInTheDocument()
    expect(categoryList().getByText('Alimentação (direto)')).toBeInTheDocument()
    expect(categoryList().queryByText('Transporte')).not.toBeInTheDocument()
    expect(screen.getByText('Mostrando subcategorias de Alimentação')).toBeInTheDocument()
  })

  it('voltar: sai do detalhamento, volta o título/lista ao nível de topo e foca a "Voltar" ao entrar', async () => {
    useSpendingBreakdown.mockReturnValue({ data: withCategories(), isPending: false, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    renderCard()
    await screen.findByTestId('donut')

    fireEvent.click(screen.getByText('fatia-Alimentação'))
    expect(screen.getByRole('button', { name: /Voltar/ })).toHaveFocus()

    fireEvent.click(screen.getByRole('button', { name: /Voltar/ }))

    expect(screen.queryByRole('button', { name: /Voltar/ })).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Distribuição de gastos' })).toBeInTheDocument()
    expect(categoryList().getByText('Transporte')).toBeInTheDocument()
    expect(screen.getByText('Mostrando categorias do mês')).toBeInTheDocument()
  })

  it('voltar foca de novo o chip da categoria de onde veio', async () => {
    useSpendingBreakdown.mockReturnValue({ data: withCategories(), isPending: false, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    renderCard()
    await screen.findByTestId('donut')

    fireEvent.click(screen.getByText('fatia-Alimentação'))
    fireEvent.click(screen.getByRole('button', { name: /Voltar/ }))

    expect(legend().getByRole('button', { name: /Alimentação/ })).toHaveFocus()
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

  it('o chip de uma categoria com subcategorias não tem aria-pressed e avisa "ver subcategorias" no rótulo', async () => {
    useSpendingBreakdown.mockReturnValue({ data: withCategories(), isPending: false, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    renderCard()
    await screen.findByTestId('donut')

    const alimentacaoChip = legend().getByRole('button', { name: /Alimentação.*ver subcategorias/ })
    expect(alimentacaoChip).not.toHaveAttribute('aria-pressed')
  })

  it('"Ver todos" de uma linha de subcategoria usa category_id + category_exact=1', async () => {
    useSpendingBreakdown.mockReturnValue({ data: withCategories(), isPending: false, isError: false, isPlaceholderData: false, refetch: vi.fn() })
    useTransactionsPreview.mockReturnValue({ isPending: false, isError: false, data: { data: [] }, refetch: vi.fn() })

    renderCard()
    await screen.findByTestId('donut')

    fireEvent.click(screen.getByText('fatia-Alimentação'))
    fireEvent.click(categoryList().getByRole('button', { name: /Mercado/ }))
    const subcategoryHref = categoryList().getByRole('link', { name: 'Ver todos' }).getAttribute('href') ?? ''
    expect(subcategoryHref).toContain('categoria=2')
    expect(subcategoryHref).toContain('exata=1')
  })

  it('"Ver todos" da linha "Sem categoria" usa sem_categoria=1, nunca categoria', async () => {
    useTransactionsPreview.mockReturnValue({ isPending: false, isError: false, data: { data: [] }, refetch: vi.fn() })
    useSpendingBreakdown.mockReturnValue({
      data: breakdown({ total: 7000, categories: [{ name: 'Sem categoria', color: null, icon: null, amount: 7000, count: 2, children: [] }] }),
      isPending: false,
      isError: false,
      isPlaceholderData: false,
      refetch: vi.fn(),
    })

    renderCard()
    await screen.findByTestId('donut')
    fireEvent.click(categoryList().getByRole('button', { name: /Sem categoria/ }))
    const noCategoryHref = categoryList().getByRole('link', { name: 'Ver todos' }).getAttribute('href') ?? ''
    expect(noCategoryHref).toContain('sem_categoria=1')
    // `not.toContain('categoria=')` daria falso positivo: "sem_categoria=1" também contém essa
    // substring. `categoria=` (filtro de categoria) não pode aparecer, só `sem_categoria=`.
    expect(noCategoryHref).not.toMatch(/[?&]categoria=/)
  })

  it('uma chave de destaque que não existe mais na tela não esmaece ninguém (highlight ativo derivado das entradas atuais)', async () => {
    useSpendingBreakdown.mockReturnValue({ data: withCategories(), isPending: false, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    const { rerender } = render(
      <MemoryRouter>
        <SpendingCard key="2026-10" month="2026-10" />
      </MemoryRouter>,
    )
    await screen.findByTestId('donut')

    fireEvent.click(screen.getByText('fatia-Transporte'))
    expect(legend().getByRole('button', { name: /Transporte/ })).toHaveAttribute('aria-pressed', 'true')

    // Os dados mudam (mesma `key`, mesmo mês, nova resposta) e "Transporte" não existe mais:
    // nada deve continuar marcado como destacado. `key` igual de propósito aqui — isso simula
    // uma atualização por invalidação em vez de uma troca de mês (que já remonta o componente).
    useSpendingBreakdown.mockReturnValue({
      data: breakdown({ total: 30000, categories: [{ category_id: 1, name: 'Alimentação', color: null, icon: null, amount: 30000, count: 3, children: [] }] }),
      isPending: false,
      isError: false,
      isPlaceholderData: false,
      refetch: vi.fn(),
    })
    rerender(
      <MemoryRouter>
        <SpendingCard key="2026-10" month="2026-10" />
      </MemoryRouter>,
    )

    expect(legend().queryByRole('button', { name: /Transporte/ })).not.toBeInTheDocument()
    expect(legend().getByRole('button', { name: /Alimentação/ })).not.toHaveAttribute('aria-pressed', 'true')
  })

  it('trocar de mês (key={month}, como no uso real) reseta detalhamento e destaque', async () => {
    useSpendingBreakdown.mockReturnValue({ data: withCategories(), isPending: false, isError: false, isPlaceholderData: false, refetch: vi.fn() })

    const { rerender } = render(
      <MemoryRouter>
        <SpendingCard key="2026-10" month="2026-10" />
      </MemoryRouter>,
    )
    await screen.findByTestId('donut')

    fireEvent.click(screen.getByText('fatia-Alimentação'))
    expect(screen.getByRole('button', { name: /Voltar/ })).toBeInTheDocument()

    useSpendingBreakdown.mockReturnValue({
      data: breakdown({ total: 20000, categories: [{ category_id: 3, name: 'Transporte', color: null, icon: null, amount: 20000, count: 1, children: [] }] }),
      isPending: false,
      isError: false,
      isPlaceholderData: false,
      refetch: vi.fn(),
    })
    rerender(
      <MemoryRouter>
        <SpendingCard key="2026-11" month="2026-11" />
      </MemoryRouter>,
    )

    expect(screen.queryByRole('button', { name: /Voltar/ })).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Distribuição de gastos' })).toBeInTheDocument()
  })

  it('isPlaceholderData torna o conteúdo inerte (não clicável) além de esmaecido', async () => {
    useSpendingBreakdown.mockReturnValue({ data: withCategories(), isPending: false, isError: false, isPlaceholderData: true, refetch: vi.fn() })

    renderCard()
    await screen.findByTestId('donut')

    const content = screen.getByRole('status').parentElement
    expect(content).toHaveAttribute('aria-busy', 'true')
    // jsdom não reflete a propriedade IDL `inert` (a chamada abaixo confirma o atributo HTML, que
    // é o que o navegador de verdade usa para desligar foco/clique/leitor de tela na subárvore).
    expect(content).toHaveAttribute('inert')
  })
})
