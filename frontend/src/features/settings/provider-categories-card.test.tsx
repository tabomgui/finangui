import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'
import type { User } from '@/api/types'
import { ProviderCategoriesCard } from './provider-categories-card'

const mutateAsync = vi.fn()
const refetch = vi.fn()
let countState: { data: { count: number } | undefined; isError: boolean }

vi.mock('@/api/queries/auth', () => ({
  useUpdateProviderCategories: () => ({ mutateAsync, isPending: false }),
}))

vi.mock('@/api/queries/categorization', () => ({
  useProviderCategorizedCount: () => ({ ...countState, refetch }),
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

function theSwitch() {
  return screen.getByRole('switch', { name: 'Usar categorias do banco' })
}

/** A contagem refeita ao desligar devolve `count` (ou falha, com `null`). */
function freshCount(count: number | null) {
  refetch.mockResolvedValue(count === null ? { data: undefined, isError: true } : { data: { count }, isError: false })
}

beforeEach(() => {
  mutateAsync.mockReset().mockResolvedValue({ user: userWith(false), cleared: 0 })
  refetch.mockReset()
  freshCount(0)
  vi.mocked(toast.error).mockReset()
  vi.mocked(toast.success).mockReset()
  countState = { data: { count: 0 }, isError: false }
})

describe('ProviderCategoriesCard', () => {
  it('mostra o interruptor no estado do usuário, com o texto de ajuda dos dois estados', () => {
    render(<ProviderCategoriesCard user={userWith(false)} />)

    expect(theSwitch()).toHaveAttribute('aria-checked', 'false')
    expect(theSwitch()).toHaveAccessibleDescription(
      'Ligado, a categoria informada pelo banco entra depois das suas regras e do histórico. Desligado, os lançamentos novos da sincronização são categorizados só pelas suas regras e pelo histórico; o resto vai para Categorizar.',
    )
  })

  it('ligar salva direto, sem perguntar nem recontar', async () => {
    render(<ProviderCategoriesCard user={userWith(false)} />)

    fireEvent.click(theSwitch())

    await waitFor(() => expect(mutateAsync).toHaveBeenCalledWith({ use_provider_categories: true }))
    expect(refetch).not.toHaveBeenCalled()
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    expect(toast.success).toHaveBeenCalledWith('Categorias do banco ativadas.')
  })

  it('desligar reconta antes de decidir: com a contagem nova em 0, salva direto mesmo que o cache dissesse outra coisa', async () => {
    countState = { data: { count: 4 }, isError: false }
    freshCount(0)
    render(<ProviderCategoriesCard user={userWith(true)} />)

    fireEvent.click(theSwitch())

    await waitFor(() => expect(mutateAsync).toHaveBeenCalledWith({ use_provider_categories: false }))
    expect(refetch).toHaveBeenCalled()
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    expect(toast.success).toHaveBeenCalledWith('Categorias do banco desativadas.')
  })

  it('desligar com lançamentos categorizados pelo banco pergunta, com a contagem nova, e "Só desligar" não limpa', async () => {
    countState = { data: { count: 0 }, isError: false }
    freshCount(3)
    render(<ProviderCategoriesCard user={userWith(true)} />)

    fireEvent.click(theSwitch())

    const dialog = await screen.findByRole('alertdialog')
    expect(
      within(dialog).getByText(
        '3 lançamentos estão com a categoria que veio do banco. Quer remover essas categorias também? Esses lançamentos ficam sem categoria.',
      ),
    ).toBeInTheDocument()
    expect(within(dialog).getByText('Categorias removidas não voltam se você ligar a opção de novo.')).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Cancelar' })).toBeInTheDocument()
    expect(mutateAsync).not.toHaveBeenCalled()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Só desligar' }))

    await waitFor(() =>
      expect(mutateAsync).toHaveBeenCalledWith({ use_provider_categories: false, clear_provider_categories: false }),
    )
    expect(toast.success).toHaveBeenCalledWith('Categorias do banco desativadas.')
    await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument())
    expect(theSwitch()).toHaveFocus()
  })

  it('"Desligar e remover" desliga e limpa, e o aviso diz quantos ficaram sem categoria', async () => {
    freshCount(3)
    mutateAsync.mockResolvedValue({ user: userWith(false), cleared: 3 })
    render(<ProviderCategoriesCard user={userWith(true)} />)

    fireEvent.click(theSwitch())
    fireEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Desligar e remover' }))

    await waitFor(() =>
      expect(mutateAsync).toHaveBeenCalledWith({ use_provider_categories: false, clear_provider_categories: true }),
    )
    expect(toast.success).toHaveBeenCalledWith('Categorias do banco desativadas. 3 lançamentos ficaram sem categoria.')
  })

  it('com 1 lançamento, o texto inteiro concorda no singular; "Cancelar" não muda nada e devolve o foco ao interruptor', async () => {
    freshCount(1)
    render(<ProviderCategoriesCard user={userWith(true)} />)

    fireEvent.click(theSwitch())
    const dialog = await screen.findByRole('alertdialog')
    expect(
      within(dialog).getByText(
        '1 lançamento está com a categoria que veio do banco. Quer remover essa categoria também? Esse lançamento fica sem categoria.',
      ),
    ).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancelar' }))

    await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument())
    expect(mutateAsync).not.toHaveBeenCalled()
    expect(theSwitch()).toHaveFocus()
  })

  it('se a contagem nova falhar, pergunta com um texto genérico', async () => {
    freshCount(null)
    render(<ProviderCategoriesCard user={userWith(true)} />)

    fireEvent.click(theSwitch())

    const dialog = await screen.findByRole('alertdialog')
    expect(within(dialog).getByText(/Alguns lançamentos podem estar com a categoria que veio do banco/)).toBeInTheDocument()
  })

  it('erro ao salvar mostra a mensagem, mantém o diálogo aberto e o foco no botão clicado', async () => {
    freshCount(2)
    mutateAsync.mockRejectedValue(new ApiError(500, 'Erro inesperado no servidor. Tente novamente.'))
    render(<ProviderCategoriesCard user={userWith(true)} />)

    fireEvent.click(theSwitch())
    const remove = within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Desligar e remover' })
    fireEvent.click(remove)

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Erro inesperado no servidor. Tente novamente.'))
    expect(screen.getByRole('alertdialog')).toBeInTheDocument()
    expect(remove).toBeEnabled()
    expect(remove).toHaveFocus()
    expect(toast.success).not.toHaveBeenCalled()
  })

  it('já desligado, com lançamentos ainda categorizados pelo banco, oferece a limpeza à parte, com botão destrutivo', async () => {
    countState = { data: { count: 2 }, isError: false }
    mutateAsync.mockResolvedValue({ user: userWith(false), cleared: 2 })
    render(<ProviderCategoriesCard user={userWith(false)} />)

    expect(screen.getByText('2 lançamentos ainda com a categoria do banco.')).toBeInTheDocument()
    const clear = screen.getByRole('button', { name: 'Remover categorias' })
    expect(clear).toHaveClass('text-destructive')
    fireEvent.click(clear)

    const dialog = await screen.findByRole('alertdialog')
    expect(within(dialog).getByText(/Categorias removidas não voltam se você ligar a opção de novo\./)).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remover categorias' }))

    await waitFor(() =>
      expect(mutateAsync).toHaveBeenCalledWith({ use_provider_categories: false, clear_provider_categories: true }),
    )
    expect(toast.success).toHaveBeenCalledWith('2 lançamentos ficaram sem categoria.')
    await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument())
    expect(theSwitch()).toHaveFocus()
  })

  it('cancelar a limpeza à parte devolve o foco ao botão "Remover categorias"', async () => {
    countState = { data: { count: 2 }, isError: false }
    render(<ProviderCategoriesCard user={userWith(false)} />)

    const clear = screen.getByRole('button', { name: 'Remover categorias' })
    fireEvent.click(clear)
    fireEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Cancelar' }))

    await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument())
    expect(clear).toHaveFocus()
  })

  it('ligado, não oferece a limpeza à parte', () => {
    countState = { data: { count: 2 }, isError: false }
    render(<ProviderCategoriesCard user={userWith(true)} />)

    expect(screen.queryByText(/ainda com a categoria do banco/)).not.toBeInTheDocument()
  })

  it('ligado, a contagem com erro não mostra aviso nenhum (o diálogo de desligar já reconta)', () => {
    countState = { data: undefined, isError: true }
    render(<ProviderCategoriesCard user={userWith(true)} />)

    expect(screen.queryByText('Não foi possível contar os lançamentos categorizados pelo banco.')).not.toBeInTheDocument()
  })

  it('desligado, a contagem com erro mostra um aviso com "Tentar de novo"', () => {
    countState = { data: undefined, isError: true }
    render(<ProviderCategoriesCard user={userWith(false)} />)

    expect(screen.getByText('Não foi possível contar os lançamentos categorizados pelo banco.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))
    expect(refetch).toHaveBeenCalled()
  })
})
