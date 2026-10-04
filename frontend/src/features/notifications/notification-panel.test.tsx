import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Notification } from '@/api/types'
import { Popover } from '@/components/ui/popover'
import { Sheet } from '@/components/ui/sheet'
import { NotificationPanel } from './notification-panel'

const { navigate, markReadMutate, markAllReadMutate, useNotifications } = vi.hoisted(() => ({
  navigate: vi.fn(),
  markReadMutate: vi.fn(),
  markAllReadMutate: vi.fn(),
  useNotifications: vi.fn(),
}))

vi.mock('react-router-dom', () => ({ useNavigate: () => navigate }))

vi.mock('@/api/queries/notifications', () => ({
  useNotifications: (...args: unknown[]) => useNotifications(...args),
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

function setNotifications(items: Notification[]) {
  useNotifications.mockReturnValue({
    data: { pages: [{ data: items }] },
    fetchNextPage: vi.fn(),
    hasNextPage: false,
    isFetchingNextPage: false,
    isPending: false,
    isError: false,
    refetch: vi.fn(),
  })
}

function renderHeaderPanel(props: Partial<Parameters<typeof NotificationPanel>[0]> = {}) {
  return render(
    <Sheet open onOpenChange={vi.fn()}>
      <NotificationPanel variant="header" open onOpenChange={vi.fn()} unreadCount={0} {...props} />
    </Sheet>,
  )
}

function renderSidebarPanel(props: Partial<Parameters<typeof NotificationPanel>[0]> = {}) {
  return render(
    <Popover open onOpenChange={vi.fn()}>
      <NotificationPanel variant="sidebar" open onOpenChange={vi.fn()} unreadCount={0} {...props} />
    </Popover>,
  )
}

beforeEach(() => {
  navigate.mockReset()
  markReadMutate.mockReset()
  markAllReadMutate.mockReset()
  useNotifications.mockReset()
  setNotifications([])
})

describe('NotificationPanel (header/Sheet)', () => {
  it('mostra o título e a lista (vazia)', () => {
    renderHeaderPanel()

    expect(screen.getByText('Notificações')).toBeInTheDocument()
    expect(screen.getByText('Nenhuma notificação')).toBeInTheDocument()
  })

  it('passa `open` pra useNotifications (pausa a busca quando fechado)', () => {
    renderHeaderPanel({ open: false })

    expect(useNotifications).toHaveBeenCalledWith(false)
  })

  it('clicar numa notificação não lida com url interna marca como lida, navega e fecha', () => {
    setNotifications([notification()])
    const onOpenChange = vi.fn()
    renderHeaderPanel({ onOpenChange })

    fireEvent.click(screen.getByText('Fatura do Nubank vence em 3 dias'))

    expect(markReadMutate).toHaveBeenCalledWith('a1')
    expect(navigate).toHaveBeenCalledWith('/cartoes/1')
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it('clicar numa notificação já lida não marca como lida de novo, mas ainda navega e fecha', () => {
    setNotifications([notification({ read_at: '2026-10-04T09:00:00.000Z' })])
    const onOpenChange = vi.fn()
    renderHeaderPanel({ onOpenChange })

    fireEvent.click(screen.getByText('Fatura do Nubank vence em 3 dias'))

    expect(markReadMutate).not.toHaveBeenCalled()
    expect(navigate).toHaveBeenCalledWith('/cartoes/1')
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it('url externa (protocol-relative) não navega, mas ainda fecha', () => {
    setNotifications([notification({ url: '//evil.example.com' })])
    const onOpenChange = vi.fn()
    renderHeaderPanel({ onOpenChange })

    fireEvent.click(screen.getByText('Fatura do Nubank vence em 3 dias'))

    expect(navigate).not.toHaveBeenCalled()
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it('sem não lidas, "Marcar todas como lidas" fica desabilitado', () => {
    renderHeaderPanel({ unreadCount: 0 })

    expect(screen.getByRole('button', { name: 'Marcar todas como lidas' })).toBeDisabled()
  })

  it('com não lidas, "Marcar todas como lidas" chama a mutação', () => {
    renderHeaderPanel({ unreadCount: 2 })

    fireEvent.click(screen.getByRole('button', { name: 'Marcar todas como lidas' }))

    expect(markAllReadMutate).toHaveBeenCalled()
  })

  it('erro ao carregar mostra "Tentar de novo" e chama refetch', () => {
    const refetch = vi.fn()
    useNotifications.mockReturnValue({
      data: undefined,
      fetchNextPage: vi.fn(),
      hasNextPage: false,
      isFetchingNextPage: false,
      isPending: false,
      isError: true,
      refetch,
    })

    renderHeaderPanel()
    fireEvent.click(screen.getByRole('button', { name: 'Tentar de novo' }))

    expect(refetch).toHaveBeenCalled()
  })
})

describe('NotificationPanel (sidebar/Popover)', () => {
  it('tem aria-label "Notificações" e mostra a lista', () => {
    setNotifications([notification({ read_at: '2026-10-04T09:00:00.000Z' })])
    renderSidebarPanel()

    expect(screen.getByLabelText('Notificações')).toBeInTheDocument()
    expect(screen.getByText('Fatura do Nubank vence em 3 dias')).toBeInTheDocument()
  })
})
