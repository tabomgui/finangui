import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { CategoryChangeItem, RuleReconcile } from '@/api/types'
import { ReconcileRuleDialog } from './reconcile-rule-dialog'

const applyMutateAsync = vi.fn()

vi.mock('@/api/queries/rules', () => ({
  useApplyRuleReconcile: () => ({ mutateAsync: applyMutateAsync, isPending: false }),
}))

vi.mock('@/api/queries/categories', () => ({
  useCategories: () => ({
    data: [
      { id: 1, parent_id: null, name: 'Transporte' },
      { id: 2, parent_id: 1, name: 'Aplicativo' },
      { id: 3, parent_id: null, name: 'Lazer' },
    ],
    isPending: false,
  }),
}))

vi.mock('sonner', () => ({
  toast: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }),
}))

function item(overrides: Partial<CategoryChangeItem> = {}): CategoryChangeItem {
  return {
    transaction_id: 1,
    date: '2026-09-10',
    description: 'Corrida teste',
    account_name: 'Conta exemplo',
    amount: 2350,
    direction: 'out',
    ...overrides,
  }
}

function reconcile(groups: RuleReconcile['groups']): RuleReconcile {
  return { max_changes: 5000, groups }
}

const onClose = vi.fn()

function renderDialog(data: RuleReconcile) {
  return render(<ReconcileRuleDialog ruleId={5} reconcile={data} onClose={onClose} />)
}

beforeEach(() => {
  applyMutateAsync.mockReset()
  onClose.mockReset()
  vi.mocked(toast).mockClear()
  vi.mocked(toast.success).mockClear()
  vi.mocked(toast.error).mockClear()
})

describe('ReconcileRuleDialog', () => {
  it('mostra só os grupos com lançamentos, todos marcados, e soma no botão', () => {
    renderDialog(
      reconcile([
        { key: 'recategorize', count: 3, sample: [] },
        { key: 'fill', count: 0, sample: [] },
        { key: 'clear', count: 2, reason: 'no_longer_matches', sample: [] },
      ]),
    )

    expect(screen.getByText('Atualizar lançamentos desta regra?')).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'Categorizados por esta regra que mudam de categoria' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Categorizados por esta regra que não casam mais (voltam para Categorizar)' })).toBeChecked()
    expect(screen.queryByRole('checkbox', { name: 'Sem categoria que agora casam' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Atualizar 5 lançamentos' })).toBeEnabled()
  })

  it('regra inativa: o grupo de tirar a categoria começa desmarcado', () => {
    renderDialog(reconcile([{ key: 'clear', count: 4, reason: 'inactive', sample: [] }]))

    expect(screen.getByRole('checkbox', { name: 'Regra inativa: remover a categoria que ela deu' })).not.toBeChecked()
    expect(screen.getByRole('button', { name: 'Atualizar 0 lançamentos' })).toBeDisabled()
  })

  it('regra sem ação de categoria usa o rótulo próprio', () => {
    renderDialog(reconcile([{ key: 'clear', count: 1, reason: 'no_category_action', sample: [] }]))

    expect(screen.getByRole('checkbox', { name: /a regra não define mais categoria/ })).toBeChecked()
    expect(screen.getByRole('button', { name: 'Atualizar 1 lançamento' })).toBeEnabled()
  })

  it('a amostra abre com "atual → nova"', () => {
    renderDialog(
      reconcile([
        {
          key: 'recategorize',
          count: 25,
          sample: [item({ current_category_id: 3, new_category_id: 2 })],
        },
      ]),
    )

    expect(screen.queryByText('Corrida teste')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Ver exemplos' }))

    expect(screen.getByText('Corrida teste')).toBeInTheDocument()
    expect(screen.getByText('Lazer')).toBeInTheDocument()
    expect(screen.getByText('Transporte › Aplicativo')).toBeInTheDocument()
    expect(screen.getByText('e mais 24 lançamentos')).toBeInTheDocument()
  })

  it('envia só os grupos marcados e mostra o resultado', async () => {
    applyMutateAsync.mockResolvedValue({ recategorize: 3, fill: 0, clear: 0, skipped: 1 })
    renderDialog(
      reconcile([
        { key: 'recategorize', count: 3, sample: [] },
        { key: 'fill', count: 6, sample: [] },
        { key: 'clear', count: 2, reason: 'inactive', sample: [] },
      ]),
    )

    fireEvent.click(screen.getByRole('checkbox', { name: 'Sem categoria que agora casam' }))
    fireEvent.click(screen.getByRole('button', { name: 'Atualizar 3 lançamentos' }))

    await waitFor(() => expect(applyMutateAsync).toHaveBeenCalledWith({ id: 5, body: { groups: ['recategorize'] } }))
    expect(toast.success).toHaveBeenCalledWith('3 lançamentos atualizados · 1 ignorado porque mudou')
    expect(onClose).toHaveBeenCalled()
  })

  it('409 acima do limite mostra a mensagem e mantém o diálogo aberto', async () => {
    applyMutateAsync.mockRejectedValue(
      new ApiError(409, 'São alterações demais de uma vez (máximo de 5000).', 'too_many_category_changes'),
    )
    renderDialog(reconcile([{ key: 'fill', count: 6000, sample: [] }]))

    fireEvent.click(screen.getByRole('button', { name: 'Atualizar 6000 lançamentos' }))

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('São alterações demais de uma vez (máximo de 5000).'))
    expect(onClose).not.toHaveBeenCalled()
    expect(screen.getByText('Atualizar lançamentos desta regra?')).toBeInTheDocument()
  })

  it('"Agora não" fecha sem aplicar', () => {
    renderDialog(reconcile([{ key: 'fill', count: 2, sample: [] }]))

    fireEvent.click(screen.getByRole('button', { name: 'Agora não' }))

    expect(onClose).toHaveBeenCalled()
    expect(applyMutateAsync).not.toHaveBeenCalled()
  })
})
