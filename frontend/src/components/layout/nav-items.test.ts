import { House } from 'lucide-react'
import { describe, expect, it } from 'vitest'
import { isNavItemActive, type NavItem } from './nav-items'

describe('isNavItemActive', () => {
  it('item end=true ativa só na rota exata', () => {
    const item: NavItem = { to: '/', label: 'Início', icon: House, end: true }

    expect(isNavItemActive('/', item)).toBe(true)
    expect(isNavItemActive('/transacoes', item)).toBe(false)
  })

  it('item sem end ativa na rota exata e em sub-rotas', () => {
    const item: NavItem = { to: '/cartoes', label: 'Cartões', icon: House }

    expect(isNavItemActive('/cartoes', item)).toBe(true)
    expect(isNavItemActive('/cartoes/42', item)).toBe(true)
  })

  it('item sem end não ativa por outra rota que só compartilha o prefixo textual', () => {
    const item: NavItem = { to: '/cartoes', label: 'Cartões', icon: House }

    expect(isNavItemActive('/cartoes-arquivados', item)).toBe(false)
  })

  it('não ativa rota que não bate em nada', () => {
    const item: NavItem = { to: '/cartoes', label: 'Cartões', icon: House }

    expect(isNavItemActive('/metas', item)).toBe(false)
  })
})
