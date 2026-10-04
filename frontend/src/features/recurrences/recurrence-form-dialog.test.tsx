import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { Recurrence } from '@/api/types'
import { RecurrenceFormDialog } from './recurrence-form-dialog'

const createMutateAsync = vi.fn()
const updateMutateAsync = vi.fn()

vi.mock('@/api/queries/recurrences', () => ({
  useCreateRecurrence: () => ({ mutateAsync: createMutateAsync, isPending: false }),
  useUpdateRecurrence: () => ({ mutateAsync: updateMutateAsync, isPending: false }),
}))

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({ data: [{ id: 1, name: 'Nubank', type: 'checking', color: null, icon: null, is_archived: false }] }),
}))

vi.mock('@/api/queries/categories', () => ({
  useCategories: () => ({
    data: [
      { id: 2, parent_id: null, name: 'Moradia', kind: 'expense', icon: null, color: null, is_archived: false },
      { id: 3, parent_id: null, name: 'Salário', kind: 'income', icon: null, color: null, is_archived: false },
    ],
  }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const { toast } = await import('sonner')

function recurrence(overrides: Partial<Recurrence> = {}): Recurrence {
  return {
    id: 1,
    account_id: 1,
    category_id: 2,
    description: 'Aluguel',
    amount: 150000,
    direction: 'out',
    frequency: 'monthly',
    interval: 1,
    day_of_month: 10,
    starts_on: '2026-01-10',
    ends_on: null,
    generated_until: null,
    match_pattern: null,
    is_active: true,
    ...overrides,
  }
}

beforeEach(() => {
  createMutateAsync.mockReset().mockResolvedValue(undefined)
  updateMutateAsync.mockReset().mockResolvedValue(undefined)
  vi.mocked(toast.success).mockReset()
})

function renderDialog(props: Partial<Parameters<typeof RecurrenceFormDialog>[0]> = {}) {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <RecurrenceFormDialog open onOpenChange={vi.fn()} {...props} />
    </QueryClientProvider>,
  )
}

async function selectOption(label: string, optionName: string | RegExp) {
  const trigger = screen.getByLabelText(label)
  fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
  fireEvent.click(trigger)
  fireEvent.click(await screen.findByRole('option', { name: optionName }))
}

describe('RecurrenceFormDialog', () => {
  it('valida a descrição antes de enviar', async () => {
    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: 'Criar recorrência' }))

    await waitFor(() => expect(screen.getByText('Informe a descrição.')).toBeInTheDocument())
    expect(createMutateAsync).not.toHaveBeenCalled()
  })

  it('mostra "Dia do mês" só para frequência mensal (padrão)', async () => {
    renderDialog()

    expect(screen.getByLabelText('Dia do mês')).toBeInTheDocument()

    await selectOption('Frequência', 'Toda semana')

    expect(screen.queryByLabelText('Dia do mês')).not.toBeInTheDocument()
  })

  it('cria uma recorrência mensal com os campos informados', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Aluguel' } })
    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '1.500,00' } })
    await selectOption('Conta', 'Nubank')
    fireEvent.change(screen.getByLabelText('Dia do mês'), { target: { value: '10' } })

    fireEvent.click(screen.getByRole('button', { name: 'Criar recorrência' }))

    await waitFor(() => expect(createMutateAsync).toHaveBeenCalled())
    expect(createMutateAsync).toHaveBeenCalledWith(
      expect.objectContaining({
        description: 'Aluguel',
        amount: 150000,
        account_id: 1,
        direction: 'out',
        frequency: 'monthly',
        interval: 1,
        day_of_month: 10,
      }),
    )
    expect(toast.success).toHaveBeenCalledWith('Recorrência criada.')
  })

  it('não envia day_of_month quando a frequência não é mensal', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Assinatura' } })
    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '30,00' } })
    await selectOption('Conta', 'Nubank')
    await selectOption('Frequência', 'Toda semana')

    fireEvent.click(screen.getByRole('button', { name: 'Criar recorrência' }))

    await waitFor(() => expect(createMutateAsync).toHaveBeenCalled())
    expect(createMutateAsync.mock.calls[0][0].day_of_month).toBeNull()
  })

  it('editando: trava o tipo e não envia direction no corpo', async () => {
    renderDialog({ recurrence: recurrence() })

    expect(screen.getByLabelText('Tipo')).toBeDisabled()

    fireEvent.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(updateMutateAsync).toHaveBeenCalled())
    expect(updateMutateAsync).toHaveBeenCalledWith({
      id: 1,
      body: expect.not.objectContaining({ direction: expect.anything() }),
    })
  })

  it('preenche os campos ao editar', () => {
    renderDialog({ recurrence: recurrence() })

    expect(screen.getByLabelText('Descrição')).toHaveValue('Aluguel')
    expect(screen.getByLabelText('Valor')).toHaveValue('1.500,00')
    expect(screen.getByLabelText('Dia do mês')).toHaveValue('10')
  })

  it('texto que identifica no extrato é opcional e vira null quando vazio', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Aluguel' } })
    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '1.500,00' } })
    await selectOption('Conta', 'Nubank')
    fireEvent.change(screen.getByLabelText('Dia do mês'), { target: { value: '10' } })

    fireEvent.click(screen.getByRole('button', { name: 'Criar recorrência' }))

    await waitFor(() => expect(createMutateAsync).toHaveBeenCalled())
    expect(createMutateAsync.mock.calls[0][0].match_pattern).toBeNull()
  })

  it('texto que identifica no extrato exige ao menos uma letra', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Aluguel' } })
    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '1.500,00' } })
    await selectOption('Conta', 'Nubank')
    fireEvent.change(screen.getByLabelText('Dia do mês'), { target: { value: '10' } })
    fireEvent.change(screen.getByLabelText('Texto que identifica no extrato'), { target: { value: '1234' } })

    fireEvent.click(screen.getByRole('button', { name: 'Criar recorrência' }))

    await waitFor(() => expect(screen.getByText('Inclua ao menos uma letra.')).toBeInTheDocument())
    expect(createMutateAsync).not.toHaveBeenCalled()
  })

  it('dia do mês vem vazio por padrão na criação, com a dica do padrão do backend', () => {
    renderDialog()

    expect(screen.getByLabelText('Dia do mês')).toHaveValue('')
    expect(screen.getByText('Padrão: dia do início.')).toBeInTheDocument()
  })

  it('dia do mês vazio envia null mesmo para frequência mensal (o backend usa o dia de starts_on)', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Aluguel' } })
    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '1.500,00' } })
    await selectOption('Conta', 'Nubank')

    fireEvent.click(screen.getByRole('button', { name: 'Criar recorrência' }))

    await waitFor(() => expect(createMutateAsync).toHaveBeenCalled())
    expect(createMutateAsync.mock.calls[0][0].day_of_month).toBeNull()
  })

  it('trocar o tipo na criação limpa a categoria escolhida', async () => {
    renderDialog()

    fireEvent.click(screen.getByLabelText('Categoria'))
    fireEvent.click(await screen.findByText('Moradia'))
    expect(screen.getByLabelText('Categoria')).toHaveTextContent('Moradia')

    await selectOption('Tipo', 'Receita')

    expect(screen.getByLabelText('Categoria')).toHaveTextContent('Escolha a categoria')
  })

  it('intervalo fora de 1-12 mostra erro e não envia', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Aluguel' } })
    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '1.500,00' } })
    await selectOption('Conta', 'Nubank')
    fireEvent.change(screen.getByLabelText('Intervalo'), { target: { value: '13' } })

    fireEvent.click(screen.getByRole('button', { name: 'Criar recorrência' }))

    await waitFor(() => expect(screen.getByText('Informe um intervalo entre 1 e 12.')).toBeInTheDocument())
    expect(createMutateAsync).not.toHaveBeenCalled()
  })

  it('fim antes do início mostra erro e não envia', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Aluguel' } })
    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '1.500,00' } })
    await selectOption('Conta', 'Nubank')
    fireEvent.change(screen.getByLabelText('Início'), { target: { value: '2026-10-10' } })
    fireEvent.change(screen.getByLabelText('Fim'), { target: { value: '2026-10-01' } })

    fireEvent.click(screen.getByRole('button', { name: 'Criar recorrência' }))

    await waitFor(() => expect(screen.getByText('O fim não pode ser antes do início.')).toBeInTheDocument())
    expect(createMutateAsync).not.toHaveBeenCalled()
  })

  it('422 do servidor em match_pattern aparece no campo', async () => {
    createMutateAsync.mockRejectedValueOnce(
      new ApiError(422, 'Dados inválidos.', null, { match_pattern: ['Já existe um padrão igual.'] }),
    )
    renderDialog()

    fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Aluguel' } })
    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: '1.500,00' } })
    await selectOption('Conta', 'Nubank')

    fireEvent.click(screen.getByRole('button', { name: 'Criar recorrência' }))

    await waitFor(() => expect(screen.getByText('Já existe um padrão igual.')).toBeInTheDocument())
  })

  it('o conteúdo do diálogo rola em telas pequenas', () => {
    renderDialog()

    expect(screen.getByText('Nova recorrência').closest('[data-slot="dialog-content"]')).toHaveClass(
      'max-h-[90dvh]',
      'overflow-y-auto',
    )
  })
})
