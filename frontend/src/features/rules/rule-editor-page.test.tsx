import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { Category, Rule, Transaction } from '@/api/types'
import { RuleEditorPage } from './rule-editor-page'

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const createMutateAsync = vi.fn()
const updateMutateAsync = vi.fn()
const deleteMutateAsync = vi.fn()

let mockRule: Rule | undefined
let mockRuleError = false

vi.mock('@/api/queries/rules', () => ({
  useRule: () => ({
    data: mockRule,
    isPending: false,
    isError: mockRuleError,
    error: mockRuleError ? new ApiError(404, 'Não encontrado.') : null,
    refetch: vi.fn(),
  }),
  useCreateRule: () => ({ mutateAsync: createMutateAsync }),
  useUpdateRule: () => ({ mutateAsync: updateMutateAsync }),
  useDeleteRule: () => ({ mutateAsync: deleteMutateAsync }),
  // A prévia ao vivo e o diálogo de aplicar têm testes próprios (rule-preview-card.test.tsx,
  // apply-rule-dialog.test.tsx); aqui só o suficiente para RuleEditorPage renderizar sem rede.
  useRulePreview: () => ({ data: undefined, isPending: false, isPlaceholderData: false, isError: false, error: null, refetch: vi.fn() }),
  useApplyRule: () => ({ mutateAsync: vi.fn() }),
}))

let mockTransaction: Transaction | undefined
let mockTransactionError = false

vi.mock('@/api/queries/transactions', () => ({
  useTransaction: () => ({
    data: mockTransaction,
    isPending: mockTransaction === undefined && !mockTransactionError,
    isError: mockTransactionError,
  }),
}))

const categories: Category[] = [
  {
    id: 1,
    parent_id: null,
    name: 'Transporte',
    kind: 'expense',
    icon: null,
    color: null,
    is_transfer: false,
    is_transfer_effective: false,
    is_archived: false,
  },
]

vi.mock('@/api/queries/categories', () => ({
  useCategories: () => ({ data: categories }),
}))

vi.mock('@/api/queries/tags', () => ({
  useTags: () => ({ data: [{ id: 1, name: 'viagem' }] }),
}))

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ data: [] }),
}))

function rule(overrides: Partial<Rule> = {}): Rule {
  return {
    id: 5,
    name: 'Uber',
    priority: 1,
    is_active: true,
    match: 'all',
    conditions: [{ field: 'description', op: 'contains', value: 'uber' }],
    actions: [{ type: 'set_category', category_id: 1 }],
    last_applied_at: null,
    last_applied_changes: null,
    ...overrides,
  }
}

function transaction(overrides: Partial<Transaction> = {}): Transaction {
  return {
    id: 10,
    account_id: 1,
    date: '2026-01-05',
    amount: 1500,
    direction: 'out',
    currency: 'BRL',
    description: 'Uber viagem',
    original_description: 'UBER* VIAGEM',
    description_locked: false,
    notes: null,
    payee: null,
    category_id: null,
    category: null,
    tags: [],
    status: 'posted',
    source: 'manual',
    categorized_by: null,
    categorization: null,
    transfer_id: null,
    statement_id: null,
    is_ignored: false,
    installment: null,
    ...overrides,
  } as Transaction
}

function renderPage(initialEntries: NonNullable<Parameters<typeof MemoryRouter>[0]['initialEntries']>) {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={initialEntries}>
        <Routes>
          <Route path="/regras/nova" element={<RuleEditorPage />} />
          <Route path="/regras/:id" element={<RuleEditorPage />} />
          <Route path="/regras" element={<div>Lista de regras</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function chooseCategory(name: string) {
  fireEvent.click(screen.getByLabelText('Categoria'))
  fireEvent.click(screen.getByText(name))
}

beforeEach(() => {
  mockRule = undefined
  mockRuleError = false
  mockTransaction = undefined
  mockTransactionError = false
  createMutateAsync.mockReset()
  updateMutateAsync.mockReset()
  deleteMutateAsync.mockReset()
  vi.mocked(toast.error).mockReset()
  vi.mocked(toast.success).mockReset()
})

describe('RuleEditorPage', () => {
  it('criação: envia o corpo esperado e navega para a regra criada', async () => {
    const created = rule({ id: 42 })
    createMutateAsync.mockImplementation(async () => {
      mockRule = created
      return created
    })
    renderPage(['/regras/nova'])

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Uber' } })
    fireEvent.change(screen.getByLabelText('Valor', { exact: false }), { target: { value: 'uber' } })
    chooseCategory('Transporte')

    fireEvent.click(screen.getByRole('button', { name: 'Salvar regra' }))

    await waitFor(() =>
      expect(createMutateAsync).toHaveBeenCalledWith({
        name: 'Uber',
        is_active: true,
        match: 'all',
        conditions: [{ field: 'description', op: 'contains', value: 'uber' }],
        actions: [{ type: 'set_category', category_id: 1 }],
      }),
    )
    expect(toast.success).toHaveBeenCalledWith('Regra salva.')
    expect(await screen.findByText('Editar regra')).toBeInTheDocument()
  })

  it('prefill por "?transacao=": condição pela descrição e categoria da transação', () => {
    mockTransaction = transaction({ description: 'Uber viagem', category_id: 1 })

    renderPage(['/regras/nova?transacao=10'])

    expect(screen.getByLabelText('Nome')).toHaveValue('Uber viagem')
    expect(screen.getByLabelText('Valor', { exact: false })).toHaveValue('Uber viagem')
    expect(screen.getByText('Transporte')).toBeInTheDocument()
  })

  it('422 em conditions.0.value aparece no campo', async () => {
    createMutateAsync.mockRejectedValue(new ApiError(422, 'Dados inválidos.', null, { 'conditions.0.value': ['Informe um texto válido.'] }))
    renderPage(['/regras/nova'])

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Uber' } })
    fireEvent.change(screen.getByLabelText('Valor', { exact: false }), { target: { value: 'uber' } })
    chooseCategory('Transporte')

    fireEvent.click(screen.getByRole('button', { name: 'Salvar regra' }))

    expect(await screen.findByText('Informe um texto válido.')).toBeInTheDocument()
  })

  it('trocar o campo de uma condição de texto para amount troca o operador e o controle', async () => {
    renderPage(['/regras/nova'])

    const trigger = screen.getByLabelText('Campo', { exact: false })
    fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
    fireEvent.click(trigger)
    fireEvent.click(await screen.findByRole('option', { name: 'Valor' }))

    expect(screen.getByLabelText('Operador', { exact: false })).toHaveTextContent('é igual a')
    expect(screen.getByLabelText('Valor', { exact: false })).toHaveAttribute('inputmode', 'decimal')
  })

  it('editar: carrega a regra existente', () => {
    mockRule = rule({ name: 'Mercado', conditions: [{ field: 'description', op: 'contains', value: 'mercado' }] })

    renderPage(['/regras/5'])

    expect(screen.getByText('Editar regra')).toBeInTheDocument()
    expect(screen.getByLabelText('Nome')).toHaveValue('Mercado')
    expect(screen.getByLabelText('Valor', { exact: false })).toHaveValue('mercado')
  })

  it('editar: regra não encontrada mostra o toast e volta para a lista', async () => {
    mockRuleError = true

    renderPage(['/regras/999'])

    await waitFor(() => expect(screen.getByText('Lista de regras')).toBeInTheDocument())
    expect(toast.error).toHaveBeenCalledWith('Regra não encontrada.')
  })

  it('editar: depois de salvar, o formulário deixa de estar sujo e "Aplicar às existentes" habilita (C2)', async () => {
    const original = rule()
    mockRule = original
    const saved = rule({ name: 'Uber viagens' })
    updateMutateAsync.mockImplementation(async () => saved)

    renderPage(['/regras/5'])

    // Recém-carregada, sem edição: o formulário ainda não está "sujo".
    expect(screen.getByRole('button', { name: 'Aplicar às existentes' })).not.toBeDisabled()

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Uber viagens' } })
    expect(screen.getByRole('button', { name: 'Aplicar às existentes' })).toBeDisabled()
    expect(screen.getByText('Salve a regra antes de aplicar.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Salvar regra' }))

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith('Regra salva.'))
    expect(screen.getByRole('button', { name: 'Aplicar às existentes' })).not.toBeDisabled()
    expect(screen.queryByText('Salve a regra antes de aplicar.')).not.toBeInTheDocument()
  })

  it('422 num caminho sem campo visível (tipo da ação) cai no toast genérico (I1)', async () => {
    createMutateAsync.mockRejectedValue(new ApiError(422, 'Dados inválidos.', null, { 'actions.0.type': ['Tipo inválido.'] }))
    renderPage(['/regras/nova'])

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Uber' } })
    fireEvent.change(screen.getByLabelText('Valor', { exact: false }), { target: { value: 'uber' } })
    chooseCategory('Transporte')

    fireEvent.click(screen.getByRole('button', { name: 'Salvar regra' }))

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Dados inválidos.'))
  })

  it('erro de servidor na lista de um grupo ("máximo de condições") aparece junto do grupo (I1)', async () => {
    mockRule = rule({
      conditions: [
        { field: 'description', op: 'contains', value: 'uber' },
        { match: 'all', conditions: [{ field: 'amount', op: 'gt', value: 1000 }] },
      ],
    })
    updateMutateAsync.mockRejectedValue(
      new ApiError(422, 'Dados inválidos.', null, { 'conditions.1.conditions': ['Máximo de condições no grupo.'] }),
    )

    renderPage(['/regras/5'])

    fireEvent.click(screen.getByRole('button', { name: 'Salvar regra' }))

    expect(await screen.findByText('Máximo de condições no grupo.')).toBeInTheDocument()
    expect(toast.error).not.toHaveBeenCalled()
  })

  it('dois grupos: o 422 no segundo grupo cai no campo certo, não no do primeiro grupo (I2)', async () => {
    mockRule = rule({
      conditions: [
        { field: 'description', op: 'contains', value: 'uber' },
        { match: 'all', conditions: [{ field: 'amount', op: 'gt', value: 100 }] },
        { match: 'any', conditions: [{ field: 'amount', op: 'gt', value: 200 }] },
      ],
    })
    updateMutateAsync.mockRejectedValue(new ApiError(422, 'Dados inválidos.', null, { 'conditions.2.conditions.0.value': ['Valor inválido.'] }))

    renderPage(['/regras/5'])

    fireEvent.click(screen.getByRole('button', { name: 'Salvar regra' }))

    const secondGroupValue = await screen.findByLabelText('Valor (condição 1 do grupo 3)', { exact: false })
    expect(secondGroupValue).toHaveAttribute('aria-invalid', 'true')

    const firstGroupValue = screen.getByLabelText('Valor (condição 1 do grupo 2)', { exact: false })
    expect(firstGroupValue).not.toHaveAttribute('aria-invalid', 'true')

    expect(screen.getByText('Valor inválido.')).toBeInTheDocument()
  })

  it('?transacao= de um lançamento inexistente avisa e mantém o formulário vazio (I6)', async () => {
    mockTransactionError = true

    renderPage(['/regras/nova?transacao=999'])

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Lançamento não encontrado.'))
    expect(screen.getByLabelText('Nome')).toHaveValue('')
  })

  it('?transacao= com valor inválido (não inteiro positivo) avisa sem tentar buscar (I6)', async () => {
    renderPage(['/regras/nova?transacao=abc'])

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Lançamento não encontrado.'))
    expect(screen.getByLabelText('Nome')).toHaveValue('')
  })
})
