// Dinheiro sempre em centavos inteiros (como a API). Nunca usar float para valores.

import type { Direction } from '@/api/types'

const currencyFormatters = new Map<string, Intl.NumberFormat>()

function currencyFormatter(currency: string): Intl.NumberFormat {
  let formatter = currencyFormatters.get(currency)
  if (!formatter) {
    formatter = new Intl.NumberFormat('pt-BR', { style: 'currency', currency })
    currencyFormatters.set(currency, formatter)
  }
  return formatter
}

const decimalFormatter = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const compactCurrencyFormatters = new Map<string, Intl.NumberFormat>()

function compactCurrencyFormatter(currency: string): Intl.NumberFormat {
  let formatter = compactCurrencyFormatters.get(currency)
  if (!formatter) {
    formatter = new Intl.NumberFormat('pt-BR', {
      style: 'currency',
      currency,
      notation: 'compact',
      compactDisplay: 'short',
      minimumFractionDigits: 0,
      maximumFractionDigits: 1,
    })
    compactCurrencyFormatters.set(currency, formatter)
  }
  return formatter
}

/** Versão abreviada ("R$ 1,5 mil", "R$ 1,2 mi") para espaços estreitos, como eixos de gráfico. */
export function formatCompactMoney(cents: number, currency = 'BRL'): string {
  const normalized = cents === 0 ? 0 : cents
  return compactCurrencyFormatter(currency).format(normalized / 100)
}

export function formatMoney(cents: number, currency = 'BRL'): string {
  const normalized = cents === 0 ? 0 : cents
  return currencyFormatter(currency).format(normalized / 100)
}

export function formatSignedMoney(cents: number, direction: Direction, currency = 'BRL'): string {
  return `${direction === 'in' ? '+' : '-'}${formatMoney(Math.abs(cents), currency)}`
}

export function centsToInputString(cents: number): string {
  const normalized = cents === 0 ? 0 : cents
  return decimalFormatter.format(normalized / 100)
}

const GROUPED_INTEGER = /^\d{1,3}(\.\d{3})*$/
const PLAIN_INTEGER = /^\d+$/

function normalizeInteger(part: string): string | null {
  if (part === '') return '0'
  if (PLAIN_INTEGER.test(part)) return part
  if (GROUPED_INTEGER.test(part)) return part.replace(/\./g, '')
  return null
}

/**
 * Converte o que o usuário digitou em centavos. Aceita formato brasileiro ("1.069,36"),
 * ponto como decimal quando há até 2 casas ("1069.36") e prefixo "R$". Retorna null se inválido.
 */
export function parseMoneyInput(raw: string): number | null {
  let value = raw.replace(/R\$/gi, '').replace(/[\s ]/g, '')
  if (value === '') return null

  let negative = false
  if (value.startsWith('-')) {
    negative = true
    value = value.slice(1)
  }
  if (!/^[\d.,]+$/.test(value)) return null
  if (!/\d/.test(value)) return null

  let integerPart: string | null
  let decimalPart = ''

  if (value.includes(',')) {
    const parts = value.split(',')
    if (parts.length !== 2) return null
    integerPart = normalizeInteger(parts[0])
    decimalPart = parts[1]
  } else {
    const dotParts = value.split('.')
    const lastPart = dotParts[dotParts.length - 1]
    if (dotParts.length === 2 && lastPart.length >= 1 && lastPart.length <= 2) {
      integerPart = normalizeInteger(dotParts[0])
      decimalPart = lastPart
    } else {
      integerPart = normalizeInteger(value)
    }
  }

  if (integerPart === null || !/^\d{0,2}$/.test(decimalPart)) return null

  const cents = Number(integerPart) * 100 + Number(decimalPart.padEnd(2, '0'))
  if (!Number.isSafeInteger(cents)) return null

  return negative && cents !== 0 ? -cents : cents
}
