import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { AccountFormDialog } from './account-form-dialog'

function renderDialog(open = true) {
  const client = new QueryClient()
  const utils = render(
    <QueryClientProvider client={client}>
      <AccountFormDialog open={open} onOpenChange={() => {}} />
    </QueryClientProvider>,
  )
  return { client, ...utils }
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
})
