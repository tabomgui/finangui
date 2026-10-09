import { act, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { WALLPAPER_INTERVAL_MS, WallpaperCarousel } from './wallpaper-carousel'

function mockReducedMotion(reduce: boolean) {
  window.matchMedia = ((query: string) =>
    ({
      matches: reduce && query === '(prefers-reduced-motion: reduce)',
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
    }) as unknown as MediaQueryList) as typeof window.matchMedia
}

function activeDot() {
  return screen.getAllByRole('button').findIndex((button) => button.getAttribute('aria-current') === 'true')
}

describe('WallpaperCarousel', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    mockReducedMotion(false)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('avança sozinho e volta para a primeira imagem depois da última', () => {
    render(<WallpaperCarousel />)
    expect(screen.getAllByRole('button')).toHaveLength(3)
    expect(activeDot()).toBe(0)

    act(() => vi.advanceTimersByTime(WALLPAPER_INTERVAL_MS))
    expect(activeDot()).toBe(1)

    act(() => vi.advanceTimersByTime(WALLPAPER_INTERVAL_MS))
    expect(activeDot()).toBe(2)

    act(() => vi.advanceTimersByTime(WALLPAPER_INTERVAL_MS))
    expect(activeDot()).toBe(0)
  })

  it('troca para a imagem escolhida e reinicia a contagem', () => {
    render(<WallpaperCarousel />)

    act(() => vi.advanceTimersByTime(WALLPAPER_INTERVAL_MS - 1000))
    fireEvent.click(screen.getByRole('button', { name: 'Mostrar imagem 3 de 3' }))
    expect(activeDot()).toBe(2)

    act(() => vi.advanceTimersByTime(WALLPAPER_INTERVAL_MS - 1))
    expect(activeDot()).toBe(2)
    act(() => vi.advanceTimersByTime(1))
    expect(activeDot()).toBe(0)
  })

  it('não troca sozinho quando o sistema pede menos movimento', () => {
    mockReducedMotion(true)
    render(<WallpaperCarousel />)

    act(() => vi.advanceTimersByTime(WALLPAPER_INTERVAL_MS))
    act(() => vi.advanceTimersByTime(WALLPAPER_INTERVAL_MS))
    expect(activeDot()).toBe(0)

    fireEvent.click(screen.getByRole('button', { name: 'Mostrar imagem 2 de 3' }))
    expect(activeDot()).toBe(1)
  })
})
