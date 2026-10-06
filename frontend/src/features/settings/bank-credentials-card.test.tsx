import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { BankCredentials } from '@/api/types'
import { BankCredentialsCard } from './bank-credentials-card'

const saveMutateAsync = vi.fn()
const removeMutateAsync = vi.fn()
const refetch = vi.fn()

let credentialsState: { data: BankCredentials | undefined; isPending: boolean; isError: boolean }

vi.mock('@/api/queries/bank-credentials', () => ({
  useBankCredentials: () => ({ ...credentialsState, refetch }),
  useSaveBankCredentials: () => ({ mutateAsync: saveMutateAsync, isPending: false }),
  useDeleteBankCredentials: () => ({ mutateAsync: removeMutateAsync, isPending: false }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const { toast } = await import('sonner')

const VALID_UUID = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'

beforeEach(() => {
  saveMutateAsync.mockReset().mockResolvedValue({ configured: true, provider: 'pluggy' })
  removeMutateAsync.mockReset().mockResolvedValue(undefined)
  refetch.mockReset()
  vi.mocked(toast.error).mockReset()
  vi.mocked(toast.success).mockReset()
  credentialsState = { data: { configured: false, provider: 'pluggy' }, isPending: false, isError: false }
})

function fillForm(clientId: string, secret: string) {
  fireEvent.change(screen.getByLabelText('Client ID'), { target: { value: clientId } })
  fireEvent.change(screen.getByLabelText('Client Secret'), { target: { value: secret } })
}

describe('BankCredentialsCard', () => {
  it('sem credenciais, mostra o formulário direto', () => {
    render(<BankCredentialsCard />)

    expect(screen.getByLabelText('Client ID')).toBeInTheDocument()
    expect(screen.getByLabelText('Client Secret')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Salvar e testar' })).toBeInTheDocument()
    expect(screen.queryByText('Pluggy conectada')).not.toBeInTheDocument()
  })

  it('configurado, mostra o status com o client id mascarado e sem formulário', () => {
    credentialsState = {
      data: { configured: true, provider: 'pluggy', client_id_hint: '3be8', verified_at: '2026-10-01T10:00:00Z' },
      isPending: false,
      isError: false,
    }
    render(<BankCredentialsCard />)

    expect(screen.getByText('Pluggy conectada')).toBeInTheDocument()
    expect(screen.getByText(/••••3be8/)).toBeInTheDocument()
    expect(screen.getByText(/verificado em 01\/10\/2026/)).toBeInTheDocument()
    expect(screen.queryByLabelText('Client Secret')).not.toBeInTheDocument()
  })

  it('mostra erro de carregamento com "Tentar de novo"', () => {
    credentialsState = { data: undefined, isPending: false, isError: true }
    render(<BankCredentialsCard />)

    expect(screen.getByText('Não foi possível carregar as credenciais.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))
    expect(refetch).toHaveBeenCalled()
  })

  it('"Trocar credenciais" abre o formulário com o secret vazio, nunca pré-preenchido', () => {
    credentialsState = {
      data: { configured: true, provider: 'pluggy', client_id_hint: '3be8', verified_at: '2026-10-01T10:00:00Z' },
      isPending: false,
      isError: false,
    }
    render(<BankCredentialsCard />)

    fireEvent.click(screen.getByRole('button', { name: 'Trocar credenciais' }))

    expect(screen.getByLabelText('Client ID')).toHaveValue('')
    expect(screen.getByLabelText('Client Secret')).toHaveValue('')
  })

  it('valida o formato do Client ID antes de enviar', async () => {
    render(<BankCredentialsCard />)

    fillForm('não-é-um-uuid', 'segredo')
    fireEvent.click(screen.getByRole('button', { name: 'Salvar e testar' }))

    await waitFor(() => expect(screen.getByText('Informe um Client ID válido.')).toBeInTheDocument())
    expect(saveMutateAsync).not.toHaveBeenCalled()
  })

  it('salva com sucesso e mostra confirmação', async () => {
    render(<BankCredentialsCard />)

    fillForm(VALID_UUID, 'segredo-secreto')
    fireEvent.click(screen.getByRole('button', { name: 'Salvar e testar' }))

    await waitFor(() => expect(saveMutateAsync).toHaveBeenCalledWith({ client_id: VALID_UUID, client_secret: 'segredo-secreto' }))
    await waitFor(() => expect(toast.success).toHaveBeenCalledWith('Credenciais salvas e verificadas.'))
  })

  it('422 em client_secret mostra o erro no campo', async () => {
    saveMutateAsync.mockRejectedValueOnce(
      new ApiError(422, 'Dados inválidos.', null, { client_secret: ['A Pluggy recusou essas credenciais.'] }),
    )
    render(<BankCredentialsCard />)

    fillForm(VALID_UUID, 'segredo-errado')
    fireEvent.click(screen.getByRole('button', { name: 'Salvar e testar' }))

    await waitFor(() => expect(screen.getByText('A Pluggy recusou essas credenciais.')).toBeInTheDocument())
  })

  it('409 bank_credentials_in_use mostra a mensagem perto do formulário', async () => {
    saveMutateAsync.mockRejectedValueOnce(
      new ApiError(409, 'Desconecte os bancos antes de trocar de conta Pluggy.', 'bank_credentials_in_use'),
    )
    render(<BankCredentialsCard />)

    fillForm(VALID_UUID, 'outro-secret')
    fireEvent.click(screen.getByRole('button', { name: 'Salvar e testar' }))

    await waitFor(() => expect(screen.getByText('Desconecte os bancos antes de trocar de conta Pluggy.')).toBeInTheDocument())
  })

  it('alterna mostrar/ocultar o Client Secret', () => {
    render(<BankCredentialsCard />)

    const secretInput = screen.getByLabelText('Client Secret')
    expect(secretInput).toHaveAttribute('type', 'password')

    fireEvent.click(screen.getByRole('button', { name: 'Mostrar Client Secret' }))
    expect(secretInput).toHaveAttribute('type', 'text')

    fireEvent.click(screen.getByRole('button', { name: 'Ocultar Client Secret' }))
    expect(secretInput).toHaveAttribute('type', 'password')
  })

  it('"Remover" pede confirmação e, ao confirmar, chama a mutação', async () => {
    credentialsState = {
      data: { configured: true, provider: 'pluggy', client_id_hint: '3be8', verified_at: '2026-10-01T10:00:00Z' },
      isPending: false,
      isError: false,
    }
    render(<BankCredentialsCard />)

    fireEvent.click(screen.getByRole('button', { name: 'Remover' }))
    expect(await screen.findByText('Remover credenciais da Pluggy?')).toBeInTheDocument()

    // O radix marca o resto da página como aria-hidden enquanto o diálogo modal está aberto: só
    // o botão "Remover" do próprio diálogo (não o do status) continua acessível por role aqui.
    fireEvent.click(screen.getByRole('button', { name: 'Remover' }))

    await waitFor(() => expect(removeMutateAsync).toHaveBeenCalled())
    await waitFor(() => expect(toast.success).toHaveBeenCalledWith('Credenciais removidas.'))
  })
})
