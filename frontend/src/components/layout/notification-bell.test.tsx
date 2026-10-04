import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { NotificationBell } from './notification-bell'

let unreadCount = 0

vi.mock('@/api/queries/notifications', () => ({
  useUnreadCount: () => ({ data: unreadCount }),
}))

// Mocka o módulo inteiro (o lazy-loaded): testa que o sino monta o painel com as props certas,
// sem precisar da lista/Sheet/Popover reais de verdade aqui (isso é coberto em outros arquivos).
vi.mock('@/features/notifications/notification-panel', () => ({
  NotificationPanel: ({ variant, open, onOpenChange, unreadCount: count }: Record<string, unknown>) => (
    <div data-testid="panel" data-variant={String(variant)} data-open={String(open)} data-unread={String(count)}>
      <button type="button" onClick={() => (onOpenChange as (next: boolean) => void)(false)}>
        fechar-painel
      </button>
    </div>
  ),
}))

beforeEach(() => {
  unreadCount = 0
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

  it('antes do primeiro clique, não monta o painel (nem o chunk lazy)', () => {
    render(<NotificationBell variant="header" />)

    expect(screen.queryByTestId('panel')).not.toBeInTheDocument()
  })

  it('clicar no sino monta o painel aberto com o variant certo', async () => {
    unreadCount = 3
    render(<NotificationBell variant="header" />)

    fireEvent.click(screen.getByLabelText('Notificações, 3 não lidas'))

    const panel = await screen.findByTestId('panel')
    expect(panel).toHaveAttribute('data-variant', 'header')
    expect(panel).toHaveAttribute('data-open', 'true')
    expect(panel).toHaveAttribute('data-unread', '3')
  })

  it('fechar pelo painel mantém ele montado, só com open=false', async () => {
    render(<NotificationBell variant="header" />)
    fireEvent.click(screen.getByLabelText('Notificações, 0 não lidas'))
    await screen.findByTestId('panel')

    fireEvent.click(screen.getByText('fechar-painel'))

    expect(screen.getByTestId('panel')).toHaveAttribute('data-open', 'false')
  })

  it('passar o mouse ou focar não monta o painel por si só', () => {
    render(<NotificationBell variant="header" />)
    const trigger = screen.getByLabelText('Notificações, 0 não lidas')

    fireEvent.pointerEnter(trigger)
    fireEvent.focus(trigger)

    expect(screen.queryByTestId('panel')).not.toBeInTheDocument()
  })
})

describe('NotificationBell (sidebar, desktop)', () => {
  it('mostra a contagem de não lidas no botão ghost e monta o painel com variant="sidebar"', async () => {
    unreadCount = 2
    render(<NotificationBell variant="sidebar" />)

    expect(screen.getByText('2')).toBeInTheDocument()

    fireEvent.click(screen.getByLabelText('Notificações, 2 não lidas'))

    const panel = await screen.findByTestId('panel')
    expect(panel).toHaveAttribute('data-variant', 'sidebar')
  })
})
