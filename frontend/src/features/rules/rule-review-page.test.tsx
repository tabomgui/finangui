import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { CategoryChangeItem, RuleReview } from '@/api/types'
import { SidebarProvider } from '@/components/ui/sidebar'
import { RuleReviewPage } from './rule-review-page'

// jsdom não implementa `matchMedia`; `useIsMobile` (shadcn, usado por `SidebarProvider`) chama
// sem guarda.
window.matchMedia ??=
  ((query: string) =>
    ({
      matches: false,
      media: query,
      onchange: null,
      addListener: () => {},
      removeListener: () => {},
      addEventListener: () => {},
      removeEventListener: () => {},
      dispatchEvent: () => false,
    }) as unknown as MediaQueryList) as typeof window.matchMedia

vi.mock('sonner', () => ({
  toast: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }),
}))

type ReviewQuery = { data: RuleReview | undefined; isPending: boolean; isError: boolean; isFetching: boolean; refetch: () => void }
let reviewQuery: ReviewQuery
const refetch = vi.fn()
const applyMutateAsync = vi.fn()
let applyPending = false

vi.mock('@/api/queries/rules', () => ({
  useRuleReview: () => reviewQuery,
  useApplyRuleReview: () => ({ mutateAsync: applyMutateAsync, isPending: applyPending }),
}))

vi.mock('@/api/queries/categories', () => ({
  useCategories: () => ({
    data: [
      { id: 1, parent_id: null, name: 'Alimentação' },
      { id: 2, parent_id: 1, name: 'Mercado' },
      { id: 3, parent_id: null, name: 'Lazer' },
    ],
    isPending: false,
  }),
}))

vi.mock('@/api/queries/notifications', () => ({
  useUnreadCount: () => ({ data: 0 }),
  useNotifications: () => ({ data: undefined, fetchNextPage: vi.fn(), hasNextPage: false, isFetchingNextPage: false, isPending: true }),
  useMarkRead: () => ({ mutate: vi.fn() }),
  useMarkAllRead: () => ({ mutate: vi.fn(), isPending: false }),
}))

function item(overrides: Partial<CategoryChangeItem>): CategoryChangeItem {
  return {
    transaction_id: 1,
    date: '2026-09-15',
    description: 'Compra exemplo',
    account_name: 'Conta exemplo',
    amount: 4590,
    direction: 'out',
    ...overrides,
  }
}

function review(): RuleReview {
  return {
    max_changes: 5000,
    groups: [
      {
        key: 'manual',
        count: 1,
        applicable: false,
        truncated: false,
        items: [
          item({
            transaction_id: 40,
            description: 'Feira manual',
            current_category_id: 3,
            new_category_id: 2,
            rule_id: 9,
            rule_name: 'Feira',
            current_source: 'manual',
          }),
        ],
      },
      {
        key: 'fill',
        count: 2,
        applicable: true,
        truncated: false,
        items: [
          item({ transaction_id: 10, description: 'Mercado A', new_category_id: 2, rule_id: 9, rule_name: 'Mercados' }),
          item({ transaction_id: 11, description: 'Mercado B', new_category_id: 2, rule_id: 9, rule_name: 'Mercados' }),
        ],
      },
      {
        key: 'conflict',
        count: 1,
        applicable: true,
        truncated: false,
        items: [
          item({
            transaction_id: 20,
            description: 'Cinema',
            current_category_id: 2,
            new_category_id: 3,
            rule_id: 7,
            rule_name: 'Lazer',
            rule_active: true,
            current_source: 'rule',
            current_rule_name: 'Delivery',
          }),
        ],
      },
      {
        key: 'stale',
        count: 900,
        applicable: true,
        truncated: true,
        items: [
          item({
            transaction_id: 30,
            description: 'Antiga',
            current_category_id: 3,
            rule_id: 8,
            rule_name: 'Cinema antigo',
            rule_active: false,
            current_source: 'rule',
            current_rule_name: 'Cinema antigo',
          }),
        ],
      },
    ],
  }
}

function setReview(data: RuleReview | undefined, overrides: Partial<ReviewQuery> = {}) {
  reviewQuery = { data, isPending: data === undefined, isError: false, isFetching: false, refetch, ...overrides }
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/regras/revisao']}>
      <SidebarProvider>
        <RuleReviewPage />
      </SidebarProvider>
    </MemoryRouter>,
  )
}

function group(label: string) {
  return screen.getByRole('region', { name: label })
}

function expand(label: string) {
  const escaped = label.replace(/[()]/g, '\\$&')
  fireEvent.click(within(group(label)).getByRole('button', { name: new RegExp(escaped) }))
}

beforeEach(() => {
  setReview(review())
  refetch.mockReset()
  applyMutateAsync.mockReset()
  applyPending = false
  vi.mocked(toast).mockClear()
  vi.mocked(toast.success).mockClear()
})

describe('RuleReviewPage', () => {
  it('mostra os grupos na ordem fill, conflict, stale, manual, com o resumo no cabeçalho', () => {
    renderPage()

    const labels = screen.getAllByRole('region').map((region) => region.getAttribute('aria-label'))
    expect(labels).toEqual([
      'Sem categoria — as regras resolvem',
      'Categoria automática diferente da regra',
      'Categorizados por regra que não se aplica mais',
      'Você escolheu outra categoria (nunca muda)',
    ])
    expect(screen.getByText('903 para aplicar · 1 só para conferir')).toBeInTheDocument()
  })

  it('seleção padrão: só "Sem categoria" marcado; manual não tem como marcar', () => {
    renderPage()

    expect(within(group('Sem categoria — as regras resolvem')).getByRole('checkbox', { name: /Selecionar todos/ })).toHaveAttribute('aria-checked', 'true')
    expect(within(group('Categoria automática diferente da regra')).getByRole('checkbox', { name: /Selecionar todos/ })).toHaveAttribute(
      'aria-checked',
      'false',
    )
    expect(within(group('Categorizados por regra que não se aplica mais')).getByRole('checkbox', { name: /Selecionar todos/ })).toHaveAttribute(
      'aria-checked',
      'false',
    )
    expect(within(group('Você escolheu outra categoria (nunca muda)')).queryByRole('checkbox')).not.toBeInTheDocument()
    expect(screen.getByText('2 selecionados')).toBeInTheDocument()

    expand('Você escolheu outra categoria (nunca muda)')
    const manual = group('Você escolheu outra categoria (nunca muda)')
    expect(within(manual).getByText('Feira manual')).toBeInTheDocument()
    expect(within(manual).queryByRole('checkbox')).not.toBeInTheDocument()
    expect(within(manual).getByText('Hoje: Lazer (à mão)')).toBeInTheDocument()
    expect(within(manual).getByText('Alimentação › Mercado')).toBeInTheDocument()
    expect(within(manual).getByText('Regra: Feira')).toBeInTheDocument()
  })

  it('selecionar tudo é de três estados', () => {
    renderPage()
    expand('Sem categoria — as regras resolvem')
    const fill = group('Sem categoria — as regras resolvem')

    fireEvent.click(within(fill).getByRole('checkbox', { name: 'Selecionar Mercado A' }))
    expect(within(fill).getByRole('checkbox', { name: /Selecionar todos/ })).toHaveAttribute('aria-checked', 'mixed')

    fireEvent.click(within(fill).getByRole('checkbox', { name: /Selecionar todos/ }))
    expect(within(fill).getByRole('checkbox', { name: /Selecionar todos/ })).toHaveAttribute('aria-checked', 'true')

    fireEvent.click(within(fill).getByRole('checkbox', { name: /Selecionar todos/ }))
    expect(within(fill).getByRole('checkbox', { name: /Selecionar todos/ })).toHaveAttribute('aria-checked', 'false')
  })

  it('grupo truncado avisa quantos mostra de quantos', () => {
    renderPage()
    expand('Categorizados por regra que não se aplica mais')

    const stale = group('Categorizados por regra que não se aplica mais')
    expect(screen.getByText('mostrando os 1 mais recentes de 900')).toBeInTheDocument()
    expect(within(stale).getByText('sem categoria')).toBeInTheDocument()
    expect(within(stale).getByText('Hoje: Lazer (regra Cinema antigo)')).toBeInTheDocument()
    expect(within(stale).getByText('Regra: Cinema antigo (desativada)')).toBeInTheDocument()
  })

  it('aplica com category_id da categoria nova, sem ele para tirar a categoria, e mostra o resultado', async () => {
    applyMutateAsync.mockResolvedValue({ applied: 3, skipped: 1 })
    renderPage()

    fireEvent.click(within(group('Categorizados por regra que não se aplica mais')).getByRole('checkbox', { name: /Selecionar todos/ }))
    expect(screen.getByText('3 selecionados')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar 3 alterações' }))
    expect(screen.getByText('Aplicar 3 alterações?')).toBeInTheDocument()
    fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Aplicar' }))

    await waitFor(() =>
      expect(applyMutateAsync).toHaveBeenCalledWith({
        items: [
          { transaction_id: 10, category_id: 2 },
          { transaction_id: 11, category_id: 2 },
          { transaction_id: 30 },
        ],
      }),
    )
    expect(toast.success).toHaveBeenCalledWith('3 lançamentos atualizados · 1 pulado porque mudou desde a revisão')
    await waitFor(() => expect(screen.queryByText(/selecionados?$/)).not.toBeInTheDocument())
    // O título do diálogo, fechando, continua com a contagem que foi confirmada.
    expect(screen.queryByText('Aplicar 0 alterações?')).not.toBeInTheDocument()
  })

  it('sem diferenças mostra o estado vazio', () => {
    setReview({
      max_changes: 5000,
      groups: [
        { key: 'fill', count: 0, applicable: true, truncated: false, items: [] },
        { key: 'manual', count: 0, applicable: false, truncated: false, items: [] },
      ],
    })
    renderPage()

    expect(screen.getByText('Tudo certo: as regras concordam com os lançamentos')).toBeInTheDocument()
    expect(screen.getByText('Nada para aplicar')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Aplicar/ })).not.toBeInTheDocument()
  })

  it('conflito mostra de onde vem a categoria atual', () => {
    renderPage()
    expand('Categoria automática diferente da regra')

    const conflict = group('Categoria automática diferente da regra')
    expect(within(conflict).getByText('Hoje: Alimentação › Mercado (regra Delivery)')).toBeInTheDocument()
    expect(within(conflict).getByText('Regra: Lazer')).toBeInTheDocument()
  })

  it('o grupo à mão fica alinhado com os outros, sem caixa de seleção', () => {
    renderPage()
    const manual = group('Você escolheu outra categoria (nunca muda)')
    expect(manual.querySelector('span[aria-hidden].h-5.w-5')).not.toBeNull()
  })

  it('Aplicar fica desabilitado enquanto a revisão recarrega', () => {
    setReview(review(), { isFetching: true })
    renderPage()

    expect(screen.getByRole('button', { name: 'Aplicar 2 alterações' })).toBeDisabled()
  })

  it('acima de max_changes avisa e desabilita o Aplicar', () => {
    const data = review()
    data.max_changes = 2
    setReview(data)
    renderPage()

    fireEvent.click(within(group('Categoria automática diferente da regra')).getByRole('checkbox', { name: /Selecionar todos/ }))

    expect(screen.getByRole('alert')).toHaveTextContent('O máximo é 2 por vez.')
    expect(screen.getByRole('button', { name: 'Aplicar 3 alterações' })).toBeDisabled()
  })

  it('com a aplicação em andamento, as caixas das linhas ficam desabilitadas', () => {
    applyPending = true
    renderPage()
    expand('Sem categoria — as regras resolvem')

    expect(screen.getByRole('checkbox', { name: 'Selecionar Mercado A' })).toBeDisabled()
  })

  it('enquanto carrega, o cabeçalho já tem um subtítulo', () => {
    setReview(undefined)
    renderPage()

    expect(screen.getByText('Comparando regras e lançamentos…')).toBeInTheDocument()
  })

  it('erro mostra "Tentar de novo"', () => {
    setReview(undefined, { isPending: false, isError: true })
    renderPage()

    expect(screen.getByText('Revisão indisponível')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetch).toHaveBeenCalled()
  })
})
