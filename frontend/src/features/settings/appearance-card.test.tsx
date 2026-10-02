import { fireEvent, render, screen } from '@testing-library/react'
import { ThemeProvider } from 'next-themes'
import { beforeAll, describe, expect, it } from 'vitest'
import { AppearanceCard } from './appearance-card'

// jsdom não implementa matchMedia; next-themes usa para resolver o tema "system".
beforeAll(() => {
  window.matchMedia ??= (query: string) =>
    ({
      matches: false,
      media: query,
      onchange: null,
      addListener: () => {},
      removeListener: () => {},
      addEventListener: () => {},
      removeEventListener: () => {},
      dispatchEvent: () => false,
    }) as unknown as MediaQueryList
})

function renderCard() {
  return render(
    <ThemeProvider attribute="class" defaultTheme="system">
      <AppearanceCard />
    </ThemeProvider>,
  )
}

describe('AppearanceCard', () => {
  it('marca o botão clicado como pressionado', () => {
    renderCard()

    const dark = screen.getByRole('button', { name: 'Escuro' })
    expect(dark).toHaveAttribute('aria-pressed', 'false')

    fireEvent.click(dark)

    expect(dark).toHaveAttribute('aria-pressed', 'true')
  })
})
