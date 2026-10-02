import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'
import type { Transaction } from '@/api/types'
import { BulkActionBar } from './bulk-action-bar'

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, PATCH: vi.fn() } }
})

const { api } = await import('@/api/client')

const transaction = (overrides: Partial<Transaction>): Transaction => ({
  id: 1,
  account_id: 1,
  date: '2026-01-01',
  amount: 1000,
  direction: 'out',
  currency: 'BRL',
  description: 'Lançamento',
  original_description: 'Lançamento',
  description_locked: false,
  notes: null,
  payee: null,
  category_id: null,
  category: null,
  tags: [],
  status: 'posted',
  source: 'manual',
  categorized_by: null,
  is_ignored: false,
  transfer_id: null,
  statement_id: null,
  installment: null,
  ...overrides,
})

function renderBar(selected: Transaction[], onDone: () => void) {
  const client = new QueryClient()
  client.setQueryData(queryKeys.tags(), [{ id: 7, name: 'casa', color: null }])

  return render(
    <QueryClientProvider client={client}>
      <BulkActionBar selected={selected} onDone={onDone} />
    </QueryClientProvider>,
  )
}

type PatchResult = { data?: unknown; error?: unknown; response: Response }
type PatchArgs = { params: { path: { transaction: number } } }

describe('BulkActionBar', () => {
  it('ao falhar uma das atualizações, mostra o toast de erro com a contagem e ainda assim encerra a seleção', async () => {
    // `api.PATCH` é sobrecarregado para todos os endpoints da API; forçamos o tipo aqui porque
    // este teste só chama a rota de transações.
    const patch = vi.mocked<(url: string, options: PatchArgs) => Promise<PatchResult>>(api.PATCH as never)
    patch.mockImplementation((_url, options) => {
      const id = options.params.path.transaction
      return Promise.resolve(
        id === 2
          ? { data: undefined, error: { message: 'erro' }, response: { ok: false, status: 422 } as Response }
          : { data: { data: {} }, error: undefined, response: { ok: true, status: 200 } as Response },
      )
    })

    const onDone = vi.fn()
    renderBar([transaction({ id: 1 }), transaction({ id: 2 })], onDone)

    const trigger = screen.getByRole('button', { name: 'Adicionar tag' })
    fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
    fireEvent.click(trigger)
    fireEvent.click(await screen.findByRole('menuitem', { name: '#casa' }))

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Tag: 1 transação de 2 não pôde ser atualizada.'))
    expect(onDone).toHaveBeenCalledTimes(1)
  })
})
