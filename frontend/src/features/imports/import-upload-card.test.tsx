import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import { ImportUploadCard } from './import-upload-card'

const mutateAsync = vi.fn()

vi.mock('@/api/queries/imports', () => ({
  useUploadStatement: () => ({ mutateAsync, isPending: false }),
}))

vi.mock('@/api/queries/accounts', () => ({
  useAccounts: () => ({
    data: [
      { id: 1, name: 'Nubank', type: 'checking', is_archived: false },
      { id: 2, name: 'Inter', type: 'checking', is_archived: false },
    ],
  }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

function renderCard(initialEntry = '/importar') {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <Routes>
          <Route path="/importar" element={<ImportUploadCard />} />
          <Route path="/importar/:id" element={<div>Prévia do lote</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function chooseAccount(label: string) {
  const trigger = screen.getByLabelText('Conta')
  fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
  fireEvent.click(trigger)
  fireEvent.click(await screen.findByRole('option', { name: label }))
}

function chooseFile(name = 'extrato.csv', content = 'conteudo') {
  const file = new File([content], name, { type: 'text/csv' })
  const input = screen.getByLabelText('Arquivo')
  fireEvent.change(input, { target: { files: [file] } })
  return file
}

beforeEach(() => {
  mutateAsync.mockReset()
})

describe('ImportUploadCard', () => {
  it('valida conta e arquivo antes de enviar', async () => {
    renderCard()

    fireEvent.click(screen.getByRole('button', { name: 'Ver prévia' }))

    await waitFor(() => expect(screen.getByText('Escolha a conta.')).toBeInTheDocument())
    expect(screen.getByText('Escolha um arquivo.')).toBeInTheDocument()
    expect(mutateAsync).not.toHaveBeenCalled()
  })

  it('envia o FormData com conta e arquivo e navega para a prévia do lote', async () => {
    mutateAsync.mockResolvedValue({ batch: { id: 42 }, rows: [], summary: {} })
    renderCard()

    await chooseAccount('Nubank')
    const file = chooseFile()

    fireEvent.click(screen.getByRole('button', { name: 'Ver prévia' }))

    await waitFor(() =>
      expect(mutateAsync).toHaveBeenCalledWith({ account_id: 1, file, format: undefined }),
    )
    expect(await screen.findByText('Prévia do lote')).toBeInTheDocument()
  })

  it('pré-seleciona a conta a partir de ?conta= na URL', () => {
    renderCard('/importar?conta=2')

    expect(screen.getByLabelText('Conta')).toHaveTextContent('Inter')
  })

  it('envia o formato escolhido quando não é "detectar automaticamente"', async () => {
    mutateAsync.mockResolvedValue({ batch: { id: 1 }, rows: [], summary: {} })
    renderCard()

    await chooseAccount('Nubank')
    chooseFile()
    const formatTrigger = screen.getByLabelText('Banco/formato')
    fireEvent.pointerDown(formatTrigger, { button: 0, pointerType: 'mouse' })
    fireEvent.click(formatTrigger)
    fireEvent.click(await screen.findByRole('option', { name: 'OFX' }))

    fireEvent.click(screen.getByRole('button', { name: 'Ver prévia' }))

    await waitFor(() =>
      expect(mutateAsync).toHaveBeenCalledWith(expect.objectContaining({ format: 'ofx' })),
    )
  })

  it('mostra o erro 422 de formato não reconhecido no campo do arquivo e destaca o seletor de formato', async () => {
    mutateAsync.mockRejectedValue(
      new ApiError(422, 'Dados inválidos.', null, {
        file: ['Não reconhecemos o formato deste arquivo. Escolha o banco.'],
      }),
    )
    renderCard()

    await chooseAccount('Nubank')
    chooseFile()
    fireEvent.click(screen.getByRole('button', { name: 'Ver prévia' }))

    expect(await screen.findByText('Não reconhecemos o formato deste arquivo. Escolha o banco.')).toBeInTheDocument()
    expect(screen.getByLabelText('Banco/formato')).toHaveAttribute('aria-invalid', 'true')
  })
})
