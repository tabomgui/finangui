import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { act } from 'react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { authStatusKey, meKey } from '@/api/queries/auth'
import type { User } from '@/api/types'
import { ProtectedRoute } from './protected-route'
import { LoginPage } from './login-page'

function renderApp(queryClient: QueryClient) {
  const router = createMemoryRouter(
    [
      { path: '/login', element: <LoginPage /> },
      {
        element: <ProtectedRoute />,
        children: [{ path: '/contas', element: <p>Tela de contas</p> }],
      },
    ],
    { initialEntries: ['/contas'] },
  )

  render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  )

  return router
}

describe('LoginPage', () => {
  it('preserva a rota pretendida ao redirecionar para o login e de volta após autenticar', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    queryClient.setQueryData(meKey, null)
    queryClient.setQueryData(authStatusKey, { google_login_enabled: false, registration_enabled: false })

    const router = renderApp(queryClient)

    expect(await screen.findByRole('heading', { name: 'Entrar' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/login')
    expect(router.state.location.state).toEqual({ from: '/contas' })

    const user: User = {
      id: 1,
      name: 'Dev',
      email: 'dev@finangui.test',
      avatar: null,
      has_password: true,
      google_linked: false,
      primary_currency: 'BRL',
      banking_enabled: false,
    }

    await act(async () => {
      queryClient.setQueryData(meKey, user)
    })

    expect(await screen.findByText('Tela de contas')).toBeInTheDocument()
  })
})
