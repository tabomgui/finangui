import { useNavigate } from 'react-router-dom'
import { useMarkAllRead, useMarkRead, useNotifications } from '@/api/queries/notifications'
import type { Notification } from '@/api/types'
import { Button } from '@/components/ui/button'
import { PopoverContent } from '@/components/ui/popover'
import { SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { NotificationList } from './notification-list'

type NotificationPanelProps = {
  variant: 'header' | 'sidebar'
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Vem do sino (`useUnreadCount`, sempre ativo): a lista paginada só tem as páginas já
   * carregadas, então não serve pra decidir se "Marcar todas como lidas" tem o que fazer. */
  unreadCount: number
}

/** `/relativo`, nunca `//host-externo` (protocol-relative) nem uma URL absoluta de outro site. */
function isInternalUrl(url: string): boolean {
  return url.startsWith('/') && !url.startsWith('//')
}

/**
 * Conteúdo pesado do sino (carregado só sob demanda, ver `notification-bell.tsx`): o `Sheet`
 * (mobile) ou `Popover` (desktop) propriamente dito, a lista e as mutações de leitura. `open`
 * controla a busca (`useNotifications(open)`): fica pausada enquanto o painel está fechado (mas
 * já montado, pra animação de saída) e busca de novo, fresca, a cada reabertura.
 */
export function NotificationPanel({ variant, open, onOpenChange, unreadCount }: NotificationPanelProps) {
  const { data, fetchNextPage, hasNextPage, isFetchingNextPage, isPending, isError, refetch } = useNotifications(open)
  const markRead = useMarkRead()
  const markAllRead = useMarkAllRead()
  const navigate = useNavigate()

  const notifications = data?.pages.flatMap((page) => page.data) ?? []

  function handleSelect(notification: Notification) {
    if (notification.read_at === null) markRead.mutate(notification.id)
    onOpenChange(false)
    if (isInternalUrl(notification.url)) navigate(notification.url)
  }

  const list = (
    <NotificationList
      notifications={notifications}
      isPending={isPending}
      isError={isError}
      onRetry={() => refetch()}
      hasNextPage={!!hasNextPage}
      isFetchingNextPage={isFetchingNextPage}
      onLoadMore={() => fetchNextPage()}
      onSelect={handleSelect}
    />
  )

  const markAllButton = (
    <Button
      type="button"
      variant="ghost"
      size="sm"
      disabled={unreadCount === 0 || markAllRead.isPending}
      onClick={() => markAllRead.mutate()}
    >
      Marcar todas como lidas
    </Button>
  )

  if (variant === 'header') {
    return (
      <SheetContent
        side="bottom"
        className="mx-auto flex max-h-[85vh] max-w-md flex-col overscroll-contain rounded-t-2xl pb-[calc(1rem+env(safe-area-inset-bottom))]"
      >
        <SheetHeader className="flex-row items-center justify-between gap-2 pr-10">
          <SheetTitle>Notificações</SheetTitle>
          {markAllButton}
        </SheetHeader>
        <div className="flex-1 overflow-y-auto">{list}</div>
      </SheetContent>
    )
  }

  return (
    <PopoverContent side="top" align="start" aria-label="Notificações" className="w-96 p-0">
      <div className="flex items-center justify-between border-b border-border p-2 pl-3">
        <p className="text-sm font-semibold">Notificações</p>
        {markAllButton}
      </div>
      <div className="max-h-[70vh] overflow-y-auto">{list}</div>
    </PopoverContent>
  )
}
