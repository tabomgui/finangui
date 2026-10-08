import { beforeEach, describe, expect, it } from 'vitest'
import { getLastUsedAccountId, pickDefaultAccountId, rememberLastUsedAccountId } from './last-used-account'

beforeEach(() => {
  window.localStorage.clear()
})

describe('pickDefaultAccountId', () => {
  it('sem lista, devolve null', () => {
    expect(pickDefaultAccountId(undefined)).toBeNull()
    expect(pickDefaultAccountId([])).toBeNull()
  })

  it('sem histórico, usa a primeira conta que não é cartão', () => {
    const accounts = [
      { id: 1, type: 'credit_card' },
      { id: 2, type: 'checking' },
    ]

    expect(pickDefaultAccountId(accounts)).toBe(2)
  })

  it('sem histórico e só cartão na lista, usa a primeira mesmo assim', () => {
    expect(pickDefaultAccountId([{ id: 1, type: 'credit_card' }])).toBe(1)
  })

  it('com histórico presente na lista, usa a última conta usada mesmo que outra venha antes', () => {
    rememberLastUsedAccountId(2)
    const accounts = [
      { id: 1, type: 'checking' },
      { id: 2, type: 'checking' },
    ]

    expect(pickDefaultAccountId(accounts)).toBe(2)
  })

  it('conta nova com id alfabeticamente antes não rouba o padrão depois de já haver um uso anterior', () => {
    rememberLastUsedAccountId(2)
    // "Acai" (id 3) viria antes de "Inter" (id 2) numa ordenação alfabética vinda do backend,
    // mas não é o que o usuário usou por último.
    const accounts = [
      { id: 3, type: 'checking' },
      { id: 2, type: 'checking' },
    ]

    expect(pickDefaultAccountId(accounts)).toBe(2)
  })

  it('histórico aponta para uma conta que não está mais na lista (ex.: arquivada): cai no fallback', () => {
    rememberLastUsedAccountId(99)
    const accounts = [{ id: 1, type: 'checking' }]

    expect(pickDefaultAccountId(accounts)).toBe(1)
  })
})

describe('getLastUsedAccountId / rememberLastUsedAccountId', () => {
  it('sem nada guardado, devolve null', () => {
    expect(getLastUsedAccountId()).toBeNull()
  })

  it('guarda e lê de volta', () => {
    rememberLastUsedAccountId(7)

    expect(getLastUsedAccountId()).toBe(7)
  })
})
