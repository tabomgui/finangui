import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { SidebarProvider } from '@/components/ui/sidebar'
import { Sidebar } from './sidebar'

vi.mock('@/api/queries/auth', () => ({
  useMe: () => ({ data: { id: 1, name: 'Ana', email: 'ana@example.com' } }),
  useLogout: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

vi.mock('@/api/queries/notifications', () => ({
  useUnreadCount: () => ({ data: 0 }),
}))

// jsdom não implementa `matchMedia`; `useIsMobile` (shadcn) chama sem guarda.
function stubMatchMedia() {
  window.matchMedia = ((query: string) =>
    ({
      matches: false,
      media: query,
      onchange: null,
      addListener: () => {},
      removeListener: () => {},
      addEventListener: () => {},
      removeEventListener: () => {},
      dispatchEvent: () => false,
    }) as unknown as MediaQueryList) as typeof window.matchMedia
}

function renderSidebar(initialPath = '/') {
  stubMatchMedia()
  const router = createMemoryRouter([{ path: '*', element: <Sidebar /> }], { initialEntries: [initialPath] })
  render(
    <QueryClientProvider client={new QueryClient()}>
      <SidebarProvider>
        <RouterProvider router={router} />
      </SidebarProvider>
    </QueryClientProvider>,
  )
}

describe('Sidebar', () => {
  it('começa expandida e o gatilho colapsa/expande', () => {
    renderSidebar()

    const sidebar = document.querySelector('[data-slot="sidebar"]')
    const trigger = document.querySelector('[data-sidebar="trigger"]') as HTMLElement
    expect(sidebar).toHaveAttribute('data-state', 'expanded')

    fireEvent.click(trigger)
    expect(sidebar).toHaveAttribute('data-state', 'collapsed')

    fireEvent.click(trigger)
    expect(sidebar).toHaveAttribute('data-state', 'expanded')
  })

  it('marca como ativo o item da rota atual, calculado pela URL', () => {
    renderSidebar('/cartoes')

    const activeLink = screen.getByRole('link', { name: /Cartões/ })
    const inactiveLink = screen.getByRole('link', { name: /Início/ })

    expect(activeLink.closest('[data-sidebar="menu-button"]')).toHaveAttribute('data-active', 'true')
    expect(inactiveLink.closest('[data-sidebar="menu-button"]')).toHaveAttribute('data-active', 'false')
  })
})
