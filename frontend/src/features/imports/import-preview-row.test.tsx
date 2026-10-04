import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { ImportPreviewRow as ImportRow } from '@/api/types'
import { ImportPreviewRow } from './import-preview-row'

function row(overrides: Partial<ImportRow>): ImportRow {
  return {
    line: 2,
    date: '2026-10-01',
    amount: 1500,
    direction: 'out',
    description: 'Mercado Bom Preço',
    outcome: 'new',
    ...overrides,
  }
}

describe('ImportPreviewRow', () => {
  it('mostra descrição, valor e o rótulo do desfecho', () => {
    render(<ImportPreviewRow row={row({})} selected selectable onToggle={vi.fn()} />)

    expect(screen.getByText('Mercado Bom Preço')).toBeInTheDocument()
    expect(screen.getByText(/R\$/)).toHaveClass('text-expense')
    expect(screen.getByText('Nova')).toBeInTheDocument()
  })

  it('mostra o número da parcela quando presente', () => {
    render(
      <ImportPreviewRow
        row={row({ outcome: 'replace_installment', installment: { number: 2, total: 10 } })}
        selected
        selectable
        onToggle={vi.fn()}
      />,
    )

    expect(screen.getByText('Parcela 2/10')).toBeInTheDocument()
  })

  it('mostra com o que a linha casou quando há match', () => {
    render(
      <ImportPreviewRow
        row={row({ outcome: 'adopt', match: { id: 9, date: '2026-09-28', description: 'Mercado', kind: 'manual' } })}
        selected
        selectable
        onToggle={vi.fn()}
      />,
    )

    expect(screen.getByText(/Casa com: Mercado em 28\/09\/2026/)).toBeInTheDocument()
  })

  it('mostra o aviso de lançamento previsto quando o match é com uma recorrência', () => {
    render(
      <ImportPreviewRow
        row={row({ outcome: 'adopt', match: { id: 9, date: '2026-09-28', description: 'Netflix', kind: 'recurrence' } })}
        selected
        selectable
        onToggle={vi.fn()}
      />,
    )

    expect(screen.getByText('Casa com lançamento previsto')).toBeInTheDocument()
    expect(screen.queryByText(/Casa com: Netflix/)).not.toBeInTheDocument()
  })

  it('não mostra a linha de match quando ausente (parcela intra-arquivo)', () => {
    render(
      <ImportPreviewRow row={row({ outcome: 'replace_installment', installment: { number: 1, total: 3 } })} selected selectable onToggle={vi.fn()} />,
    )

    expect(screen.queryByText(/Casa com:/)).not.toBeInTheDocument()
  })

  it('mostra a categoria sugerida com o ícone de regra', () => {
    render(<ImportPreviewRow row={row({ suggested_category_id: 5 })} selected selectable categoryName="Mercado" onToggle={vi.fn()} />)

    expect(screen.getByText('Mercado')).toBeInTheDocument()
  })

  it('duplicada aparece desmarcada e desabilitada', () => {
    render(<ImportPreviewRow row={row({ outcome: 'duplicate' })} selected={false} selectable={false} onToggle={vi.fn()} />)

    const checkbox = screen.getByRole('checkbox')
    expect(checkbox).not.toBeChecked()
    expect(checkbox).toBeDisabled()
  })

  it('chama onToggle com o número da linha ao marcar/desmarcar', () => {
    const onToggle = vi.fn()
    render(<ImportPreviewRow row={row({ line: 7 })} selected selectable onToggle={onToggle} />)

    fireEvent.click(screen.getByRole('checkbox'))
    expect(onToggle).toHaveBeenCalledWith(7)
  })
})
