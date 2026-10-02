import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { TagFormDialog } from './tag-form-dialog'

function renderDialog(open = true) {
  const client = new QueryClient()
  const utils = render(
    <QueryClientProvider client={client}>
      <TagFormDialog open={open} onOpenChange={() => {}} />
    </QueryClientProvider>,
  )
  return { client, ...utils }
}

describe('TagFormDialog', () => {
  it('valida o nome', async () => {
    renderDialog()

    fireEvent.click(screen.getByRole('button', { name: 'Criar tag' }))

    await waitFor(() => expect(screen.getByText('Informe o nome da tag.')).toBeInTheDocument())
  })

  it('limpa o formulário ao reabrir para criação', () => {
    const { client, rerender } = renderDialog()

    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Rascunho' } })
    expect(screen.getByLabelText('Nome')).toHaveValue('Rascunho')

    rerender(
      <QueryClientProvider client={client}>
        <TagFormDialog open={false} onOpenChange={() => {}} />
      </QueryClientProvider>,
    )
    rerender(
      <QueryClientProvider client={client}>
        <TagFormDialog open onOpenChange={() => {}} />
      </QueryClientProvider>,
    )

    expect(screen.getByLabelText('Nome')).toHaveValue('')
  })
})
