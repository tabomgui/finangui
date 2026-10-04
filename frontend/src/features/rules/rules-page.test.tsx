import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Rule } from '@/api/types'
import { RulesPage } from './rules-page'

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const refetch = vi.fn()
const updateMutateAsync = vi.fn()
const deleteMutateAsync = vi.fn()
const reorderMutate = vi.fn()

let rulesState: { data: Rule[] | undefined; isPending: boolean; isError: boolean }
let updateState: { isPending: boolean; variables?: { id: number } }

vi.mock('@/api/queries/rules', () => ({
  useRules: () => ({ ...rulesState, refetch }),
  useUpdateRule: () => ({ mutateAsync: updateMutateAsync, ...updateState }),
  useDeleteRule: () => ({ mutateAsync: deleteMutateAsync, isPending: false }),
  useReorderRules: () => ({ mutate: reorderMutate, isPending: false }),
}))

vi.mock('@/api/queries/categories', () => ({
  useCategories: () => ({ data: [{ id: 1, name: 'Transporte' }, { id: 2, name: 'Lazer', is_archived: true }] }),
}))

vi.mock('@/api/queries/tags', () => ({
  useTags: () => ({ data: [{ id: 1, name: 'viagem' }] }),
}))

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ data: [{ id: 1, name: 'Nubank' }] }),
}))

function rule(overrides: Partial<Rule>): Rule {
  return {
    id: 1,
    name: 'Regra',
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

function renderPage() {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/regras']}>
        <RulesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  refetch.mockReset()
  updateMutateAsync.mockReset().mockResolvedValue(undefined)
  deleteMutateAsync.mockReset().mockResolvedValue(undefined)
  reorderMutate.mockReset()
  vi.mocked(toast.success).mockReset()
  rulesState = { data: undefined, isPending: true, isError: false }
  updateState = { isPending: false }
})

describe('RulesPage', () => {
  it('mostra skeletons enquanto carrega', () => {
    const { container } = renderPage()
    expect(screen.queryByText('Nenhuma regra ainda')).not.toBeInTheDocument()
    expect(container.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(3)
  })

  it('renderiza nomes e resumos das regras na ordem recebida', () => {
    rulesState = {
      data: [
        rule({ id: 1, name: 'Uber', priority: 1 }),
        rule({ id: 2, name: 'Mercado', priority: 2, conditions: [{ field: 'description', op: 'contains', value: 'mercado' }] }),
      ],
      isPending: false,
      isError: false,
    }
    renderPage()

    const names = screen.getAllByRole('link', { name: /Uber|Mercado/ }).map((link) => link.textContent)
    expect(names).toEqual(['Uber', 'Mercado'])
    expect(screen.getByText('Descrição contém “uber” → Transporte')).toBeInTheDocument()
  })

  it('mostra "(arquivada)" no resumo quando a categoria da ação está arquivada', () => {
    rulesState = {
      data: [rule({ id: 1, name: 'Lazer antigo', actions: [{ type: 'set_category', category_id: 2 }] })],
      isPending: false,
      isError: false,
    }
    renderPage()

    expect(screen.getByText('Descrição contém “uber” → Lazer (arquivada)')).toBeInTheDocument()
  })

  it('chama update com is_active: false ao desligar o switch', () => {
    rulesState = { data: [rule({ id: 1, name: 'Uber' })], isPending: false, isError: false }
    renderPage()

    fireEvent.click(screen.getByRole('switch', { name: 'Ativar Uber' }))

    expect(updateMutateAsync).toHaveBeenCalledWith({ id: 1, body: { is_active: false } })
  })

  it('desabilita só o switch da regra cuja troca de ativa está em voo', () => {
    rulesState = {
      data: [rule({ id: 1, name: 'Uber' }), rule({ id: 2, name: 'Mercado' })],
      isPending: false,
      isError: false,
    }
    updateState = { isPending: true, variables: { id: 1 } }
    renderPage()

    expect(screen.getByRole('switch', { name: 'Ativar Uber' })).toBeDisabled()
    expect(screen.getByRole('switch', { name: 'Ativar Mercado' })).not.toBeDisabled()
  })

  it('exclui a regra após confirmar no diálogo', async () => {
    rulesState = { data: [rule({ id: 1, name: 'Uber' })], isPending: false, isError: false }
    renderPage()

    const trigger = screen.getByRole('button', { name: 'Ações da regra Uber' })
    fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
    fireEvent.click(trigger)
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Excluir' }))

    fireEvent.click(await screen.findByRole('button', { name: 'Excluir' }))

    await waitFor(() => expect(deleteMutateAsync).toHaveBeenCalledWith(1))
    expect(toast.success).toHaveBeenCalledWith('Regra excluída.')
  })

  it('mostra o estado vazio quando não há regras', () => {
    rulesState = { data: [], isPending: false, isError: false }
    renderPage()

    expect(screen.getByText('Nenhuma regra ainda')).toBeInTheDocument()
  })

  it('mostra erro com opção de tentar de novo quando a busca falha', () => {
    rulesState = { data: undefined, isPending: false, isError: true }
    renderPage()

    expect(screen.getByText('Não foi possível carregar as regras.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetch).toHaveBeenCalled()
  })
})
