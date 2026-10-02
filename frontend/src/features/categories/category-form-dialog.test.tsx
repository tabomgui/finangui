import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { CategoryFormDialog } from './category-form-dialog'

function renderDialog(open = true) {
  const client = new QueryClient()
  const utils = render(
    <QueryClientProvider client={client}>
      <CategoryFormDialog open={open} onOpenChange={() => {}} kind="expense" />
    </QueryClientProvider>,
  )
  return { client, ...utils }
}

describe('CategoryFormDialog', () => {
  it('valida o nome antes de enviar', async () => {
    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: 'Criar categoria' }))

    await waitFor(() => expect(screen.getByText('Informe o nome da categoria.')).toBeInTheDocument())
  })

  it('limpa o formulário ao reabrir para criação', () => {
    const { client, rerender } = renderDialog()

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Rascunho' } })
    expect(screen.getByLabelText('Nome')).toHaveValue('Rascunho')

    rerender(
      <QueryClientProvider client={client}>
        <CategoryFormDialog open={false} onOpenChange={() => {}} kind="expense" />
      </QueryClientProvider>,
    )
    rerender(
      <QueryClientProvider client={client}>
        <CategoryFormDialog open onOpenChange={() => {}} kind="expense" />
      </QueryClientProvider>,
    )

    expect(screen.getByLabelText('Nome')).toHaveValue('')
  })
})
