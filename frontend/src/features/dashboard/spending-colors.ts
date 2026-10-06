// Paleta de fallback para categorias sem `color` própria: tons vivos o bastante para ler tanto no
// fundo claro quanto no escuro do card (mesmo princípio de `CategoryIcon`, que também só aceita
// hex). Determinística por id — a mesma categoria cai sempre na mesma cor; ids diferentes podem
// colidir na mesma cor quando passam de 8 (paleta curta de propósito, para as cores ficarem
// distinguíveis entre si no gráfico e na legenda).
const FALLBACK_PALETTE = [
  '#f97316', // orange-500
  '#0ea5e9', // sky-500
  '#a855f7', // purple-500
  '#22c55e', // green-500
  '#ec4899', // pink-500
  '#eab308', // yellow-500
  '#14b8a6', // teal-500
  '#6366f1', // indigo-500
]

/** `categoryId` nulo (grupo "Sem categoria") cai sempre na mesma cor reservada, fora da rotação por id. */
const NO_CATEGORY_COLOR = '#6b7280' // gray-500

export function fallbackCategoryColor(categoryId: number | null): string {
  if (categoryId === null) return NO_CATEGORY_COLOR
  const index = ((categoryId % FALLBACK_PALETTE.length) + FALLBACK_PALETTE.length) % FALLBACK_PALETTE.length
  return FALLBACK_PALETTE[index]
}

/** Cor própria da categoria quando houver; senão, a cor de fallback determinística por id. */
export function entryColor(categoryId: number | null, color: string | null): string {
  return color ?? fallbackCategoryColor(categoryId)
}
