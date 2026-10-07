import { describe, expect, it } from 'vitest'
import { assignEntryColors } from './spending-colors'

// Mesma fórmula de contraste do WCAG 2.x, só para o teste de paleta abaixo.
function srgbToLinear(channel: number): number {
  const value = channel / 255
  return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
}

function relativeLuminance(hex: string): number {
  const r = parseInt(hex.slice(1, 3), 16)
  const g = parseInt(hex.slice(3, 5), 16)
  const b = parseInt(hex.slice(5, 7), 16)
  return 0.2126 * srgbToLinear(r) + 0.7152 * srgbToLinear(g) + 0.0722 * srgbToLinear(b)
}

function contrastRatio(hexA: string, hexB: string): number {
  const a = relativeLuminance(hexA)
  const b = relativeLuminance(hexB)
  const lighter = Math.max(a, b)
  const darker = Math.min(a, b)
  return (lighter + 0.05) / (darker + 0.05)
}

// `--card` claro (branco) e escuro (`hsl(0 0% 12%)`, ver `index.css`) — as cores de fallback
// nunca mudam por tema, então precisam ler em cima dos dois.
const LIGHT_CARD = '#ffffff'
const DARK_CARD = '#1f1f1f'
const MIN_CONTRAST = 3

describe('assignEntryColors: paleta de fallback', () => {
  it('toda cor atribuída sem categoria própria lê >= 3:1 no card claro e no escuro', () => {
    // Força a atribuição das 8 cores da paleta (sem nenhum `color` explícito para "usar") mais a
    // reservada de "Sem categoria", uma por entrada.
    const entries = [
      ...Array.from({ length: 8 }, (_, i) => ({ key: `cat-${i}`, categoryId: i + 1, color: null })),
      { key: 'none', categoryId: null, color: null },
    ]

    const colors = assignEntryColors(entries)

    for (const [key, color] of colors) {
      expect(contrastRatio(color, LIGHT_CARD)).toBeGreaterThanOrEqual(MIN_CONTRAST)
      expect(contrastRatio(color, DARK_CARD)).toBeGreaterThanOrEqual(MIN_CONTRAST)
      // `key` só para a mensagem de falha apontar qual entrada, se alguma cor não passar.
      expect(key).toBeTruthy()
    }
  })
})

describe('assignEntryColors', () => {
  it('usa a cor própria quando houver', () => {
    const colors = assignEntryColors([{ key: '1', categoryId: 1, color: '#ff0000' }])
    expect(colors.get('1')).toBe('#ff0000')
  })

  it('"Sem categoria" (id nulo) sempre cai na mesma cor reservada', () => {
    const colors = assignEntryColors([{ key: 'none', categoryId: null, color: null }])
    const other = assignEntryColors([
      { key: 'a', categoryId: 1, color: null },
      { key: 'none', categoryId: null, color: null },
    ])
    expect(colors.get('none')).toBe(other.get('none'))
  })

  it('é estável: a mesma lista de entradas sempre produz a mesma atribuição', () => {
    const entries = [
      { key: 'a', categoryId: 1, color: null },
      { key: 'b', categoryId: 2, color: '#112233' },
      { key: 'c', categoryId: 3, color: null },
    ]
    expect(assignEntryColors(entries)).toEqual(assignEntryColors(entries.map((e) => ({ ...e }))))
  })

  it('pula uma cor da paleta já usada explicitamente por outra categoria da mesma tela', () => {
    // A primeira cor da paleta ('#dc2626') já está em uso por uma categoria com cor própria;
    // a categoria sem cor não deve repeti-la.
    const colors = assignEntryColors([
      { key: 'explicit', categoryId: 1, color: '#dc2626' },
      { key: 'fallback', categoryId: 2, color: null },
    ])
    expect(colors.get('fallback')).not.toBe('#dc2626')
  })

  it('duas categorias sem cor na mesma tela recebem cores de fallback diferentes', () => {
    const colors = assignEntryColors([
      { key: 'a', categoryId: 1, color: null },
      { key: 'b', categoryId: 2, color: null },
    ])
    expect(colors.get('a')).not.toBe(colors.get('b'))
  })

  it('a mesma categoria pode receber cores de fallback diferentes em telas diferentes (rank, não id)', () => {
    const asFirst = assignEntryColors([{ key: '5', categoryId: 5, color: null }])
    const asSecond = assignEntryColors([
      { key: '9', categoryId: 9, color: null },
      { key: '5', categoryId: 5, color: null },
    ])
    expect(asFirst.get('5')).not.toBe(asSecond.get('5'))
  })
})
