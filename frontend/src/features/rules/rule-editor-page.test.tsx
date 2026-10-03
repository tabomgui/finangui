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

vi.mock('@/api/queries/transactions', () => ({
  useTransaction: () => ({ data: mockTransaction, isPending: mockTransaction === undefined }),
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
    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: 'uber' } })
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
    expect(screen.getByLabelText('Valor')).toHaveValue('Uber viagem')
    expect(screen.getByText('Transporte')).toBeInTheDocument()
  })

  it('422 em conditions.0.value aparece no campo', async () => {
    createMutateAsync.mockRejectedValue(new ApiError(422, 'Dados inválidos.', null, { 'conditions.0.value': ['Informe um texto válido.'] }))
    renderPage(['/regras/nova'])

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Uber' } })
    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: 'uber' } })
    chooseCategory('Transporte')

    fireEvent.click(screen.getByRole('button', { name: 'Salvar regra' }))

    expect(await screen.findByText('Informe um texto válido.')).toBeInTheDocument()
  })

  it('trocar o campo de uma condição de texto para amount troca o operador e o controle', async () => {
    renderPage(['/regras/nova'])

    const trigger = screen.getByLabelText('Campo')
    fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
    fireEvent.click(trigger)
    fireEvent.click(await screen.findByRole('option', { name: 'Valor' }))

    expect(screen.getByLabelText('Operador')).toHaveTextContent('é igual a')
    expect(screen.getByLabelText('Valor')).toHaveAttribute('inputmode', 'decimal')
  })

  it('editar: carrega a regra existente', () => {
    mockRule = rule({ name: 'Mercado', conditions: [{ field: 'description', op: 'contains', value: 'mercado' }] })

    renderPage(['/regras/5'])

    expect(screen.getByText('Editar regra')).toBeInTheDocument()
    expect(screen.getByLabelText('Nome')).toHaveValue('Mercado')
    expect(screen.getByLabelText('Valor')).toHaveValue('mercado')
  })

  it('editar: regra não encontrada mostra o toast e volta para a lista', async () => {
    mockRuleError = true

    renderPage(['/regras/999'])

    await waitFor(() => expect(screen.getByText('Lista de regras')).toBeInTheDocument())
    expect(toast.error).toHaveBeenCalledWith('Regra não encontrada.')
  })
})
