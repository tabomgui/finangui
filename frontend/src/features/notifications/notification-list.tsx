import { Bell, TriangleAlert } from 'lucide-react'
import type { Notification } from '@/api/types'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { formatRelativeTime } from './relative-time'

type NotificationListProps = {
  notifications: Notification[]
  isPending: boolean
  isError: boolean
  onRetry: () => void
  hasNextPage: boolean
  isFetchingNextPage: boolean
  onLoadMore: () => void
  onSelect: (notification: Notification) => void
}

/**
 * Lista pura (sem cabeçalho nem "marcar todas"): usada tanto dentro do `Sheet` (mobile) quanto do
 * `Popover` (desktop) do sino, que montam o cabeçalho cada um do seu jeito (ver `notification-panel.tsx`).
 */
export function NotificationList({
  notifications,
  isPending,
  isError,
  onRetry,
  hasNextPage,
  isFetchingNextPage,
  onLoadMore,
  onSelect,
}: NotificationListProps) {
  if (isError) {
    return (
      <div className="flex items-center justify-between gap-2 p-3 text-sm text-muted-foreground">
        <span className="flex items-center gap-2">
          <TriangleAlert className="h-4 w-4" />
          Não foi possível carregar as notificações.
        </span>
        <Button type="button" variant="outline" size="sm" onClick={onRetry}>
          Tentar de novo
        </Button>
      </div>
    )
  }

  if (isPending) {
    return (
      <div className="space-y-2 p-2">
        {[0, 1, 2].map((i) => (
          <Skeleton key={i} className="h-16 w-full rounded-xl" />
        ))}
      </div>
    )
  }

  if (notifications.length === 0) {
    return <EmptyState icon={Bell} title="Nenhuma notificação" />
  }

  return (
    <div className="space-y-1 p-2">
      {notifications.map((notification) => {
        const unread = notification.read_at === null
        return (
          <button
            key={notification.id}
            type="button"
            onClick={() => onSelect(notification)}
            className="flex w-full items-start gap-3 rounded-xl px-3 py-2 text-left hover:bg-muted/50"
          >
            <span
              aria-hidden
              className={unread ? 'mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary' : 'mt-1.5 h-2 w-2 shrink-0 rounded-full bg-transparent'}
            />
            <span className="flex-1 space-y-0.5">
              <span className={unread ? 'block text-sm font-semibold text-foreground' : 'block text-sm font-medium text-foreground'}>
                {unread && <span className="sr-only">Não lida: </span>}
                {notification.title}
              </span>
              <span className="block text-sm text-muted-foreground">{notification.body}</span>
              <span className="block text-xs text-muted-foreground">{formatRelativeTime(notification.created_at)}</span>
            </span>
          </button>
        )
      })}
      {hasNextPage && (
        <div className="pt-1 text-center">
          <Button type="button" variant="ghost" size="sm" disabled={isFetchingNextPage} onClick={onLoadMore}>
            {isFetchingNextPage ? 'Carregando...' : 'Carregar mais'}
          </Button>
        </div>
      )}
    </div>
  )
}
