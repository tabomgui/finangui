import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { EvolutionTooltip } from './evolution-tooltip'

const row = { month: '2026-10', income: 150000, expense: 80000, net: 70000 }

function payloadFor() {
  return [{ payload: row }]
}

function money(value: string) {
  return new RegExp(`R\\$\\s*${value.replace('.', '\\.')}`)
}

describe('EvolutionTooltip', () => {
  it('não mostra nada quando inativo', () => {
    render(<EvolutionTooltip active={false} payload={payloadFor()} currency="BRL" />)

    expect(screen.queryByText('Receita')).not.toBeInTheDocument()
  })

  it('não mostra nada sem payload', () => {
    render(<EvolutionTooltip active currency="BRL" />)

    expect(screen.queryByText('Receita')).not.toBeInTheDocument()
  })

  it('mostra o mês completo e os três valores por extenso', () => {
    render(<EvolutionTooltip active payload={payloadFor()} currency="BRL" />)

    expect(screen.getByText('Outubro de 2026')).toBeInTheDocument()
    expect(screen.getByText('Receita')).toBeInTheDocument()
    expect(screen.getByText(money('1.500,00'))).toBeInTheDocument()
    expect(screen.getByText('Despesa')).toBeInTheDocument()
    expect(screen.getByText(money('800,00'))).toBeInTheDocument()
    expect(screen.getByText('Resultado')).toBeInTheDocument()
    expect(screen.getByText(money('700,00'))).toBeInTheDocument()
  })
})
