import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { MoneyInput } from './money-input'

describe('MoneyInput', () => {
  it('converte o texto digitado em centavos e formata ao sair do campo', () => {
    const onChange = vi.fn()
    render(<MoneyInput aria-label="Valor" value={null} onChange={onChange} />)
    const input = screen.getByLabelText('Valor')

    fireEvent.focus(input)
    fireEvent.change(input, { target: { value: '1069,3' } })
    expect(onChange).toHaveBeenLastCalledWith(106930)

    fireEvent.blur(input)
    expect(input).toHaveValue('1.069,30')
  })

  it('informa null para texto inválido', () => {
    const onChange = vi.fn()
    render(<MoneyInput aria-label="Valor" value={null} onChange={onChange} />)

    fireEvent.change(screen.getByLabelText('Valor'), { target: { value: 'abc' } })

    expect(onChange).toHaveBeenLastCalledWith(null)
  })

  it('mostra o valor recebido', () => {
    render(<MoneyInput aria-label="Valor" value={4590} onChange={() => {}} />)

    expect(screen.getByLabelText('Valor')).toHaveValue('45,90')
  })
})
