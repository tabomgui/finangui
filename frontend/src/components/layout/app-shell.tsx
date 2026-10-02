import { Outlet, ScrollRestoration } from 'react-router-dom'
import { BottomNav } from './bottom-nav'
import { NavigationProgress } from './navigation-progress'
import { Sidebar } from './sidebar'

export function AppShell() {
  return (
    <div className="min-h-dvh bg-background">
      <NavigationProgress />
      <Sidebar />
      <div className="md:pl-60">
        <main className="pb-[calc(6rem+env(safe-area-inset-bottom))] md:pb-10">
          <Outlet />
        </main>
      </div>
      <BottomNav />
      <ScrollRestoration />
    </div>
  )
}
