import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Notification } from '@/api/types'
import { NotificationBell } from './notification-bell'

const { navigate, markReadMutate, markAllReadMutate, fetchNextPage } = vi.hoisted(() => ({
  navigate: vi.fn(),
  markReadMutate: vi.fn(),
  markAllReadMutate: vi.fn(),
  fetchNextPage: vi.fn(),
}))

let unreadCount = 0
let notifications: Notification[] = []
let hasNextPage = false

vi.mock('react-router-dom', () => ({ useNavigate: () => navigate }))

vi.mock('@/api/queries/notifications', () => ({
  useUnreadCount: () => ({ data: unreadCount }),
  useNotifications: () => ({
    data: { pages: [{ data: notifications }] },
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage: false,
    isPending: false,
  }),
  useMarkRead: () => ({ mutate: markReadMutate }),
  useMarkAllRead: () => ({ mutate: markAllReadMutate, isPending: false }),
}))

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

beforeEach(() => {
  unreadCount = 0
  notifications = []
  hasNextPage = false
  navigate.mockReset()
  markReadMutate.mockReset()
  markAllReadMutate.mockReset()
  fetchNextPage.mockReset()
})

describe('NotificationBell (header, mobile)', () => {
  it('sem notificações não lidas, não mostra o badge', () => {
    render(<NotificationBell variant="header" />)

    expect(screen.getByLabelText('Notificações, 0 não lidas')).toBeInTheDocument()
    expect(screen.queryByText('0')).not.toBeInTheDocument()
  })

  it('mostra a contagem de não lidas no badge', () => {
    unreadCount = 5

    render(<NotificationBell variant="header" />)

    expect(screen.getByText('5')).toBeInTheDocument()
    expect(screen.getByLabelText('Notificações, 5 não lidas')).toBeInTheDocument()
  })

  it('acima de 9 não lidas, mostra "9+"', () => {
    unreadCount = 15

    render(<NotificationBell variant="header" />)

    expect(screen.getByText('9+')).toBeInTheDocument()
  })

  it('abre a lista ao clicar e mostra o estado vazio sem notificações', () => {
    render(<NotificationBell variant="header" />)

    fireEvent.click(screen.getByLabelText('Notificações, 0 não lidas'))

    expect(screen.getByText('Nenhuma notificação')).toBeInTheDocument()
  })

  it('clicar numa notificação não lida com url interna marca como lida, navega e fecha', () => {
    notifications = [notification()]

    render(<NotificationBell variant="header" />)
    fireEvent.click(screen.getByLabelText('Notificações, 0 não lidas'))
    fireEvent.click(screen.getByText('Fatura do Nubank vence em 3 dias'))

    expect(markReadMutate).toHaveBeenCalledWith('a1')
    expect(navigate).toHaveBeenCalledWith('/cartoes/1')
    expect(screen.queryByText('Nenhuma notificação')).not.toBeInTheDocument()
  })

  it('clicar numa notificação já lida não marca como lida de novo', () => {
    notifications = [notification({ read_at: '2026-10-04T09:00:00.000Z' })]

    render(<NotificationBell variant="header" />)
    fireEvent.click(screen.getByLabelText('Notificações, 0 não lidas'))
    fireEvent.click(screen.getByText('Fatura do Nubank vence em 3 dias'))

    expect(markReadMutate).not.toHaveBeenCalled()
    expect(navigate).toHaveBeenCalledWith('/cartoes/1')
  })

  it('url externa (protocol-relative) não navega', () => {
    notifications = [notification({ url: '//evil.example.com' })]

    render(<NotificationBell variant="header" />)
    fireEvent.click(screen.getByLabelText('Notificações, 0 não lidas'))
    fireEvent.click(screen.getByText('Fatura do Nubank vence em 3 dias'))

    expect(navigate).not.toHaveBeenCalled()
  })

  it('"Marcar todas como lidas" chama a mutação', () => {
    unreadCount = 2

    render(<NotificationBell variant="header" />)
    fireEvent.click(screen.getByLabelText('Notificações, 2 não lidas'))
    fireEvent.click(screen.getByText('Marcar todas como lidas'))

    expect(markAllReadMutate).toHaveBeenCalled()
  })
})

describe('NotificationBell (sidebar, desktop)', () => {
  it('mostra a contagem de não lidas no botão ghost', () => {
    unreadCount = 3

    render(<NotificationBell variant="sidebar" />)

    expect(screen.getByText('3')).toBeInTheDocument()
    expect(screen.getByLabelText('Notificações, 3 não lidas')).toBeInTheDocument()
  })

  it('abre o popover ao clicar e mostra a lista', () => {
    notifications = [notification()]

    render(<NotificationBell variant="sidebar" />)
    fireEvent.click(screen.getByLabelText('Notificações, 0 não lidas'))

    expect(screen.getByText('Fatura do Nubank vence em 3 dias')).toBeInTheDocument()
  })
})
