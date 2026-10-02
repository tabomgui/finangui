import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AccountFormDialog } from './account-form-dialog'

const createMutateAsync = vi.fn()
const updateMutateAsync = vi.fn()

vi.mock('@/api/queries/accounts', () => ({
  useCreateAccount: () => ({ mutateAsync: createMutateAsync, isPending: false }),
  useUpdateAccount: () => ({ mutateAsync: updateMutateAsync, isPending: false }),
}))

beforeEach(() => {
  createMutateAsync.mockReset().mockResolvedValue(undefined)
  updateMutateAsync.mockReset().mockResolvedValue(undefined)
})

function renderDialog(open = true, defaultType?: 'checking' | 'credit_card') {
  const client = new QueryClient()
  const utils = render(
    <QueryClientProvider client={client}>
      <AccountFormDialog open={open} onOpenChange={() => {}} defaultType={defaultType} />
    </QueryClientProvider>,
  )
  return { client, ...utils }
}

async function selectAccountType(label: string) {
  const trigger = screen.getByLabelText('Tipo')
  fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
  fireEvent.click(trigger)
  fireEvent.click(await screen.findByRole('option', { name: label }))
}

describe('AccountFormDialog', () => {
  it('valida o nome antes de enviar', async () => {
    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: 'Criar conta' }))

    await waitFor(() => expect(screen.getByText('Informe o nome da conta.')).toBeInTheDocument())
  })

  it('aceita saldo inicial negativo', () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Saldo inicial'), { target: { value: '-150,00' } })

    expect(screen.getByLabelText('Saldo inicial')).toHaveValue('-150,00')
    expect(screen.queryByText('Informe um valor válido.')).not.toBeInTheDocument()
  })

  it('limpa o formulário ao reabrir para criação', () => {
    const { client, rerender } = renderDialog()

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Rascunho' } })
    expect(screen.getByLabelText('Nome')).toHaveValue('Rascunho')

    rerender(
      <QueryClientProvider client={client}>
        <AccountFormDialog open={false} onOpenChange={() => {}} />
      </QueryClientProvider>,
    )
    rerender(
      <QueryClientProvider client={client}>
        <AccountFormDialog open onOpenChange={() => {}} />
      </QueryClientProvider>,
    )

    expect(screen.getByLabelText('Nome')).toHaveValue('')
  })

  it('escolhendo cartão de crédito mostra os campos de cartão', async () => {
    renderDialog()

    await selectAccountType('Cartão de crédito')

    expect(screen.getByLabelText('Limite')).toBeInTheDocument()
    expect(screen.getByLabelText('Dia de fechamento')).toBeInTheDocument()
    expect(screen.getByLabelText('Dia de vencimento')).toBeInTheDocument()
    expect(screen.getByLabelText('Final do cartão')).toBeInTheDocument()
  })

  it('enviar cartão sem limite/dias mostra os erros e não chama a mutação', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Nubank' } })
    await selectAccountType('Cartão de crédito')

    fireEvent.click(screen.getByRole('button', { name: 'Criar conta' }))

    await waitFor(() => expect(screen.getByText('Informe o limite.')).toBeInTheDocument())
    expect(screen.getAllByText('Informe um dia entre 1 e 31.')).toHaveLength(2)
    expect(createMutateAsync).not.toHaveBeenCalled()
  })

  it('enviar cartão válido chama create.mutateAsync com os campos de cartão', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Nubank' } })
    await selectAccountType('Cartão de crédito')

    fireEvent.change(screen.getByLabelText('Limite'), { target: { value: '5.000,00' } })
    fireEvent.change(screen.getByLabelText('Dia de fechamento'), { target: { value: '3' } })
    fireEvent.change(screen.getByLabelText('Dia de vencimento'), { target: { value: '10' } })
    fireEvent.change(screen.getByLabelText('Final do cartão'), { target: { value: '1234' } })

    fireEvent.click(screen.getByRole('button', { name: 'Criar conta' }))

    await waitFor(() => expect(createMutateAsync).toHaveBeenCalled())
    expect(createMutateAsync).toHaveBeenCalledWith(
      expect.objectContaining({
        type: 'credit_card',
        credit_limit: 500000,
        closing_day: 3,
        due_day: 10,
        last_four: '1234',
      }),
    )
  })

  it('enviar conta corrente não inclui chaves de cartão no corpo', async () => {
    renderDialog()

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Banco' } })
    fireEvent.click(screen.getByRole('button', { name: 'Criar conta' }))

    await waitFor(() => expect(createMutateAsync).toHaveBeenCalled())
    const body = createMutateAsync.mock.calls[0][0]
    expect(body).not.toHaveProperty('credit_limit')
    expect(body).not.toHaveProperty('closing_day')
    expect(body).not.toHaveProperty('due_day')
    expect(body).not.toHaveProperty('last_four')
  })

  it('com defaultType credit_card abre com o tipo cartão selecionado', () => {
    renderDialog(true, 'credit_card')

    expect(screen.getByText('Novo cartão')).toBeInTheDocument()
    expect(screen.getByLabelText('Limite')).toBeInTheDocument()
  })
})
