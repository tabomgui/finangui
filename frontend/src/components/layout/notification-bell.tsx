import { Bell } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMarkAllRead, useMarkRead, useNotifications, useUnreadCount } from '@/api/queries/notifications'
import type { Notification } from '@/api/types'
import { Button } from '@/components/ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet'
import { NotificationList } from '@/features/notifications/notification-list'
import { cn } from '@/lib/utils'
import { headerIconButton } from './theme-toggle'

type NotificationBellProps = {
  /** `header`: botão branco no cabeçalho, abre um `Sheet` de baixo (mobile). `sidebar`: botão
   * `ghost` no rodapé da barra lateral, abre um `Popover` (desktop) — os dois nunca ficam visíveis
   * ao mesmo tempo (mesma regra do `ThemeToggle`: um some em `md:hidden`, o outro só existe dentro
   * do `Sidebar`, que é `hidden md:flex`), então não precisa de media query em JS para escolher. */
  variant: 'header' | 'sidebar'
  className?: string
}

/** `/relativo`, nunca `//host-externo` (protocol-relative) nem uma URL absoluta de outro site. */
function isInternalUrl(url: string): boolean {
  return url.startsWith('/') && !url.startsWith('//')
}

function badgeLabel(count: number): string {
  return count > 9 ? '9+' : String(count)
}

export function NotificationBell({ variant, className }: NotificationBellProps) {
  const [open, setOpen] = useState(false)
  const { data: unreadCount = 0 } = useUnreadCount()
  const { data, fetchNextPage, hasNextPage, isFetchingNextPage, isPending } = useNotifications()
  const markRead = useMarkRead()
  const markAllRead = useMarkAllRead()
  const navigate = useNavigate()

  const notifications = data?.pages.flatMap((page) => page.data) ?? []

  function handleSelect(notification: Notification) {
    setOpen(false)
    if (notification.read_at === null) markRead.mutate(notification.id)
    if (isInternalUrl(notification.url)) navigate(notification.url)
  }

  const trigger =
    variant === 'header' ? (
      <button
        type="button"
        aria-label={`Notificações, ${unreadCount} não lidas`}
        title="Notificações"
        className={cn(headerIconButton, 'relative', className)}
      >
        <Bell className="h-5 w-5" />
        {unreadCount > 0 && <NotificationBadge count={unreadCount} />}
      </button>
    ) : (
      <Button
        type="button"
        variant="ghost"
        size="icon"
        aria-label={`Notificações, ${unreadCount} não lidas`}
        title="Notificações"
        className={cn('relative', className)}
      >
        <Bell className="h-4 w-4" />
        {unreadCount > 0 && <NotificationBadge count={unreadCount} />}
      </Button>
    )

  const list = (
    <NotificationList
      notifications={notifications}
      isPending={isPending}
      hasNextPage={!!hasNextPage}
      isFetchingNextPage={isFetchingNextPage}
      onLoadMore={() => fetchNextPage()}
      onSelect={handleSelect}
    />
  )

  const markAllButton = (
    <Button type="button" variant="ghost" size="sm" disabled={unreadCount === 0 || markAllRead.isPending} onClick={() => markAllRead.mutate()}>
      Marcar todas como lidas
    </Button>
  )

  if (variant === 'header') {
    return (
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetTrigger asChild>{trigger}</SheetTrigger>
        <SheetContent side="bottom" className="flex max-h-[85vh] flex-col rounded-t-2xl pb-[calc(1rem+env(safe-area-inset-bottom))]">
          <SheetHeader className="flex-row items-center justify-between">
            <SheetTitle>Notificações</SheetTitle>
            {markAllButton}
          </SheetHeader>
          <div className="flex-1 overflow-y-auto">{list}</div>
        </SheetContent>
      </Sheet>
    )
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>{trigger}</PopoverTrigger>
      <PopoverContent side="top" align="start" className="w-96 p-0">
        <div className="flex items-center justify-between border-b border-border p-2 pl-3">
          <p className="text-sm font-semibold">Notificações</p>
          {markAllButton}
        </div>
        <div className="max-h-[70vh] overflow-y-auto">{list}</div>
      </PopoverContent>
    </Popover>
  )
}

function NotificationBadge({ count }: { count: number }) {
  return (
    <span
      aria-hidden
      className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold text-white"
    >
      {badgeLabel(count)}
    </span>
  )
}
