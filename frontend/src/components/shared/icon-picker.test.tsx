import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { IconPicker } from './icon-picker'

describe('IconPicker', () => {
  it('abre a grade e informa o ícone escolhido', () => {
    const onChange = vi.fn()
    render(<IconPicker id="icon" value="tag" color="#10b981" onChange={onChange} />)

    fireEvent.click(screen.getByRole('button', { name: 'Escolher ícone' }))
    fireEvent.click(screen.getByRole('button', { name: 'Ícone Talheres' }))

    expect(onChange).toHaveBeenCalledWith('utensils')
  })
})
