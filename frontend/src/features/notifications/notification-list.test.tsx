import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { Notification } from '@/api/types'
import { NotificationList } from './notification-list'

function notification(overrides: Partial<Notification> = {}): Notification {
  return {
    id: 'a1',
    type: 'statement_due',
    title: 'Fatura do Nubank vence em 3 dias',
    body: 'Confira o valor antes do vencimento.',
    url: '/cartoes/1',
    read_at: null,
    created_at: '2026-10-04T10:00:00.000Z',
    ...overrides,
  }
}

const baseProps = {
  isPending: false,
  isError: false,
  onRetry: vi.fn(),
  hasNextPage: false,
  isFetchingNextPage: false,
  onLoadMore: vi.fn(),
  onSelect: vi.fn(),
}

describe('NotificationList', () => {
  it('carregando, mostra esqueletos', () => {
    const { container } = render(<NotificationList {...baseProps} notifications={[]} isPending />)

    expect(container.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(3)
  })

  it('erro, mostra mensagem e "Tentar de novo"', () => {
    const onRetry = vi.fn()
    render(<NotificationList {...baseProps} notifications={[]} isError onRetry={onRetry} />)

    expect(screen.getByText('Não foi possível carregar as notificações.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(onRetry).toHaveBeenCalled()
  })

  it('erro tem prioridade sobre carregando (evita esqueleto preso)', () => {
    render(<NotificationList {...baseProps} notifications={[]} isPending isError onRetry={vi.fn()} />)

    expect(screen.getByText('Não foi possível carregar as notificações.')).toBeInTheDocument()
  })

  it('sem notificações, mostra o estado vazio', () => {
    render(<NotificationList {...baseProps} notifications={[]} />)

    expect(screen.getByText('Nenhuma notificação')).toBeInTheDocument()
  })

  it('mostra título, corpo e há quanto tempo de cada notificação', () => {
    render(<NotificationList {...baseProps} notifications={[notification({ read_at: '2026-10-04T09:00:00.000Z' })]} />)

    expect(screen.getByText('Fatura do Nubank vence em 3 dias')).toBeInTheDocument()
    expect(screen.getByText('Confira o valor antes do vencimento.')).toBeInTheDocument()
  })

  it('notificação não lida tem um marcador "Não lida" só para leitor de tela', () => {
    render(<NotificationList {...baseProps} notifications={[notification({ read_at: null })]} />)

    expect(screen.getByText('Não lida:', { exact: false })).toBeInTheDocument()
  })

  it('notificação lida não tem o marcador "Não lida"', () => {
    render(<NotificationList {...baseProps} notifications={[notification({ read_at: '2026-10-04T09:00:00.000Z' })]} />)

    expect(screen.queryByText('Não lida:', { exact: false })).not.toBeInTheDocument()
  })

  it('clicar numa notificação chama onSelect com ela', () => {
    const onSelect = vi.fn()
    const item = notification({ read_at: '2026-10-04T09:00:00.000Z' })
    render(<NotificationList {...baseProps} notifications={[item]} onSelect={onSelect} />)

    fireEvent.click(screen.getByText('Fatura do Nubank vence em 3 dias'))

    expect(onSelect).toHaveBeenCalledWith(item)
  })

  it('com próxima página, mostra "Carregar mais" e chama onLoadMore ao clicar', () => {
    const onLoadMore = vi.fn()
    render(<NotificationList {...baseProps} notifications={[notification()]} hasNextPage onLoadMore={onLoadMore} />)

    fireEvent.click(screen.getByText('Carregar mais'))

    expect(onLoadMore).toHaveBeenCalled()
  })

  it('sem próxima página, não mostra "Carregar mais"', () => {
    render(<NotificationList {...baseProps} notifications={[notification()]} />)

    expect(screen.queryByText('Carregar mais')).not.toBeInTheDocument()
  })
})
