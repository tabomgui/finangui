import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { CardLimitBar } from './card-limit-bar'

describe('CardLimitBar', () => {
  it('mostra usado (do banco, used_limit), parcelas futuras (local, limit.projected) e disponível (do banco, available_limit)', () => {
    render(
      <CardLimitBar
        credit_limit={100000}
        used_limit={25000}
        available_limit={50000}
        limit={{ used: 99999, projected: 25000, available: 11111 }}
        currency="BRL"
      />,
    )

    expect(screen.getByText('Usado')).toBeInTheDocument()
    expect(screen.getByText('Parcelas futuras')).toBeInTheDocument()
    expect(screen.getByText('Disponível')).toBeInTheDocument()
    expect(screen.getAllByText('R$ 250,00')).toHaveLength(2)
    expect(screen.getByText('R$ 500,00')).toBeInTheDocument()

    const meter = screen.getByRole('meter')
    expect(meter).toHaveAttribute('aria-valuenow', '50000')
    expect(meter).toHaveAttribute('aria-valuemax', '100000')
  })

  it('sem available_limit do banco, cai no disponível calculado localmente (limit.available)', () => {
    render(
      <CardLimitBar
        credit_limit={100000}
        used_limit={25000}
        available_limit={undefined}
        limit={{ used: 25000, projected: 0, available: 75000 }}
        currency="BRL"
      />,
    )

    expect(screen.getByText('R$ 750,00')).toBeInTheDocument()
  })

  it('disponível negativo usa a cor de despesa', () => {
    render(
      <CardLimitBar
        credit_limit={100000}
        used_limit={100000}
        available_limit={-1000}
        limit={{ used: 100000, projected: 1000, available: -1000 }}
        currency="BRL"
      />,
    )

    expect(screen.getByText('-R$ 10,00')).toHaveClass('text-expense')
  })

  it('sem parcelas futuras, a linha não aparece', () => {
    render(
      <CardLimitBar
        credit_limit={100000}
        used_limit={25000}
        available_limit={75000}
        limit={{ used: 25000, projected: 0, available: 75000 }}
        currency="BRL"
      />,
    )

    expect(screen.queryByText('Parcelas futuras')).not.toBeInTheDocument()
  })
})
