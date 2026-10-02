import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ColorPicker } from './color-picker'

describe('ColorPicker', () => {
  it('marca a cor atual e informa a escolhida', () => {
    const onChange = vi.fn()
    render(<ColorPicker value="#10b981" onChange={onChange} />)

    expect(screen.getByRole('button', { name: 'Esmeralda' })).toHaveAttribute('aria-pressed', 'true')

    fireEvent.click(screen.getByRole('button', { name: 'Azul' }))
    expect(onChange).toHaveBeenCalledWith('#0ea5e9')
  })

  it('usa marca de confirmação escura sobre cor clara (Amarelo)', () => {
    render(<ColorPicker value="#eab308" onChange={vi.fn()} />)

    const check = screen.getByRole('button', { name: 'Amarelo' }).querySelector('svg')
    expect(check).toHaveClass('text-neutral-900')
  })
})
