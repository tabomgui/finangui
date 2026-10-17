import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { Category, PendingCategorizationGroup, PendingCategorizationTransaction } from '@/api/types'
import { DEFAULT_COLOR } from '@/lib/palette'
import { CategorizePage } from './categorize-page'

vi.mock('sonner', () => ({
  toast: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }),
}))

const usePendingCategorization = vi.fn()
const applyMutateAsync = vi.fn()
const createMutateAsync = vi.fn()
const useCategories = vi.fn(() => ({ data: [] as Category[] }))
const useRulePreview = vi.fn((_body: unknown, _enabled: boolean) => ({
  data: undefined as { matched: number; changed: number } | undefined,
  isPending: true,
  isPlaceholderData: false,
  isError: false,
}))

vi.mock('@/api/queries/categorization', () => ({
  usePendingCategorizationCount: () => ({ data: { total_pending: 3, group_count: 2 }, isFetchedAfterMount: true }),
  usePendingCategorization: () => usePendingCategorization(),
  useApplyCategorization: () => ({ mutateAsync: applyMutateAsync, isPending: false }),
}))

vi.mock('@/api/queries/categories', () => ({
  useCategories: () => useCategories(),
  useCreateCategory: () => ({ mutateAsync: createMutateAsync, isPending: false }),
}))

vi.mock('@/api/queries/rules', () => ({
  useRulePreview: (body: unknown, enabled: boolean) => useRulePreview(body, enabled),
}))

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ data: [{ id: 1, name: 'Banco Inter' }], isPending: false }),
}))

vi.mock('@/api/queries/notifications', () => ({
  useUnreadCount: () => ({ data: 0 }),
  useNotifications: () => ({ data: undefined, fetchNextPage: vi.fn(), hasNextPage: false, isFetchingNextPage: false, isPending: true }),
  useMarkRead: () => ({ mutate: vi.fn() }),
  useMarkAllRead: () => ({ mutate: vi.fn(), isPending: false }),
}))

function category(overrides: Partial<Category> = {}): Category {
  return {
    id: 50,
    parent_id: null,
    name: 'Compras',
    kind: 'expense',
    icon: 'shopping-bag',
    color: '#14b8a6',
    is_transfer: false,
    is_transfer_effective: false,
    is_archived: false,
    ...overrides,
  }
}

function tx(overrides: Partial<PendingCategorizationTransaction>): PendingCategorizationTransaction {
  const description = overrides.description ?? 'Pix enviado - Shpp Brasil'
  return {
    id: 1,
    date: '2026-10-10',
    description,
    original_description: description,
    amount: 1000,
    direction: 'out',
    account_id: 1,
    account_name: 'Banco Inter',
    ...overrides,
  }
}

function group(overrides: Partial<PendingCategorizationGroup> = {}): PendingCategorizationGroup {
  return {
    key: 'shpp',
    name: 'Shpp Brasil',
    out_count: 2,
    in_count: 0,
    net: -2000,
    transactions: [tx({ id: 1 }), tx({ id: 2, description: 'Pix enviado - Shpp Brasil 2' })],
    ...overrides,
  }
}

function mockPending(groups: PendingCategorizationGroup[]) {
  usePendingCategorization.mockReturnValue({
    data: { total_pending: groups.reduce((sum, g) => sum + g.transactions.length, 0), group_count: groups.length, groups },
    isPending: false,
    isError: false,
    isPlaceholderData: false,
    refetch: vi.fn(),
  })
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/categorizar']}>
      <CategorizePage />
    </MemoryRouter>,
  )
}

function selectGroup(name: string) {
  fireEvent.click(screen.getByRole('checkbox', { name: `Selecionar grupo ${name}` }))
}

function chooseCategory(name: string) {
  fireEvent.click(screen.getByRole('combobox', { name: 'Definir categoria' }))
  fireEvent.click(screen.getByRole('option', { name }))
}

function applyButton() {
  return screen.getByRole('button', { name: /^Aplicar a categoria a \d+ lançamentos?$/ })
}

function ruleSwitch() {
  return screen.getByRole('switch', { name: 'Criar regra para os próximos' })
}

function lastApplyBody() {
  return applyMutateAsync.mock.calls.at(-1)?.[0]
}

const SHPP_RULE_CONDITIONS = [
  { field: 'original_description', op: 'contains', value: 'Shpp Brasil' },
  { field: 'direction', op: 'equals', value: 'out' },
]

function manyGroups(count: number): PendingCategorizationGroup[] {
  return Array.from({ length: count }, (_, i) =>
    group({
      key: `g${i + 1}`,
      name: `Grupo ${i + 1}`,
      out_count: 1,
      net: -1000,
      transactions: [tx({ id: 100 + i, description: `Pix enviado - Grupo ${i + 1}` })],
    }),
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  window.localStorage.clear()
  useCategories.mockReturnValue({ data: [category()] })
  applyMutateAsync.mockResolvedValue({ categorized: 2, skipped: 0 })
  mockPending([group()])
})

describe('CategorizeActionBar', () => {
  it('mostra o resumo da seleção: quantidade, total e grupos', () => {
    renderPage()
    selectGroup('Shpp Brasil')

    const summary = screen.getByText('2 selecionados').parentElement as HTMLElement
    expect(summary).toHaveTextContent(/^2 selecionados · -R\$\s20,00 · 1 grupo$/)
  })

  it('escolher a categoria habilita "Aplicar"', () => {
    renderPage()
    selectGroup('Shpp Brasil')

    expect(applyButton()).toBeDisabled()
    chooseCategory('Compras')
    expect(applyButton()).toBeEnabled()
  })

  it('aplica com a regra padrão e apply_to_pending marcado por padrão', async () => {
    renderPage()
    selectGroup('Shpp Brasil')
    chooseCategory('Compras')
    fireEvent.click(applyButton())

    await vi.waitFor(() =>
      expect(applyMutateAsync).toHaveBeenCalledWith({
        transaction_ids: [1, 2],
        category_id: 50,
        rule: { name: 'Shpp Brasil → Compras', match: 'all', conditions: SHPP_RULE_CONDITIONS, apply_to_pending: true },
      }),
    )
  })

  it('desmarcar "aplicar também a esses" manda apply_to_pending falso', async () => {
    renderPage()
    selectGroup('Shpp Brasil')
    chooseCategory('Compras')
    fireEvent.click(screen.getByRole('checkbox', { name: 'aplicar também a esses' }))
    fireEvent.click(applyButton())

    await vi.waitFor(() => expect(lastApplyBody()?.rule).toMatchObject({ apply_to_pending: false }))
  })

  it('com o interruptor desligado, o corpo vai sem regra e a escolha fica guardada', async () => {
    renderPage()
    selectGroup('Shpp Brasil')
    chooseCategory('Compras')
    fireEvent.click(ruleSwitch())
    expect(ruleSwitch()).toHaveAttribute('aria-checked', 'false')
    expect(window.localStorage.getItem('finangui:categorizeRuleEnabled')).toBe('false')

    fireEvent.click(applyButton())

    await vi.waitFor(() => expect(applyMutateAsync).toHaveBeenCalled())
    expect(lastApplyBody()).toEqual({ transaction_ids: [1, 2], category_id: 50 })
  })

  it('começa com a última escolha manual guardada', () => {
    window.localStorage.setItem('finangui:categorizeRuleEnabled', 'false')
    renderPage()
    selectGroup('Shpp Brasil')

    expect(ruleSwitch()).toHaveAttribute('aria-checked', 'false')
  })

  it('o interruptor começa desligado quando a seleção é parcial em algum grupo', () => {
    renderPage()
    fireEvent.click(screen.getByRole('button', { name: /Shpp Brasil/ }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Selecionar Pix enviado - Shpp Brasil' }))
    expect(ruleSwitch()).toHaveAttribute('aria-checked', 'false')

    fireEvent.click(screen.getByRole('checkbox', { name: 'Selecionar Pix enviado - Shpp Brasil 2' }))
    expect(ruleSwitch()).toHaveAttribute('aria-checked', 'true')
  })

  it('conta os pendentes que a regra casa além dos marcados e soma no "Aplicar"', () => {
    mockPending([
      group({ transactions: [tx({ id: 1 })], out_count: 1, net: -1000 }),
      group({ key: 'outro', name: 'Outro', transactions: [tx({ id: 3, description: 'Outro', original_description: 'PIX SHPP BRASIL' })] }),
    ])
    renderPage()
    selectGroup('Shpp Brasil')

    const bar = screen.getByText('Criar regra para os próximos').closest('div.rounded-xl') as HTMLElement
    expect(within(bar).getByText('1 além dos marcados')).toBeInTheDocument()
    expect(bar).toHaveTextContent('casa 2 lançamentos pendentes')
    expect(applyButton()).toHaveTextContent('Aplicar a 2')

    fireEvent.click(screen.getByRole('checkbox', { name: 'aplicar também a esses' }))
    expect(applyButton()).toHaveTextContent('Aplicar a 1')
  })

  it('personalizar a regra altera o corpo enviado', async () => {
    renderPage()
    selectGroup('Shpp Brasil')
    chooseCategory('Compras')

    fireEvent.click(screen.getByRole('button', { name: 'Personalizar' }))
    const dialog = screen.getByRole('dialog', { name: 'Regra de categorização' })
    const value = within(dialog).getByLabelText('Valor (condição 1 da alternativa 1)')
    fireEvent.change(value, { target: { value: 'SHPP' } })
    expect(within(dialog).getByText(/Casaria/)).toHaveTextContent('Casaria 2 lançamentos pendentes hoje.')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Usar esta regra' }))

    expect(screen.getByRole('button', { name: 'Editar regra' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Voltar ao padrão' })).toBeInTheDocument()

    fireEvent.click(applyButton())
    await vi.waitFor(() =>
      expect(lastApplyBody()?.rule).toMatchObject({
        match: 'all',
        conditions: [
          { field: 'original_description', op: 'contains', value: 'SHPP' },
          { field: 'direction', op: 'equals', value: 'out' },
        ],
      }),
    )
  })

  it('"+ alternativa" e "Voltar ao padrão"', async () => {
    renderPage()
    selectGroup('Shpp Brasil')
    chooseCategory('Compras')

    fireEvent.click(screen.getByRole('button', { name: 'Personalizar' }))
    const dialog = screen.getByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'alternativa (“ou”)' }))
    expect(within(dialog).getByText('Ou se')).toBeInTheDocument()

    // Valor vazio não passa.
    fireEvent.click(within(dialog).getByRole('button', { name: 'Usar esta regra' }))
    expect(within(dialog).getByText('Informe um valor.')).toBeInTheDocument()

    fireEvent.change(within(dialog).getByLabelText('Valor (condição 1 da alternativa 2)'), { target: { value: 'Shopee' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Usar esta regra' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Voltar ao padrão' }))
    fireEvent.click(applyButton())
    await vi.waitFor(() => expect(lastApplyBody()?.rule?.conditions).toEqual(SHPP_RULE_CONDITIONS))
  })

  it('condição que o cliente não avalia usa a prévia do servidor', async () => {
    useRulePreview.mockReturnValue({ data: { matched: 9, changed: 7 }, isPending: false, isPlaceholderData: false, isError: false })
    renderPage()
    selectGroup('Shpp Brasil')
    chooseCategory('Compras')

    fireEvent.click(screen.getByRole('button', { name: 'Personalizar' }))
    const dialog = screen.getByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Valor (condição 1 da alternativa 1)'), { target: { value: '^PIX' } })
    const operator = within(dialog).getByLabelText('Operador (condição 1 da alternativa 1)')
    fireEvent.pointerDown(operator, { button: 0, pointerType: 'mouse' })
    fireEvent.click(operator)
    fireEvent.click(await screen.findByRole('option', { name: 'casa a expressão' }))

    await vi.waitFor(() => expect(within(dialog).getByText(/Casaria/)).toHaveTextContent('Casaria 7 lançamentos sem categoria hoje.'), {
      timeout: 2000,
    })
    const [body, enabled] = useRulePreview.mock.calls.at(-1) ?? []
    expect(enabled).toBe(true)
    expect(body).toMatchObject({
      match: 'all',
      actions: [{ type: 'set_category', category_id: 50 }],
      overwrite: false,
      conditions: [
        { field: 'original_description', op: 'regex', value: '^PIX' },
        { field: 'direction', op: 'equals', value: 'out' },
      ],
    })
  })

  it('cria uma subcategoria pelo seletor e aplica com ela', async () => {
    const created = category({ id: 77, parent_id: 50, name: 'Shopee' })
    createMutateAsync.mockResolvedValue(created)
    renderPage()
    selectGroup('Shpp Brasil')

    fireEvent.click(screen.getByRole('combobox', { name: 'Definir categoria' }))
    fireEvent.change(screen.getByPlaceholderText('Buscar ou criar categoria'), { target: { value: 'Shopee' } })
    fireEvent.click(screen.getByRole('option', { name: 'Criar “Shopee”…' }))

    expect(screen.getByLabelText('Nome')).toHaveValue('Shopee')
    const parent = screen.getByLabelText('Dentro de')
    fireEvent.pointerDown(parent, { button: 0, pointerType: 'mouse' })
    fireEvent.click(parent)
    fireEvent.click(await screen.findByRole('option', { name: 'Compras' }))
    expect(screen.getByText('Compras › Shopee')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Criar e usar' }))

    await vi.waitFor(() =>
      expect(createMutateAsync).toHaveBeenCalledWith({ name: 'Shopee', kind: 'expense', parent_id: 50, icon: 'tag', color: DEFAULT_COLOR }),
    )
    await vi.waitFor(() => expect(screen.getByRole('combobox', { name: 'Definir categoria' })).toHaveTextContent('Shopee'))

    fireEvent.click(applyButton())
    await vi.waitFor(() =>
      expect(applyMutateAsync).toHaveBeenCalledWith(
        expect.objectContaining({
          category_id: 77,
          categoryCreated: true,
          rule: expect.objectContaining({ name: 'Shpp Brasil → Compras › Shopee' }),
        }),
      ),
    )
  })

  it('seleção mista desabilita a categoria e esconde a regra e o "Aplicar"', () => {
    mockPending([
      group({
        in_count: 1,
        out_count: 1,
        net: 0,
        transactions: [tx({ id: 1, description: 'Saída' }), tx({ id: 2, direction: 'in', description: 'Entrada' })],
      }),
    ])
    renderPage()
    selectGroup('Shpp Brasil')

    expect(screen.getByRole('combobox', { name: 'Definir categoria' })).toBeDisabled()
    expect(screen.getByText(/Selecione só entradas ou só saídas/)).toBeInTheDocument()
    expect(screen.queryByRole('switch')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Aplicar a categoria/ })).not.toBeInTheDocument()
  })

  it('o toast mostra os números da resposta e a barra some', async () => {
    applyMutateAsync.mockResolvedValue({ categorized: 2, rule_applied: 3, skipped: 0, rule: { id: 9, name: 'Shpp Brasil → Compras' } })
    renderPage()
    selectGroup('Shpp Brasil')
    chooseCategory('Compras')
    fireEvent.click(applyButton())

    await vi.waitFor(() => expect(toast.success).toHaveBeenCalledWith('5 lançamentos → Compras · regra criada'))
    expect(screen.queryByText('2 selecionados')).not.toBeInTheDocument()
  })

  it('sem regra, o toast não fala de regra', async () => {
    applyMutateAsync.mockResolvedValue({ categorized: 1, skipped: 1 })
    renderPage()
    selectGroup('Shpp Brasil')
    chooseCategory('Compras')
    fireEvent.click(ruleSwitch())
    fireEvent.click(applyButton())

    await vi.waitFor(() => expect(toast.success).toHaveBeenCalledWith('1 lançamento → Compras'))
  })

  it('422 numa condição abre o editor com o erro no campo', async () => {
    applyMutateAsync.mockRejectedValue(
      new ApiError(422, 'Dados inválidos.', null, { 'rule.conditions.0.value': ['Use no máximo 200 caracteres.'] }),
    )
    renderPage()
    selectGroup('Shpp Brasil')
    chooseCategory('Compras')
    fireEvent.click(applyButton())

    const dialog = await screen.findByRole('dialog', { name: 'Regra de categorização' })
    expect(within(dialog).getByText('Use no máximo 200 caracteres.')).toBeInTheDocument()
    const value = within(dialog).getByLabelText('Valor (condição 1 da alternativa 1)')
    expect(value).toHaveAttribute('aria-invalid', 'true')
    expect(toast.error).not.toHaveBeenCalled()

    // Editar a linha tira o erro dela.
    fireEvent.change(value, { target: { value: 'Shpp' } })
    expect(within(dialog).queryByText('Use no máximo 200 caracteres.')).not.toBeInTheDocument()
  })

  it('422 num termo da regra compacta aponta para a alternativa certa no editor', async () => {
    mockPending(manyGroups(3))
    applyMutateAsync.mockRejectedValue(
      new ApiError(422, 'Dados inválidos.', null, { 'rule.conditions.1.conditions.2.value': ['Texto inválido.'] }),
    )
    renderPage()
    for (const name of ['Grupo 1', 'Grupo 2', 'Grupo 3']) selectGroup(name)
    chooseCategory('Compras')
    fireEvent.click(applyButton())

    const dialog = await screen.findByRole('dialog', { name: 'Regra de categorização' })
    expect(within(dialog).getByLabelText('Valor (condição 1 da alternativa 3)')).toHaveAttribute('aria-invalid', 'true')
    expect(within(dialog).getByLabelText('Valor (condição 1 da alternativa 1)')).not.toHaveAttribute('aria-invalid')
  })

  it('com 11 grupos ou mais, a regra padrão sai compacta: a direção uma vez e um termo por grupo', async () => {
    mockPending(manyGroups(12))
    renderPage()
    for (let i = 1; i <= 12; i++) selectGroup(`Grupo ${i}`)
    chooseCategory('Compras')
    expect(ruleSwitch()).toHaveAttribute('aria-checked', 'true')
    fireEvent.click(applyButton())

    await vi.waitFor(() => expect(applyMutateAsync).toHaveBeenCalled())
    expect(lastApplyBody()?.rule).toMatchObject({
      match: 'all',
      conditions: [
        { field: 'direction', op: 'equals', value: 'out' },
        {
          match: 'any',
          conditions: Array.from({ length: 12 }, (_, i) => ({ field: 'original_description', op: 'contains', value: `Grupo ${i + 1}` })),
        },
      ],
    })
  })

  it('acima do limite de grupos, a regra fica desligada e o aviso aparece', async () => {
    mockPending(manyGroups(20))
    renderPage()
    for (let i = 1; i <= 20; i++) selectGroup(`Grupo ${i}`)
    chooseCategory('Compras')

    expect(ruleSwitch()).toHaveAttribute('aria-checked', 'false')
    expect(ruleSwitch()).toBeDisabled()
    expect(ruleSwitch()).toHaveAccessibleDescription(/Regra só para até 19 grupos por vez/)

    fireEvent.click(applyButton())
    await vi.waitFor(() => expect(applyMutateAsync).toHaveBeenCalled())
    expect(lastApplyBody()).not.toHaveProperty('rule')
  })

  it('"só saídas" num grupo misto não desliga a regra', () => {
    mockPending([
      group({
        in_count: 1,
        out_count: 2,
        net: -1000,
        transactions: [tx({ id: 1, description: 'Saída 1' }), tx({ id: 2, description: 'Saída 2' }), tx({ id: 3, direction: 'in', description: 'Entrada' })],
      }),
    ])
    renderPage()
    fireEvent.click(screen.getByRole('button', { name: /Shpp Brasil/ }))
    fireEvent.click(screen.getByText('só saídas (2)'))

    expect(ruleSwitch()).toHaveAttribute('aria-checked', 'true')
  })

  it('trocar o tipo da seleção descarta a categoria escolhida', () => {
    useCategories.mockReturnValue({ data: [category(), category({ id: 60, name: 'Salário', kind: 'income' })] })
    mockPending([
      group(),
      group({ key: 'acme', name: 'ACME', out_count: 0, in_count: 1, net: 5000, transactions: [tx({ id: 9, direction: 'in', description: 'ACME' })] }),
    ])
    renderPage()
    selectGroup('Shpp Brasil')
    chooseCategory('Compras')
    expect(applyButton()).toBeEnabled()

    selectGroup('Shpp Brasil')
    selectGroup('ACME')

    expect(screen.getByRole('combobox', { name: 'Definir categoria' })).toHaveTextContent('Definir categoria')
    expect(applyButton()).toBeDisabled()
  })

  it('com busca ativa, a contagem vem da prévia do servidor', async () => {
    useRulePreview.mockReturnValue({ data: { matched: 9, changed: 7 }, isPending: false, isPlaceholderData: false, isError: false })
    renderPage()
    fireEvent.change(screen.getByRole('textbox', { name: 'Buscar grupo ou descrição' }), { target: { value: 'shpp' } })
    selectGroup('Shpp Brasil')
    chooseCategory('Compras')

    const block = screen.getByText('Criar regra para os próximos').closest('div.rounded-xl') as HTMLElement
    await vi.waitFor(() => expect(block).toHaveTextContent('casa 7 lançamentos sem categoria'), { timeout: 2500 })
    expect(useRulePreview.mock.calls.at(-1)?.[1]).toBe(true)
    expect(applyButton()).toHaveTextContent('Aplicar a 2')
    expect(screen.getByText('+ os que a regra casar')).toBeInTheDocument()

    // Sem aplicar aos pendentes, a regra não categoriza mais ninguém agora: some a dica.
    fireEvent.click(screen.getByRole('checkbox', { name: 'aplicar também a esses' }))
    expect(screen.queryByText('+ os que a regra casar')).not.toBeInTheDocument()
  })

  it('com a resposta truncada, a contagem vem da prévia do servidor', async () => {
    useRulePreview.mockReturnValue({ data: { matched: 40, changed: 30 }, isPending: false, isPlaceholderData: false, isError: false })
    const groups = [group()]
    usePendingCategorization.mockReturnValue({
      data: { total_pending: 6000, group_count: 1, truncated: true, groups },
      isPending: false,
      isError: false,
      isPlaceholderData: false,
      refetch: vi.fn(),
    })
    renderPage()
    selectGroup('Shpp Brasil')
    chooseCategory('Compras')

    const block = screen.getByText('Criar regra para os próximos').closest('div.rounded-xl') as HTMLElement
    await vi.waitFor(() => expect(block).toHaveTextContent('casa 30 lançamentos sem categoria'), { timeout: 2500 })
    expect(useRulePreview.mock.calls.at(-1)?.[1]).toBe(true)
    expect(screen.getByText('+ os que a regra casar')).toBeInTheDocument()
  })

  it('mudar a seleção descarta a regra personalizada, mesmo voltando à seleção anterior', () => {
    renderPage()
    selectGroup('Shpp Brasil')
    fireEvent.click(screen.getByRole('button', { name: 'Personalizar' }))
    fireEvent.change(within(screen.getByRole('dialog')).getByLabelText('Valor (condição 1 da alternativa 1)'), { target: { value: 'SHPP' } })
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Usar esta regra' }))
    expect(screen.getByRole('button', { name: 'Editar regra' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Shpp Brasil/ }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Selecionar Pix enviado - Shpp Brasil 2' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Selecionar Pix enviado - Shpp Brasil 2' }))

    expect(screen.getByRole('button', { name: 'Personalizar' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Editar regra' })).not.toBeInTheDocument()
  })

  it('o editor avisa quando a condição de tipo sai da regra', () => {
    renderPage()
    selectGroup('Shpp Brasil')
    fireEvent.click(screen.getByRole('button', { name: 'Personalizar' }))
    const dialog = screen.getByRole('dialog')
    expect(within(dialog).queryByText(/também vai casar entradas/)).not.toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Remover condição 2 da alternativa 1' }))
    expect(within(dialog).getByText(/também vai casar entradas/)).toBeInTheDocument()
  })

  it('outro erro avisa por toast e mantém a seleção', async () => {
    applyMutateAsync.mockRejectedValue(new ApiError(409, 'Algo mudou.', 'conflict'))
    renderPage()
    selectGroup('Shpp Brasil')
    chooseCategory('Compras')
    fireEvent.click(applyButton())

    await vi.waitFor(() => expect(toast.error).toHaveBeenCalledWith('Algo mudou.'))
    expect(screen.getByText('2 selecionados')).toBeInTheDocument()
  })

  it('fechar limpa a seleção', () => {
    renderPage()
    selectGroup('Shpp Brasil')
    fireEvent.click(screen.getByRole('button', { name: 'Cancelar seleção' }))

    expect(screen.queryByText('2 selecionados')).not.toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'Selecionar grupo Shpp Brasil' })).toHaveAttribute('aria-checked', 'false')
  })
})
