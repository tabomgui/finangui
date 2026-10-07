import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { StatementLimitMeter } from './statement-limit-meter'

describe('StatementLimitMeter', () => {
  it('mostra usado de limite e disponível formatados', () => {
    render(<StatementLimitMeter used_limit={52000} available_limit={748000} credit_limit={800000} currency="BRL" />)

    expect(screen.getByText('Limite usado R$ 520,00 de R$ 8.000,00')).toBeInTheDocument()
    expect(screen.getByText('Disponível R$ 7.480,00')).toBeInTheDocument()

    const meter = screen.getByRole('meter', { name: 'Limite usado do cartão' })
    expect(meter).toHaveAttribute('aria-valuenow', '52000')
    expect(meter).toHaveAttribute('aria-valuemax', '800000')
  })

  it('sem disponível (nada para derivar), a linha não aparece', () => {
    render(<StatementLimitMeter used_limit={52000} available_limit={undefined} credit_limit={800000} currency="BRL" />)

    expect(screen.queryByText(/Disponível/)).not.toBeInTheDocument()
  })

  it('disponível negativo usa a cor de despesa', () => {
    render(<StatementLimitMeter used_limit={900000} available_limit={-100000} credit_limit={800000} currency="BRL" />)

    expect(screen.getByText('Disponível -R$ 1.000,00')).toHaveClass('text-expense')
  })

  it('sem limite algum (nem do banco, nem cadastrado), o bloco inteiro some', () => {
    render(<StatementLimitMeter used_limit={0} available_limit={undefined} credit_limit={0} currency="BRL" />)

    expect(screen.queryByText(/Limite usado/)).not.toBeInTheDocument()
  })
})
