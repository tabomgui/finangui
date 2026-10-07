import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { Slice } from './spending-donut'

const sectorProps = { cx: 50, cy: 50, innerRadius: 20, outerRadius: 40, startAngle: 0, endAngle: 180, fill: '#dc2626' }

const payload = {
  key: '1',
  categoryId: 1,
  name: 'Alimentação',
  color: null,
  displayColor: '#dc2626',
  icon: null,
  amount: 30000,
  count: 3,
  direct: false,
  hasChildren: false,
  percent: 60,
  percentLabel: '60%',
  amountLabel: 'R$ 300,00',
}

function renderSlice(overrides: Partial<Parameters<typeof Slice>[0]> = {}) {
  const onSelect = vi.fn()
  const { container } = render(
    <svg>
      <Slice sectorProps={sectorProps} payload={payload} highlightKey={null} onSelect={onSelect} {...overrides} />
    </svg>,
  )
  return { container, onSelect }
}

describe('Slice (fatia do donut)', () => {
  it('não entra no tab (tabIndex=-1): a legenda é o caminho de teclado, não a fatia', () => {
    const { container } = renderSlice()
    expect(container.querySelector('g')).toHaveAttribute('tabindex', '-1')
  })

  it('descreve nome, valor e percentual no aria-label', () => {
    renderSlice()
    expect(screen.getByLabelText('Alimentação, R$ 300,00, 60%')).toBeInTheDocument()
  })

  it('clique do mouse ainda funciona e chama onSelect com a entrada', () => {
    const { container, onSelect } = renderSlice()
    fireEvent.click(container.querySelector('g')!)
    expect(onSelect).toHaveBeenCalledWith(payload)
  })

  it('esmaece (fillOpacity reduzido) quando outra chave está destacada', () => {
    const { container } = renderSlice({ highlightKey: 'other' })
    expect(container.querySelector('path')).toHaveAttribute('fill-opacity', '0.35')
  })

  it('não esmaece quando a própria entrada é a destacada, nem quando nada está destacado', () => {
    const { container: own } = renderSlice({ highlightKey: '1' })
    expect(own.querySelector('path')).toHaveAttribute('fill-opacity', '1')

    const { container: none } = renderSlice({ highlightKey: null })
    expect(none.querySelector('path')).toHaveAttribute('fill-opacity', '1')
  })
})
