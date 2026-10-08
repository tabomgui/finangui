import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { PageHeader } from './page-header'

function renderHeader() {
  const router = createMemoryRouter([{ path: '*', element: <PageHeader title="Título" back /> }], {
    initialEntries: ['/'],
  })
  render(
    <QueryClientProvider client={new QueryClient()}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  )
}

describe('PageHeader', () => {
  it('botão de voltar mostra o anel de foco visível ao tabular', () => {
    renderHeader()

    const backButton = screen.getByRole('button', { name: 'Voltar' })

    expect(backButton.className).toMatch(/focus-visible:ring/)
  })
})
