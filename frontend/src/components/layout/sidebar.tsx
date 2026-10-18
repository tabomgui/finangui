import { NavLink, useLocation } from 'react-router-dom'
import {
  Sidebar as SidebarRoot,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarRail,
  SidebarTrigger,
} from '@/components/ui/sidebar'
import { cn } from '@/lib/utils'
import { Logo } from './logo'
import { isNavItemActive, moreNav, newTransactionItem, type NavItem, primaryNav } from './nav-items'
import { NotificationBell } from './notification-bell'
import { ThemeToggle } from './theme-toggle'
import { SignOutButton, UserSummary } from './user-menu-actions'

function SidebarNavButton({ item, pathname }: { item: NavItem; pathname: string }) {
  const Icon = item.icon
  const isActive = isNavItemActive(pathname, item)

  return (
    <SidebarMenuItem>
      <SidebarMenuButton
        asChild
        isActive={isActive}
        tooltip={item.label}
        className={cn(isActive && 'bg-primary/15 text-primary hover:bg-primary/15 hover:text-primary')}
      >
        <NavLink to={item.to} end={item.end}>
          <Icon aria-hidden="true" />
          <span>{item.label}</span>
        </NavLink>
      </SidebarMenuButton>
    </SidebarMenuItem>
  )
}

export function Sidebar() {
  const { pathname } = useLocation()
  const NewIcon = newTransactionItem.icon

  return (
    <SidebarRoot collapsible="icon">
      <SidebarHeader className="gap-3 p-3">
        <div className="flex items-center justify-between">
          <Logo className="group-data-[collapsible=icon]:hidden" />
          <SidebarTrigger />
        </div>
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton
              asChild
              tooltip={newTransactionItem.label}
              className="bg-primary text-primary-foreground hover:bg-primary/90 hover:text-primary-foreground"
            >
              <NavLink to={newTransactionItem.to}>
                <NewIcon aria-hidden="true" />
                <span>{newTransactionItem.label}</span>
              </NavLink>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>
      <SidebarContent className="px-3">
        <SidebarMenu>
          {primaryNav.map((item) => (
            <SidebarNavButton key={item.to} item={item} pathname={pathname} />
          ))}
        </SidebarMenu>
        <div className="my-2 border-t border-sidebar-border" />
        <SidebarMenu>
          {moreNav.map((item) => (
            <SidebarNavButton key={item.to} item={item} pathname={pathname} />
          ))}
        </SidebarMenu>
      </SidebarContent>
      {/* Resumo e ações em linhas separadas: na mesma linha, os três botões deixam uns 80px para
          nome/email, e um email comum já não cabe. Colapsada, o resumo some e as ações empilham
          (3rem de largura não cabe os três lado a lado). */}
      <SidebarFooter className="gap-2 border-t border-sidebar-border p-3">
        <div className="min-w-0 group-data-[collapsible=icon]:hidden">
          <UserSummary />
        </div>
        <div className="flex items-center gap-1 group-data-[collapsible=icon]:flex-col group-data-[collapsible=icon]:gap-2">
          <NotificationBell variant="sidebar" />
          <ThemeToggle />
          <SignOutButton className="ml-auto group-data-[collapsible=icon]:ml-0" />
        </div>
      </SidebarFooter>
      <SidebarRail />
    </SidebarRoot>
  )
}
