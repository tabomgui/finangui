import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { CategoryIcon } from './category-icon'

describe('CategoryIcon', () => {
  it('renderiza o ícone quando o nome é conhecido', () => {
    const { container } = render(<CategoryIcon icon="heart-pulse" color={null} />)

    const icon = container.querySelector('svg')
    expect(icon).toBeInTheDocument()
    expect(icon).toHaveClass('lucide-heart-pulse')
  })

  it('cai no ícone de fallback quando o nome não é conhecido', () => {
    const { container } = render(<CategoryIcon icon="nao-existe" color={null} />)

    const icon = container.querySelector('svg')
    expect(icon).toHaveClass('lucide-tag')
  })

  it('aplica a cor como tinta de fundo e texto', () => {
    const { container } = render(<CategoryIcon icon="house" color="#ff0000" />)

    const wrapper = container.querySelector('span[aria-hidden="true"]')
    expect(wrapper).toHaveStyle({ color: '#ff0000' })
  })
})
