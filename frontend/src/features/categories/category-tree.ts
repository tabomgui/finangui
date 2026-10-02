import type { Category, CategoryKind } from '@/api/types'

export type CategoryNode = { category: Category; children: Category[] }
export type CategoryOption = { category: Category; depth: 0 | 1 }

const byName = (a: Category, b: Category) => a.name.localeCompare(b.name, 'pt-BR')

/** Árvore de um nível (pai → filhas) de um tipo, ordenada por nome. Inclui arquivadas. */
export function buildCategoryTree(categories: Category[], kind: CategoryKind): CategoryNode[] {
  const ofKind = categories.filter((category) => category.kind === kind)
  const childrenByParent = new Map<number, Category[]>()
  for (const category of ofKind) {
    if (category.parent_id !== null) {
      childrenByParent.set(category.parent_id, [...(childrenByParent.get(category.parent_id) ?? []), category])
    }
  }

  return ofKind
    .filter((category) => category.parent_id === null)
    .sort(byName)
    .map((category) => ({ category, children: (childrenByParent.get(category.id) ?? []).sort(byName) }))
}

/**
 * Opções para seletores: pais seguidos das filhas, sem arquivadas (exceto `keepId`, para não
 * sumir a categoria já escolhida num lançamento antigo). Sem `kind`, junta despesas e receitas.
 */
export function flattenCategoryOptions(
  categories: Category[],
  { kind, keepId }: { kind?: CategoryKind; keepId?: number | null },
): CategoryOption[] {
  const visible = categories.filter((category) => !category.is_archived || category.id === keepId)
  const kinds: CategoryKind[] = kind ? [kind] : ['expense', 'income']

  return kinds.flatMap((each) =>
    buildCategoryTree(visible, each).flatMap(({ category, children }) => [
      { category, depth: 0 as const },
      ...children.map((child) => ({ category: child, depth: 1 as const })),
    ]),
  )
}
