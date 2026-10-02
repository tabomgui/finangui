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
})
