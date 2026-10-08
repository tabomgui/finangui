import { Bell } from 'lucide-react'
import { lazy, Suspense, useState } from 'react'
import { useUnreadCount } from '@/api/queries/notifications'
import { Button } from '@/components/ui/button'
import { Popover, PopoverTrigger } from '@/components/ui/popover'
import { Sheet, SheetTrigger } from '@/components/ui/sheet'
import { cn } from '@/lib/utils'
import { headerIconButton } from './theme-toggle'
import { useCloseOnDesktop } from './use-close-on-desktop'

// `.then` mantém o módulo com export nomeado (convenção do projeto: nunca `export default`) e
// ainda satisfaz o formato que `React.lazy` espera.
const NotificationPanel = lazy(() =>
  import('@/features/notifications/notification-panel').then((m) => ({ default: m.NotificationPanel })),
)

/** Começa a baixar o chunk do painel antes do clique (hover/foco no sino), sem abrir nada ainda. */
function prefetchPanel() {
  void import('@/features/notifications/notification-panel')
}

type NotificationBellProps = {
  /** `header`: botão branco no cabeçalho, abre um `Sheet` de baixo (mobile). `sidebar`: botão
   * `ghost` no rodapé da barra lateral, abre um `Popover` (desktop) — os dois nunca ficam visíveis
   * ao mesmo tempo (mesma regra do `ThemeToggle`: um some em `md:hidden`, o outro só existe dentro
   * do `Sidebar`, que é `hidden md:flex`), então não precisa de media query em JS para escolher. */
  variant: 'header' | 'sidebar'
  className?: string
}

function badgeLabel(count: number): string {
  return count > 9 ? '9+' : String(count)
}

/**
 * Só o gatilho, o badge e `useUnreadCount` ficam no bundle eager (carregado em toda página, via
 * `PageHeader`/`Sidebar`): o `Sheet`/`Popover`, a lista e `useNotifications` pesam mais (recharts
 * não — esse é só o próprio sino —, mas `date-fns`-like formatação, mutações e o conteúdo do
 * `Popover`/`Sheet` somados não são de graça) e vão para `notification-panel.tsx`, importado sob
 * demanda. `SheetTrigger`/`PopoverTrigger` (não um `<button>` solto) são o que devolve o foco pro
 * gatilho ao fechar — é o `triggerRef` registrado por eles que o Radix usa pra isso — e, no caso
 * do `Popover`, também ancoram o painel nele automaticamente quando não há um `PopoverAnchor`
 * próprio.
 */
export function NotificationBell({ variant, className }: NotificationBellProps) {
  const [open, setOpen] = useState(false)
  const [touched, setTouched] = useState(false)
  const { data: unreadCount = 0 } = useUnreadCount()

  // Só a variante `header` vira `md:hidden` (ver uso em `page-header.tsx`): o gatilho some ao
  // cruzar para desktop, mas o `Sheet` fica num portal e não ouviria essa mudança por si só.
  useCloseOnDesktop(variant === 'header' && open, setOpen)

  function handleOpenChange(next: boolean) {
    if (next) setTouched(true)
    setOpen(next)
  }

  const label = `Notificações, ${unreadCount} não lidas`
  const badge = unreadCount > 0 && (
    <span
      aria-hidden
      className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold text-white"
    >
      {badgeLabel(unreadCount)}
    </span>
  )

  const trigger =
    variant === 'header' ? (
      <button
        type="button"
        aria-label={label}
        title="Notificações"
        onPointerEnter={prefetchPanel}
        onFocus={prefetchPanel}
        className={cn(headerIconButton, 'relative', className)}
      >
        <Bell className="h-5 w-5" aria-hidden="true" />
        {badge}
      </button>
    ) : (
      <Button
        type="button"
        variant="ghost"
        size="icon"
        aria-label={label}
        title="Notificações"
        onPointerEnter={prefetchPanel}
        onFocus={prefetchPanel}
        className={cn('relative', className)}
      >
        <Bell className="h-4 w-4" aria-hidden="true" />
        {badge}
      </Button>
    )

  const panel = touched && (
    <Suspense fallback={null}>
      <NotificationPanel variant={variant} open={open} onOpenChange={handleOpenChange} unreadCount={unreadCount} />
    </Suspense>
  )

  if (variant === 'header') {
    return (
      <Sheet open={open} onOpenChange={handleOpenChange}>
        <SheetTrigger asChild>{trigger}</SheetTrigger>
        {panel}
      </Sheet>
    )
  }

  return (
    <Popover open={open} onOpenChange={handleOpenChange}>
      <PopoverTrigger asChild>{trigger}</PopoverTrigger>
      {panel}
    </Popover>
  )
}
