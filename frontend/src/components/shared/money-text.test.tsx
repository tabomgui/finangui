import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { MoneyText } from './money-text'

describe('MoneyText', () => {
  it('pinta entradas com a cor de receita e sinal positivo', () => {
    render(<MoneyText cents={4590} direction="in" />)

    const text = screen.getByText(/45,90/)
    expect(text).toHaveTextContent('+R$')
    expect(text).toHaveClass('text-income')
  })

  it('pinta saídas com a cor de despesa', () => {
    render(<MoneyText cents={4590} direction="out" />)

    expect(screen.getByText(/45,90/)).toHaveClass('text-expense')
  })

  it('sem sentido, usa a cor de despesa só para valores negativos', () => {
    render(<MoneyText cents={-100} />)

    expect(screen.getByText(/1,00/)).toHaveClass('text-expense')
  })
})
