import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { LoadMore } from './load-more'

describe('LoadMore', () => {
  it('mostra o botão quando há mais itens e chama onLoadMore', () => {
    const onLoadMore = vi.fn()
    render(<LoadMore hasMore loading={false} onLoadMore={onLoadMore} />)

    fireEvent.click(screen.getByRole('button', { name: 'Carregar mais' }))

    expect(onLoadMore).toHaveBeenCalledTimes(1)
  })

  it('não renderiza nada sem mais itens', () => {
    const { container } = render(<LoadMore hasMore={false} loading={false} onLoadMore={() => {}} />)

    expect(container).toBeEmptyDOMElement()
  })

  it('mantém o observer estável entre renders e chama a versão mais recente de onLoadMore', () => {
    class MockIntersectionObserver {
      static instances: MockIntersectionObserver[] = []
      callback: IntersectionObserverCallback
      observe = vi.fn()
      disconnect = vi.fn()
      unobserve = vi.fn()

      constructor(callback: IntersectionObserverCallback) {
        this.callback = callback
        MockIntersectionObserver.instances.push(this)
      }
    }
    vi.stubGlobal('IntersectionObserver', MockIntersectionObserver)

    const first = vi.fn()
    const second = vi.fn()
    const { rerender } = render(<LoadMore hasMore loading={false} onLoadMore={first} />)
    rerender(<LoadMore hasMore loading={false} onLoadMore={second} />)

    expect(MockIntersectionObserver.instances).toHaveLength(1)

    const [instance] = MockIntersectionObserver.instances
    instance.callback([{ isIntersecting: true } as IntersectionObserverEntry], instance as unknown as IntersectionObserver)

    expect(first).not.toHaveBeenCalled()
    expect(second).toHaveBeenCalledTimes(1)

    vi.unstubAllGlobals()
  })
})
