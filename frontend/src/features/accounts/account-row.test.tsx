import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AccountRow } from './account-row'

const updateMutateAsync = vi.fn()
const deleteMutateAsync = vi.fn()

vi.mock('@/api/queries/accounts', () => ({
  useUpdateAccount: () => ({ mutateAsync: updateMutateAsync, isPending: false }),
  useDeleteAccount: () => ({ mutateAsync: deleteMutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

function account(overrides: Partial<Parameters<typeof AccountRow>[0]['account']> = {}) {
  return {
    id: 1,
    name: 'Nubank',
    type: 'checking' as const,
    currency: 'BRL',
    color: '#f97316',
    icon: 'landmark',
    is_archived: false,
    balance: 15000,
    ...overrides,
  }
}

function renderRow(props: Partial<Parameters<typeof AccountRow>[0]> = {}) {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/contas']}>
        <Routes>
          <Route path="/contas" element={<AccountRow account={account()} onEdit={vi.fn()} {...props} />} />
          <Route path="/importar" element={<div>Importar extrato: tela</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function openMenu(name: string) {
  const trigger = screen.getByRole('button', { name })
  fireEvent.pointerDown(trigger, { button: 0, pointerType: 'mouse' })
  fireEvent.click(trigger)
}

beforeEach(() => {
  updateMutateAsync.mockReset().mockResolvedValue(undefined)
  deleteMutateAsync.mockReset().mockResolvedValue(undefined)
})

describe('AccountRow', () => {
  it('mostra nome, saldo e badge de arquivada', () => {
    renderRow({ account: account({ name: 'Conta arquivada', is_archived: true }) })

    expect(screen.getByText('Conta arquivada')).toBeInTheDocument()
    expect(screen.getByText('Arquivada')).toBeInTheDocument()
    expect(screen.getByText(/150,00/)).toBeInTheDocument()
  })

  it('mostra a moeda só quando difere da moeda principal', () => {
    const { rerender } = renderRow({ account: account({ currency: 'USD' }), primaryCurrency: 'BRL' })
    expect(screen.getByText(/USD/)).toBeInTheDocument()

    rerender(
      <QueryClientProvider client={new QueryClient()}>
        <MemoryRouter>
          <AccountRow account={account({ currency: 'BRL' })} onEdit={vi.fn()} primaryCurrency="BRL" />
        </MemoryRouter>
      </QueryClientProvider>,
    )
    expect(screen.queryByText(/BRL/)).not.toBeInTheDocument()
  })

  it('chama onEdit pelo menu', async () => {
    const onEdit = vi.fn()
    renderRow({ onEdit })

    await openMenu('Ações da conta Nubank')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Editar' }))

    expect(onEdit).toHaveBeenCalled()
  })

  it('arquiva pelo menu', async () => {
    renderRow()

    await openMenu('Ações da conta Nubank')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Arquivar' }))

    expect(updateMutateAsync).toHaveBeenCalledWith({ id: 1, body: { is_archived: true } })
  })

  it('mostra "Importar extrato" só quando showImport e navega com a conta pré-selecionada', async () => {
    renderRow({ showImport: true })

    await openMenu('Ações da conta Nubank')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Importar extrato' }))

    expect(await screen.findByText('Importar extrato: tela')).toBeInTheDocument()
  })

  it('não mostra "Importar extrato" por padrão (contas conectadas)', async () => {
    renderRow()

    await openMenu('Ações da conta Nubank')

    expect(screen.queryByRole('menuitem', { name: 'Importar extrato' })).not.toBeInTheDocument()
  })

  it('excluir abre confirmação e, ao confirmar, chama a mutação', async () => {
    renderRow()

    await openMenu('Ações da conta Nubank')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Excluir' }))

    expect(await screen.findByText('Excluir Nubank?')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Excluir' }))

    await vi.waitFor(() => expect(deleteMutateAsync).toHaveBeenCalledWith(1))
  })

  it('mostra o conteúdo extra quando passado', () => {
    renderRow({ extra: <p>Banco informa R$ 200,00</p> })

    expect(screen.getByText('Banco informa R$ 200,00')).toBeInTheDocument()
  })
})
