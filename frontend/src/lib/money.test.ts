import { describe, expect, it } from 'vitest'
import { centsToInputString, formatCompactMoney, formatMoney, formatSignedMoney, parseMoneyInput } from './money'

const nbsp = ' '

describe('formatMoney', () => {
  it('formata centavos em reais', () => {
    expect(formatMoney(106936)).toBe(`R$${nbsp}1.069,36`)
    expect(formatMoney(0)).toBe(`R$${nbsp}0,00`)
    expect(formatMoney(-4590)).toBe(`-R$${nbsp}45,90`)
  })

  it('aceita outra moeda', () => {
    expect(formatMoney(1050, 'USD')).toBe(`US$${nbsp}10,50`)
  })

  it('normaliza zero negativo para não exibir sinal', () => {
    expect(formatMoney(-0)).toBe(`R$${nbsp}0,00`)
  })
})

describe('formatSignedMoney', () => {
  it('prefixa sinal pelo sentido', () => {
    expect(formatSignedMoney(4590, 'in')).toBe(`+R$${nbsp}45,90`)
    expect(formatSignedMoney(4590, 'out')).toBe(`-R$${nbsp}45,90`)
  })
})

describe('parseMoneyInput', () => {
  it.each([
    ['1.069,36', 106936],
    ['1069,36', 106936],
    ['1069,3', 106930],
    ['1069', 106900],
    ['R$ 45,90', 4590],
    ['1069.36', 106936],
    ['1.069', 106900],
    ['1.234.567,89', 123456789],
    ['-10,50', -1050],
    [',50', 50],
    ['0,05', 5],
  ])('interpreta %s', (input, expected) => {
    expect(parseMoneyInput(input)).toBe(expected)
  })

  it.each(['', '   ', 'abc', '10,999', '1,2,3', '1.', '12.34.5', '--1', ',', '.', '-', 'R$'])(
    'rejeita %s',
    (input) => {
      expect(parseMoneyInput(input)).toBeNull()
    },
  )

  it('normaliza zero negativo para zero', () => {
    expect(Object.is(parseMoneyInput('-0'), 0)).toBe(true)
    expect(Object.is(parseMoneyInput('-0,00'), 0)).toBe(true)
  })
})

describe('formatCompactMoney', () => {
  it('abrevia milhares e milhões', () => {
    expect(formatCompactMoney(0)).toBe(`R$${nbsp}0`)
    expect(formatCompactMoney(150000)).toBe(`R$${nbsp}1,5${nbsp}mil`)
    expect(formatCompactMoney(150000000)).toBe(`R$${nbsp}1,5${nbsp}mi`)
  })

  it('mantém o sinal de negativo', () => {
    expect(formatCompactMoney(-250000)).toBe(`-R$${nbsp}2,5${nbsp}mil`)
  })
})

describe('centsToInputString', () => {
  it('formata para edição', () => {
    expect(centsToInputString(106936)).toBe('1.069,36')
    expect(centsToInputString(5)).toBe('0,05')
    expect(centsToInputString(-1050)).toBe('-10,50')
  })

  it('normaliza zero negativo para não exibir sinal', () => {
    expect(centsToInputString(-0)).toBe('0,00')
  })
})
