import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { BottomNav } from './bottom-nav'

function renderAt(path: string) {
  const router = createMemoryRouter([{ path: '*', element: <BottomNav /> }], { initialEntries: [path] })
  render(
    <QueryClientProvider client={new QueryClient()}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  )
}

describe('BottomNav', () => {
  it('mostra os destinos principais com rótulo', () => {
    renderAt('/')

    for (const label of ['Início', 'Transações', 'Cartões', 'Mais']) {
      expect(screen.getByText(label)).toBeInTheDocument()
    }
    expect(screen.getByRole('link', { name: 'Nova transação' })).toHaveAttribute('href', '/transacoes/nova')
  })

  it('marca o item ativo sem confundir Transações com Nova', () => {
    renderAt('/transacoes/nova')

    expect(screen.getByRole('link', { name: /Transações/ })).not.toHaveAttribute('aria-current')
  })

  it('marca Transações quando na lista', () => {
    renderAt('/transacoes')

    expect(screen.getByRole('link', { name: /Transações/ })).toHaveAttribute('aria-current', 'page')
  })
})
