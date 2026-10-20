import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { Rule } from '@/api/types'
import { ApplyRuleDialog } from './apply-rule-dialog'

const applyMutateAsync = vi.fn()
let polledRuleData: Rule | undefined
let applyIsPending = false
type PreviewResult = { data: { matched: number; changed: number; sample: unknown[] } | undefined; isPending: boolean; isPlaceholderData: boolean }
let previewResult: PreviewResult
/** Resultado da prévia quando o corpo pede `overwrite: true` (senão, `previewResult`). */
let overwritePreviewResult: PreviewResult | undefined
const useRulePreviewMock = vi.fn((body: { overwrite?: boolean }, _enabled: boolean) =>
  body.overwrite ? (overwritePreviewResult ?? previewResult) : previewResult,
)

vi.mock('@/api/queries/rules', () => ({
  useApplyRule: () => ({ mutateAsync: applyMutateAsync, isPending: applyIsPending }),
  useRule: () => ({ data: polledRuleData }),
  useRulePreview: (body: { overwrite?: boolean }, enabled: boolean) => useRulePreviewMock(body, enabled),
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
      <ApplyRuleDialog rule={rule()} disabled={false} {...overrideProps} />
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
  overwritePreviewResult = undefined
  useRulePreviewMock.mockClear()
  vi.mocked(toast).mockClear()
  vi.mocked(toast.success).mockClear()
})

describe('ApplyRuleDialog', () => {
  it('abre em "Só os lançamentos sem categoria", com a nota sobre os feitos à mão e a contagem dessa opção', () => {
    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar às existentes' }))

    expect(screen.getByRole('radio', { name: 'Só os lançamentos sem categoria' })).toBeChecked()
    expect(screen.getByRole('radio', { name: /Também corrigir os que foram categorizados sozinhos/ })).not.toBeChecked()
    expect(screen.getByText('Os que você categorizou à mão nunca mudam.')).toBeInTheDocument()
    expect(screen.getByText('Isso vai alterar cerca de 7 lançamentos.')).toBeInTheDocument()
    expect(useRulePreviewMock).toHaveBeenLastCalledWith(expect.not.objectContaining({ overwrite: expect.anything() }), true)
  })

  it('confirmar sem trocar a opção aplica com overwrite false', async () => {
    applyMutateAsync.mockResolvedValue({ queued: true })
    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar às existentes' }))
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }))

    await waitFor(() => expect(applyMutateAsync).toHaveBeenCalledWith({ id: 5, body: { overwrite: false } }))
  })

  it('"Também trocar categorias automáticas" mostra a contagem com overwrite e aplica com overwrite true', async () => {
    applyMutateAsync.mockResolvedValue({ queued: true })
    overwritePreviewResult = { data: { matched: 10, changed: 9, sample: [] }, isPending: false, isPlaceholderData: false }
    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar às existentes' }))
    fireEvent.click(screen.getByRole('radio', { name: /Também corrigir os que foram categorizados sozinhos/ }))

    expect(screen.getByRole('radio', { name: /Também corrigir os que foram categorizados sozinhos/ })).toBeChecked()
    expect(screen.getByText('Isso vai alterar cerca de 9 lançamentos.')).toBeInTheDocument()
    expect(useRulePreviewMock).toHaveBeenLastCalledWith(expect.objectContaining({ overwrite: true }), true)

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }))

    await waitFor(() => expect(applyMutateAsync).toHaveBeenCalledWith({ id: 5, body: { overwrite: true } }))
  })

  it('com o diálogo fechado, a prévia de contagem não roda', () => {
    renderDialog()

    expect(useRulePreviewMock).toHaveBeenLastCalledWith(expect.anything(), false)
  })

  it('reabrir o diálogo volta para "Só os lançamentos sem categoria"', () => {
    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar às existentes' }))
    fireEvent.click(screen.getByRole('radio', { name: /Também corrigir os que foram categorizados sozinhos/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }))
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar às existentes' }))

    expect(screen.getByRole('radio', { name: 'Só os lançamentos sem categoria' })).toBeChecked()
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

  it('409 rule_apply_in_progress: continua sondando em vez de voltar ao botão com erro', async () => {
    applyMutateAsync.mockRejectedValue(new ApiError(409, 'Esta regra já está sendo aplicada. Aguarde terminar.', 'rule_apply_in_progress'))
    renderDialog({})

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar às existentes' }))
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }))

    await waitFor(() => expect(applyMutateAsync).toHaveBeenCalled())
    expect(screen.getByText('Aplicando…')).toBeInTheDocument()
    expect(toast.error).not.toHaveBeenCalled()
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
