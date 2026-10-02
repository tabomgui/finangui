export const COLOR_PALETTE = [
  { hex: '#10b981', name: 'Esmeralda' },
  { hex: '#0ea5e9', name: 'Azul' },
  { hex: '#6366f1', name: 'Índigo' },
  { hex: '#8b5cf6', name: 'Violeta' },
  { hex: '#ec4899', name: 'Rosa' },
  { hex: '#ef4444', name: 'Vermelho' },
  { hex: '#f97316', name: 'Laranja' },
  { hex: '#eab308', name: 'Amarelo' },
  { hex: '#84cc16', name: 'Lima' },
  { hex: '#14b8a6', name: 'Turquesa' },
  { hex: '#64748b', name: 'Ardósia' },
  { hex: '#a16207', name: 'Âmbar' },
] as const

export const DEFAULT_COLOR = COLOR_PALETTE[0].hex

function toLinearChannel(value: number): number {
  const c = value / 255
  return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
}

/** Luminância relativa (WCAG) do hex; acima de 0,45 é considerado claro. */
export function isLightColor(hex: string): boolean {
  const r = Number.parseInt(hex.slice(1, 3), 16)
  const g = Number.parseInt(hex.slice(3, 5), 16)
  const b = Number.parseInt(hex.slice(5, 7), 16)

  const luminance = 0.2126 * toLinearChannel(r) + 0.7152 * toLinearChannel(g) + 0.0722 * toLinearChannel(b)

  return luminance > 0.45
}
