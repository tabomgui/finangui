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

describe('NotificationList', () => {
  it('carregando, mostra esqueletos', () => {
    const { container } = render(
      <NotificationList
        notifications={[]}
        isPending
        hasNextPage={false}
        isFetchingNextPage={false}
        onLoadMore={vi.fn()}
        onSelect={vi.fn()}
      />,
    )

    expect(container.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(3)
  })

  it('sem notificações, mostra o estado vazio', () => {
    render(
      <NotificationList notifications={[]} isPending={false} hasNextPage={false} isFetchingNextPage={false} onLoadMore={vi.fn()} onSelect={vi.fn()} />,
    )

    expect(screen.getByText('Nenhuma notificação')).toBeInTheDocument()
  })

  it('mostra título, corpo e há quanto tempo de cada notificação', () => {
    render(
      <NotificationList
        notifications={[notification()]}
        isPending={false}
        hasNextPage={false}
        isFetchingNextPage={false}
        onLoadMore={vi.fn()}
        onSelect={vi.fn()}
      />,
    )

    expect(screen.getByText('Fatura do Nubank vence em 3 dias')).toBeInTheDocument()
    expect(screen.getByText('Confira o valor antes do vencimento.')).toBeInTheDocument()
  })

  it('clicar numa notificação chama onSelect com ela', () => {
    const onSelect = vi.fn()
    render(
      <NotificationList
        notifications={[notification()]}
        isPending={false}
        hasNextPage={false}
        isFetchingNextPage={false}
        onLoadMore={vi.fn()}
        onSelect={onSelect}
      />,
    )

    fireEvent.click(screen.getByText('Fatura do Nubank vence em 3 dias'))

    expect(onSelect).toHaveBeenCalledWith(notification())
  })

  it('com próxima página, mostra "Carregar mais" e chama onLoadMore ao clicar', () => {
    const onLoadMore = vi.fn()
    render(
      <NotificationList
        notifications={[notification()]}
        isPending={false}
        hasNextPage
        isFetchingNextPage={false}
        onLoadMore={onLoadMore}
        onSelect={vi.fn()}
      />,
    )

    fireEvent.click(screen.getByText('Carregar mais'))

    expect(onLoadMore).toHaveBeenCalled()
  })

  it('sem próxima página, não mostra "Carregar mais"', () => {
    render(
      <NotificationList
        notifications={[notification()]}
        isPending={false}
        hasNextPage={false}
        isFetchingNextPage={false}
        onLoadMore={vi.fn()}
        onSelect={vi.fn()}
      />,
    )

    expect(screen.queryByText('Carregar mais')).not.toBeInTheDocument()
  })
})
