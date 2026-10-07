// Paleta de fallback para categorias sem `color` própria. Tons "600" (mais escuros que os
// tradicionais tons vivos de dashboard) escolhidos para ler em cima tanto do card claro quanto do
// escuro sem precisar trocar de cor por tema: todos têm razão de contraste >= 3:1 contra o branco
// do card claro (`--card` em `index.css`) e >= 3:1 contra o quase-preto do card escuro (ver
// `spending-colors.test.ts`, que confere isso com a fórmula de contraste do WCAG).
const FALLBACK_PALETTE = [
  '#dc2626', // red-600
  '#ea580c', // orange-600
  '#d97706', // amber-600
  '#16a34a', // green-600
  '#0d9488', // teal-600
  '#2563eb', // blue-600
  '#9333ea', // purple-600
  '#db2777', // pink-600
]

/** `categoryId` nulo (grupo "Sem categoria") cai sempre na mesma cor reservada, fora da paleta de fallback. */
const NO_CATEGORY_COLOR = '#6b7280' // gray-500 (mesmo critério de contraste da paleta acima)

type ColorableEntry = { key: string; categoryId: number | null; color: string | null }

/**
 * Resolve a cor de exibição de cada linha de uma mesma tela (nível de topo, ou o detalhamento de
 * uma categoria): cor própria quando houver; senão, a próxima cor da paleta ainda não usada por
 * outra categoria *explícita* desta tela, na ordem em que as linhas chegam (ordem de valor desc,
 * que já vem da API) — evita uma categoria sem cor cair por acaso na mesma cor de uma vizinha que
 * já tem cor própria. Puramente função da lista recebida: a mesma tela sempre produz a mesma
 * atribuição (estável), mas duas telas diferentes podem atribuir cores diferentes ao mesmo id.
 */
export function assignEntryColors(entries: ColorableEntry[]): Map<string, string> {
  const explicit = new Set(entries.map((entry) => entry.color).filter((color): color is string => color !== null))
  const assigned = new Map<string, string>()
  let cursor = 0

  for (const entry of entries) {
    if (entry.color) {
      assigned.set(entry.key, entry.color)
      continue
    }
    if (entry.categoryId === null) {
      assigned.set(entry.key, NO_CATEGORY_COLOR)
      continue
    }

    let color = FALLBACK_PALETTE[cursor % FALLBACK_PALETTE.length]
    for (let skipped = 0; explicit.has(color) && skipped < FALLBACK_PALETTE.length; skipped++) {
      cursor += 1
      color = FALLBACK_PALETTE[cursor % FALLBACK_PALETTE.length]
    }
    assigned.set(entry.key, color)
    cursor += 1
  }

  return assigned
}
