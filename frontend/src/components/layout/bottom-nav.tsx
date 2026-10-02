import { NavLink } from 'react-router-dom'
import { cn } from '@/lib/utils'
import { MoreSheet } from './more-sheet'
import { newTransactionItem, type NavItem, primaryNav } from './nav-items'

function BottomNavLink({ item }: { item: NavItem }) {
  const Icon = item.icon
  return (
    <NavLink
      to={item.to}
      end={item.end}
      className={({ isActive }) =>
        cn(
          'flex flex-col items-center gap-1 py-2 text-[11px] font-medium transition-colors',
          isActive ? 'text-primary' : 'text-muted-foreground hover:text-foreground',
        )
      }
    >
      <Icon className="h-5 w-5" />
      {item.label}
    </NavLink>
  )
}

export function BottomNav() {
  const [home, transactions, accounts] = primaryNav
  const NewIcon = newTransactionItem.icon

  return (
    <nav
      aria-label="Navegação principal"
      className="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-card/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden"
    >
      <div className="mx-auto grid h-16 max-w-md grid-cols-5 items-center">
        <BottomNavLink item={home} />
        <BottomNavLink item={transactions} />
        <NavLink to={newTransactionItem.to} aria-label={newTransactionItem.label} className="flex justify-center">
          <span className="-mt-7 flex h-14 w-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg ring-4 ring-background transition-transform active:scale-95">
            <NewIcon className="h-6 w-6" />
          </span>
        </NavLink>
        <BottomNavLink item={accounts} />
        <MoreSheet />
      </div>
    </nav>
  )
}
