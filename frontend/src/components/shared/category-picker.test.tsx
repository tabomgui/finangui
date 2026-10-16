import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/api/query-keys'
import type { Category } from '@/api/types'
import { CategoryPicker } from './category-picker'

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { ...actual.api, POST: vi.fn() } }
})

const { api } = await import('@/api/client')

const category = (overrides: Partial<Category>): Category => ({
  id: 1,
  parent_id: null,
  name: 'Alimentação',
  kind: 'expense',
  icon: null,
  color: null,
  is_transfer: false,
  is_transfer_effective: false,
  is_archived: false,
  ...overrides,
})

describe('CategoryPicker', () => {
  it('lista apenas categorias do tipo filtrado e seleciona ao clicar', () => {
    const client = new QueryClient()
    client.setQueryData(queryKeys.categories(true), [
      category({ id: 1, name: 'Alimentação', kind: 'expense' }),
      category({ id: 2, name: 'Salário', kind: 'income' }),
    ])
    const onChange = vi.fn()

    render(
      <QueryClientProvider client={client}>
        <CategoryPicker value={null} onChange={onChange} kind="expense" />
      </QueryClientProvider>,
    )

    fireEvent.click(screen.getByRole('combobox'))

    expect(screen.getByText('Alimentação')).toBeInTheDocument()
    expect(screen.queryByText('Salário')).not.toBeInTheDocument()

    fireEvent.click(screen.getByText('Alimentação'))

    expect(onChange).toHaveBeenCalledWith(1)
  })

  it('busca ignorando acentuação', () => {
    const client = new QueryClient()
    client.setQueryData(queryKeys.categories(true), [
      category({ id: 1, name: 'Alimentação', kind: 'expense' }),
      category({ id: 2, name: 'Transporte', kind: 'expense' }),
    ])

    render(
      <QueryClientProvider client={client}>
        <CategoryPicker value={null} onChange={vi.fn()} kind="expense" />
      </QueryClientProvider>,
    )

    fireEvent.click(screen.getByRole('combobox'))
    fireEvent.change(screen.getByPlaceholderText('Buscar categoria'), { target: { value: 'alimentacao' } })

    expect(screen.getByText('Alimentação')).toBeInTheDocument()
    expect(screen.queryByText('Transporte')).not.toBeInTheDocument()
  })

  describe('com allowCreate', () => {
    beforeEach(() => vi.mocked(api.POST).mockReset())

    function renderPicker(props: Partial<Parameters<typeof CategoryPicker>[0]> = {}) {
      // Sem retry: criar invalida as categorias, e o refetch (sem servidor no teste) falha na hora.
      const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
      client.setQueryData(queryKeys.categories(true), [
        category({ id: 1, name: 'Alimentação', kind: 'expense' }),
        category({ id: 2, name: 'Salário', kind: 'income' }),
      ])
      const onChange = vi.fn()
      const onCreate = vi.fn()
      render(
        <QueryClientProvider client={client}>
          <CategoryPicker value={null} onChange={onChange} onCreate={onCreate} kind="expense" allowCreate {...props} />
        </QueryClientProvider>,
      )
      fireEvent.click(screen.getByRole('combobox'))
      return { onChange, onCreate }
    }

    function search(text: string) {
      fireEvent.change(screen.getByPlaceholderText('Buscar ou criar categoria'), { target: { value: text } })
    }

    it('sem allowCreate nunca oferece criar (comportamento de sempre)', () => {
      renderPicker({ allowCreate: false })
      fireEvent.change(screen.getByPlaceholderText('Buscar categoria'), { target: { value: 'Mercado' } })

      expect(screen.queryByText(/Criar/)).not.toBeInTheDocument()
    })

    it('oferece "Criar “<busca>”…" só quando nenhum nome bate exatamente, ignorando acento e caixa', () => {
      renderPicker()

      search('alimentacao')
      expect(screen.queryByText(/Criar/)).not.toBeInTheDocument()

      search('Aliment')
      expect(screen.getByRole('option', { name: 'Criar “Aliment”…' })).toBeInTheDocument()

      // Salário é receita: não conta como nome existente para uma despesa.
      search('Salário')
      expect(screen.getByRole('option', { name: 'Criar “Salário”…' })).toBeInTheDocument()
    })

    it('"Voltar" do mini formulário volta para a lista', () => {
      renderPicker()
      search('Mercado')
      fireEvent.click(screen.getByRole('option', { name: 'Criar “Mercado”…' }))

      expect(screen.getByText('Nova categoria')).toBeInTheDocument()
      expect(screen.getByText('Vai ficar como categoria principal de despesa.')).toBeInTheDocument()
      fireEvent.click(screen.getByRole('button', { name: 'Voltar' }))

      expect(screen.getByPlaceholderText('Buscar ou criar categoria')).toBeInTheDocument()
    })

    it('cria a categoria, seleciona e avisa quem chamou', async () => {
      const created = category({ id: 9, name: 'Mercado' })
      vi.mocked(api.POST).mockResolvedValue({ data: { data: created }, error: undefined, response: { ok: true, status: 201 } as Response } as never)
      const { onChange, onCreate } = renderPicker()
      search('Mercado')
      fireEvent.click(screen.getByRole('option', { name: 'Criar “Mercado”…' }))
      fireEvent.click(screen.getByRole('button', { name: 'Criar e usar' }))

      await vi.waitFor(() => expect(onChange).toHaveBeenCalledWith(9))
      expect(onCreate).toHaveBeenCalledWith(created)
      expect(api.POST).toHaveBeenCalledWith('/categories', {
        body: expect.objectContaining({ name: 'Mercado', kind: 'expense', parent_id: null }),
      })
    })

    it('nome vazio não cria', () => {
      renderPicker()
      search('Mercado')
      fireEvent.click(screen.getByRole('option', { name: 'Criar “Mercado”…' }))
      fireEvent.change(screen.getByLabelText('Nome'), { target: { value: '  ' } })
      fireEvent.click(screen.getByRole('button', { name: 'Criar e usar' }))

      expect(screen.getByText('Informe o nome da categoria.')).toBeInTheDocument()
      expect(api.POST).not.toHaveBeenCalled()
    })
  })
})
