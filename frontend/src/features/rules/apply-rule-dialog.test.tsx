import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Rule } from '@/api/types'
import { ApplyRuleDialog } from './apply-rule-dialog'

const applyMutateAsync = vi.fn()
let polledRuleData: Rule | undefined
let applyIsPending = false
let previewResult: { data: { matched: number; changed: number; sample: unknown[] } | undefined; isPending: boolean; isPlaceholderData: boolean }

vi.mock('@/api/queries/rules', () => ({
  useApplyRule: () => ({ mutateAsync: applyMutateAsync, isPending: applyIsPending }),
  useRule: () => ({ data: polledRuleData }),
  useRulePreview: () => previewResult,
}))

vi.mock('sonner', () => ({
  toast: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }),
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

function renderDialog(props: Partial<React.ComponentProps<typeof ApplyRuleDialog>> = {}) {
  const client = new QueryClient()
  const element = (overrideProps: Partial<React.ComponentProps<typeof ApplyRuleDialog>>) => (
    <QueryClientProvider client={client}>
      <ApplyRuleDialog rule={rule()} overwrite={false} onOverwriteChange={vi.fn()} disabled={false} {...overrideProps} />
    </QueryClientProvider>
  )
  const result = render(element(props))
  return { ...result, client, rerenderWith: (next: Partial<React.ComponentProps<typeof ApplyRuleDialog>>) => result.rerender(element(next)) }
}

beforeEach(() => {
  applyMutateAsync.mockReset()
  polledRuleData = undefined
  applyIsPending = false
  previewResult = { data: { matched: 10, changed: 7, sample: [] }, isPending: false, isPlaceholderData: false }
  vi.mocked(toast).mockClear()
  vi.mocked(toast.success).mockClear()
})

describe('ApplyRuleDialog', () => {
  it('confirmar chama a mutação com overwrite', async () => {
    applyMutateAsync.mockResolvedValue({ queued: true })
    renderDialog({ overwrite: true })

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar às existentes' }))
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }))

    await waitFor(() => expect(applyMutateAsync).toHaveBeenCalledWith({ id: 5, body: { overwrite: true } }))
  })

  it('com o useRule devolvendo last_applied_at novo, mostra o toast de sucesso', async () => {
    applyMutateAsync.mockResolvedValue({ queued: true })
    const { rerenderWith } = renderDialog()

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar às existentes' }))
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }))

    await waitFor(() => expect(applyMutateAsync).toHaveBeenCalled())
    expect(screen.getByText('Aplicando…')).toBeInTheDocument()

    // Simula o próximo tick do polling: `useRule` já devolve a regra com `last_applied_at` novo.
    polledRuleData = rule({ last_applied_at: '2026-10-03T12:00:00+00:00', last_applied_changes: 7 })
    rerenderWith({})

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith('Regra aplicada: 7 alterados.'))
  })

  it('botão desabilitado com formulário sujo', () => {
    renderDialog({ disabled: true })

    expect(screen.getByRole('button', { name: 'Aplicar às existentes' })).toBeDisabled()
    expect(screen.getByText('Salve a regra antes de aplicar.')).toBeInTheDocument()
  })

  it('mostra "calculando" em vez de "cerca de 0" enquanto a prévia ainda não chegou', () => {
    previewResult = { data: undefined, isPending: true, isPlaceholderData: false }
    renderDialog({})

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar às existentes' }))

    expect(screen.getByText('Calculando quantos lançamentos seriam alterados…')).toBeInTheDocument()
    expect(screen.queryByText(/cerca de 0/)).not.toBeInTheDocument()
  })

  it('não dispara duas aplicações quando a mutação já está em voo (guard de isPending)', () => {
    applyIsPending = true
    renderDialog({})

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar às existentes' }))
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }))

    expect(applyMutateAsync).not.toHaveBeenCalled()
  })

  it('se a página desmontar ainda aplicando, invalida ledger e regras mesmo sem o fim chegar', async () => {
    applyMutateAsync.mockResolvedValue({ queued: true })
    const { client, unmount } = renderDialog({})
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar às existentes' }))
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }))

    await waitFor(() => expect(applyMutateAsync).toHaveBeenCalled())
    expect(screen.getByText('Aplicando…')).toBeInTheDocument()

    unmount()

    expect(invalidateSpy).toHaveBeenCalled()
  })
})
