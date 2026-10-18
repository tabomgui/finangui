import { afterEach, describe, expect, it } from 'vitest'
import { readSidebarState } from './sidebar-cookie'

function setCookie(value: string) {
  document.cookie = value
}

function clearCookies() {
  document.cookie = 'sidebar_state=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/'
}

afterEach(clearCookies)

describe('readSidebarState', () => {
  it('lê sidebar_state=false do cookie e devolve fechado', () => {
    setCookie('sidebar_state=false')

    expect(readSidebarState()).toBe(false)
  })

  it('lê sidebar_state=true do cookie e devolve aberto', () => {
    setCookie('sidebar_state=true')

    expect(readSidebarState()).toBe(true)
  })

  it('sem cookie, assume aberto por padrão', () => {
    expect(readSidebarState()).toBe(true)
  })

  it('ignora outros cookies e lê só sidebar_state', () => {
    setCookie('outro=valor')
    setCookie('sidebar_state=false')

    expect(readSidebarState()).toBe(false)
  })
})
