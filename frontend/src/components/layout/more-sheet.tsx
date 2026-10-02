import { Ellipsis, LogOut } from 'lucide-react'
import { useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet'
import { cn } from '@/lib/utils'
import { moreNav } from './nav-items'
import { ThemeToggle } from './theme-toggle'
import { useSignOut } from './use-sign-out'
import { UserSummary } from './user-menu-actions'

export function MoreSheet() {
  const [open, setOpen] = useState(false)
  const location = useLocation()
  const { signOut, pending } = useSignOut()
  const active = moreNav.some(
    (item) => location.pathname === item.to || location.pathname.startsWith(item.to + '/'),
  )

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <button
          type="button"
          className={cn(
            'flex flex-col items-center gap-1 py-2 text-[11px] font-medium transition-colors',
            active ? 'text-primary' : 'text-muted-foreground hover:text-foreground',
          )}
        >
          <Ellipsis className="h-5 w-5" />
          Mais
        </button>
      </SheetTrigger>
      <SheetContent side="bottom" className="rounded-t-2xl pb-[calc(1rem+env(safe-area-inset-bottom))]">
        <SheetHeader>
          <SheetTitle>Mais</SheetTitle>
        </SheetHeader>
        <div className="space-y-1 px-4">
          {moreNav.map((item) => {
            const Icon = item.icon
            return (
              <NavLink
                key={item.to}
                to={item.to}
                onClick={() => setOpen(false)}
                className={({ isActive }) =>
                  cn(
                    'flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium',
                    isActive ? 'bg-primary/15 text-primary' : 'hover:bg-muted',
                  )
                }
              >
                <Icon className="h-5 w-5" />
                {item.label}
              </NavLink>
            )
          })}
          <div className="flex items-center gap-3 border-t border-border px-3 pt-4">
            <div className="flex-1">
              <UserSummary />
            </div>
            <ThemeToggle />
          </div>
          <button
            type="button"
            disabled={pending}
            onClick={() => {
              setOpen(false)
              void signOut()
            }}
            className="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium text-destructive hover:bg-muted"
          >
            <LogOut className="h-5 w-5" />
            Sair
          </button>
        </div>
      </SheetContent>
    </Sheet>
  )
}
