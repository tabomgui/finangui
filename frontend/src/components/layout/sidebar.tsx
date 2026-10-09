import { NavLink } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { Logo } from './logo'
import { moreNav, newTransactionItem, type NavItem, primaryNav } from './nav-items'
import { NotificationBell } from './notification-bell'
import { ThemeToggle } from './theme-toggle'
import { SignOutButton, UserSummary } from './user-menu-actions'

function SidebarLink({ item }: { item: NavItem }) {
  const Icon = item.icon
  return (
    <NavLink
      to={item.to}
      end={item.end}
      className={({ isActive }) =>
        cn(
          'flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium outline-none transition-colors focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
          isActive ? 'bg-primary/15 text-primary' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
        )
      }
    >
      <Icon className="h-4 w-4" aria-hidden="true" />
      {item.label}
    </NavLink>
  )
}

export function Sidebar() {
  const NewIcon = newTransactionItem.icon

  return (
    <aside className="fixed inset-y-0 left-0 z-30 hidden w-60 flex-col border-r border-border bg-card md:flex">
      <div className="flex h-16 items-center px-5">
        <Logo />
      </div>
      <div className="px-3">
        <Button asChild className="w-full justify-start gap-2 rounded-xl">
          <NavLink to={newTransactionItem.to}>
            <NewIcon className="h-4 w-4" aria-hidden="true" />
            {newTransactionItem.label}
          </NavLink>
        </Button>
      </div>
      <nav aria-label="Navegação principal" className="mt-4 flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto px-3 pb-3">
        {primaryNav.map((item) => (
          <SidebarLink key={item.to} item={item} />
        ))}
        <div className="my-2 border-t border-border" />
        {moreNav.map((item) => (
          <SidebarLink key={item.to} item={item} />
        ))}
      </nav>
      {/* Resumo e ações em linhas separadas: na mesma linha, os três botões deixam uns 80px para
          nome/email, e um email comum já não cabe. */}
      <div className="space-y-2 border-t border-border p-3">
        <UserSummary />
        <div className="flex items-center gap-1">
          <NotificationBell variant="sidebar" />
          <ThemeToggle />
          <SignOutButton className="ml-auto" />
        </div>
      </div>
    </aside>
  )
}
