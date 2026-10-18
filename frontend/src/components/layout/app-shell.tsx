import { Outlet, ScrollRestoration } from 'react-router-dom'
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar'
import { BottomNav } from './bottom-nav'
import { NavigationProgress } from './navigation-progress'
import { readSidebarState } from './sidebar-cookie'
import { Sidebar } from './sidebar'

export function AppShell() {
  return (
    <SidebarProvider defaultOpen={readSidebarState()} className="min-h-dvh bg-background">
      <NavigationProgress />
      <Sidebar />
      <SidebarInset className="pb-[calc(6rem+env(safe-area-inset-bottom))] md:pb-10">
        <Outlet />
      </SidebarInset>
      <BottomNav />
      <ScrollRestoration />
    </SidebarProvider>
  )
}
