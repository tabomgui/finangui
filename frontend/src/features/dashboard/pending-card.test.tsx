import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { PendingCard } from './pending-card'

const useTransferSuggestions = vi.fn()

vi.mock('@/api/queries/transfer-suggestions', () => ({
  useTransferSuggestions: () => useTransferSuggestions(),
}))

function renderCard() {
  return render(
    <MemoryRouter>
      <PendingCard />
    </MemoryRouter>,
  )
}

describe('PendingCard', () => {
  it('sem sugestões pendentes, não renderiza nada', () => {
    useTransferSuggestions.mockReturnValue({ data: { pages: [{ data: [], meta: { next_cursor: null } }] } })

    const { container } = renderCard()

    expect(container).toBeEmptyDOMElement()
  })

  it('uma sugestão: singular, sem "+"', () => {
    useTransferSuggestions.mockReturnValue({ data: { pages: [{ data: [{ id: 1 }], meta: { next_cursor: null } }] } })

    renderCard()

    expect(screen.getByText('1 sugestão de transferência')).toBeInTheDocument()
    expect(screen.getByRole('link')).toHaveAttribute('href', '/transferencias/sugestoes')
  })

  it('várias sugestões na primeira página, sem mais: plural exato', () => {
    useTransferSuggestions.mockReturnValue({
      data: { pages: [{ data: [{ id: 1 }, { id: 2 }], meta: { next_cursor: null } }] },
    })

    renderCard()

    expect(screen.getByText('2 sugestões de transferência')).toBeInTheDocument()
  })

  it('com cursor seguinte na primeira página, mostra "N+" (sem contar tudo)', () => {
    useTransferSuggestions.mockReturnValue({
      data: { pages: [{ data: [{ id: 1 }, { id: 2 }], meta: { next_cursor: 'abc' } }] },
    })

    renderCard()

    expect(screen.getByText('2+ sugestões de transferência')).toBeInTheDocument()
  })

  it('sem dado ainda (carregando), não renderiza nada', () => {
    useTransferSuggestions.mockReturnValue({ data: undefined })

    const { container } = renderCard()

    expect(container).toBeEmptyDOMElement()
  })
})
