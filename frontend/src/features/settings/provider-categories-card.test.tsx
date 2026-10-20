import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { User } from '@/api/types'
import { ProviderCategoriesCard } from './provider-categories-card'

const mutateAsync = vi.fn()
let countState: { data: { count: number } | undefined }

vi.mock('@/api/queries/auth', () => ({
  useUpdateProviderCategories: () => ({ mutateAsync, isPending: false }),
}))

vi.mock('@/api/queries/categorization', () => ({
  useProviderCategorizedCount: () => countState,
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const { toast } = await import('sonner')

function userWith(useProviderCategories: boolean): User {
  return {
    id: 1,
    name: 'Pessoa Exemplo',
    email: 'pessoa@exemplo.test',
    avatar: null,
    has_password: true,
    google_linked: false,
    primary_currency: 'BRL',
    banking_enabled: true,
    use_provider_categories: useProviderCategories,
  }
}

function toggle() {
  fireEvent.click(screen.getByRole('switch', { name: 'Usar categorias do banco' }))
}

beforeEach(() => {
  mutateAsync.mockReset().mockResolvedValue({ user: userWith(false), cleared: 0 })
  vi.mocked(toast.error).mockReset()
  vi.mocked(toast.success).mockReset()
  countState = { data: { count: 0 } }
})

describe('ProviderCategoriesCard', () => {
  it('mostra o interruptor no estado do usuário, com o texto de ajuda', () => {
    render(<ProviderCategoriesCard user={userWith(false)} />)

    const control = screen.getByRole('switch', { name: 'Usar categorias do banco' })
    expect(control).toHaveAttribute('aria-checked', 'false')
    expect(control).toHaveAccessibleDescription(
      'Quando desligado, os lançamentos novos da sincronização são categorizados só pelas suas regras e pelo histórico; o resto vai para Categorizar.',
    )
  })

  it('ligar salva direto, sem perguntar', async () => {
    render(<ProviderCategoriesCard user={userWith(false)} />)

    toggle()

    await waitFor(() => expect(mutateAsync).toHaveBeenCalledWith({ use_provider_categories: true }))
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    expect(toast.success).toHaveBeenCalledWith('Categorias do banco ativadas.')
  })

  it('desligar sem nenhum lançamento categorizado pelo banco salva direto, sem perguntar', async () => {
    render(<ProviderCategoriesCard user={userWith(true)} />)

    toggle()

    await waitFor(() => expect(mutateAsync).toHaveBeenCalledWith({ use_provider_categories: false }))
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    expect(toast.success).toHaveBeenCalledWith('Categorias do banco desativadas.')
  })

  it('desligar com lançamentos categorizados pelo banco pergunta, com a contagem, e "Manter categorias" só desliga', async () => {
    countState = { data: { count: 3 } }
    render(<ProviderCategoriesCard user={userWith(true)} />)

    toggle()

    const dialog = await screen.findByRole('alertdialog')
    expect(within(dialog).getByText(/3 lançamentos estão com a categoria que veio do banco/)).toBeInTheDocument()
    expect(mutateAsync).not.toHaveBeenCalled()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Manter categorias' }))

    await waitFor(() =>
      expect(mutateAsync).toHaveBeenCalledWith({ use_provider_categories: false, clear_provider_categories: false }),
    )
    expect(toast.success).toHaveBeenCalledWith('Categorias do banco desativadas.')
    await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument())
  })

  it('"Remover categorias" desliga e limpa, e o aviso mostra quantos voltaram para Categorizar', async () => {
    countState = { data: { count: 3 } }
    mutateAsync.mockResolvedValue({ user: userWith(false), cleared: 3 })
    render(<ProviderCategoriesCard user={userWith(true)} />)

    toggle()
    fireEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Remover categorias' }))

    await waitFor(() =>
      expect(mutateAsync).toHaveBeenCalledWith({ use_provider_categories: false, clear_provider_categories: true }),
    )
    expect(toast.success).toHaveBeenCalledWith('Categorias do banco desativadas. 3 lançamentos voltaram para Categorizar.')
  })

  it('"Cancelar" não muda nada', async () => {
    countState = { data: { count: 1 } }
    render(<ProviderCategoriesCard user={userWith(true)} />)

    toggle()
    const dialog = await screen.findByRole('alertdialog')
    expect(within(dialog).getByText(/1 lançamento está com a categoria que veio do banco/)).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancelar' }))

    await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument())
    expect(mutateAsync).not.toHaveBeenCalled()
  })

  it('sem a contagem (carregando ou com erro), pergunta com um texto genérico', async () => {
    countState = { data: undefined }
    render(<ProviderCategoriesCard user={userWith(true)} />)

    toggle()

    const dialog = await screen.findByRole('alertdialog')
    expect(within(dialog).getByText(/Alguns lançamentos podem estar com a categoria que veio do banco/)).toBeInTheDocument()
  })

  it('erro ao salvar mostra a mensagem e mantém o diálogo aberto', async () => {
    countState = { data: { count: 2 } }
    mutateAsync.mockRejectedValue(new ApiError(500, 'Erro inesperado no servidor. Tente novamente.'))
    render(<ProviderCategoriesCard user={userWith(true)} />)

    toggle()
    fireEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Remover categorias' }))

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Erro inesperado no servidor. Tente novamente.'))
    expect(screen.getByRole('alertdialog')).toBeInTheDocument()
    expect(toast.success).not.toHaveBeenCalled()
  })

  it('já desligado, com lançamentos ainda categorizados pelo banco, oferece a limpeza à parte', async () => {
    countState = { data: { count: 2 } }
    mutateAsync.mockResolvedValue({ user: userWith(false), cleared: 2 })
    render(<ProviderCategoriesCard user={userWith(false)} />)

    expect(screen.getByText('2 lançamentos ainda com a categoria do banco.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Remover categorias' }))

    const dialog = await screen.findByRole('alertdialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remover categorias' }))

    await waitFor(() =>
      expect(mutateAsync).toHaveBeenCalledWith({ use_provider_categories: false, clear_provider_categories: true }),
    )
    expect(toast.success).toHaveBeenCalledWith('2 lançamentos voltaram para Categorizar.')
  })

  it('ligado, não oferece a limpeza à parte', () => {
    countState = { data: { count: 2 } }
    render(<ProviderCategoriesCard user={userWith(true)} />)

    expect(screen.queryByText(/ainda com a categoria do banco/)).not.toBeInTheDocument()
  })
})
