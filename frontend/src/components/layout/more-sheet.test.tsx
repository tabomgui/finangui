import { act } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { MoreSheet } from './more-sheet'

/**
 * jsdom não implementa `matchMedia`; aqui controlamos `matches` manualmente e disparamos o
 * listener de `change` registrado pelo componente, para simular o cruzamento do breakpoint de
 * desktop com o `Sheet` já aberto.
 */
function stubMatchMedia() {
  const listeners = new Set<(event: MediaQueryListEvent) => void>()
  let matches = false

  window.matchMedia = ((query: string) =>
    ({
      // getter: o hook também lê `media.matches` de forma síncrona (ao montar/reabrir), não só
      // pelo evento `change` — o stub precisa refletir o estado atual nas duas formas.
      get matches() {
        return matches
      },
      media: query,
      onchange: null,
      addListener: () => {},
      removeListener: () => {},
      addEventListener: (_: string, listener: (event: MediaQueryListEvent) => void) => listeners.add(listener),
      removeEventListener: (_: string, listener: (event: MediaQueryListEvent) => void) => listeners.delete(listener),
      dispatchEvent: () => false,
    }) as unknown as MediaQueryList) as typeof window.matchMedia

  // O listener chama `setOpen`, fora de qualquer handler do Testing Library: sem `act()`, a
  // atualização fica agendada e o teste não veria o resultado ainda na mesma sincronia.
  function setMatches(next: boolean) {
    matches = next
    act(() => {
      for (const listener of listeners) listener({ matches: next } as MediaQueryListEvent)
    })
  }

  return {
    crossToDesktop() {
      setMatches(true)
    },
    crossToMobile() {
      setMatches(false)
    },
  }
}

function renderMoreSheet() {
  const router = createMemoryRouter([{ path: '*', element: <MoreSheet /> }], { initialEntries: ['/'] })
  render(
    <QueryClientProvider client={new QueryClient()}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  )
}

describe('MoreSheet', () => {
  it('fecha ao cruzar para desktop enquanto está aberto', () => {
    const media = stubMatchMedia()
    renderMoreSheet()

    fireEvent.click(screen.getByRole('button', { name: 'Mais' }))
    expect(screen.getByText('Contas')).toBeInTheDocument()

    media.crossToDesktop()

    expect(screen.queryByText('Contas')).not.toBeInTheDocument()
  })

  it('redimensionar sem abrir não faz nada (sem gatilho de fechamento pendurado)', () => {
    const media = stubMatchMedia()
    renderMoreSheet()

    media.crossToDesktop()

    expect(screen.queryByText('Contas')).not.toBeInTheDocument()
  })

  it('fecha ao cruzar para desktop e continua fechando depois de voltar ao mobile e reabrir', () => {
    const media = stubMatchMedia()
    renderMoreSheet()

    fireEvent.click(screen.getByRole('button', { name: 'Mais' }))
    expect(screen.getByText('Contas')).toBeInTheDocument()

    media.crossToDesktop()
    expect(screen.queryByText('Contas')).not.toBeInTheDocument()

    media.crossToMobile()
    fireEvent.click(screen.getByRole('button', { name: 'Mais' }))
    expect(screen.getByText('Contas')).toBeInTheDocument()

    media.crossToDesktop()
    expect(screen.queryByText('Contas')).not.toBeInTheDocument()
  })
})
