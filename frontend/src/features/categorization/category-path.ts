import type { Category } from '@/api/types'

/**
 * "Pai › Sub" quando a categoria tem pai e ele está no mapa carregado; senão só o nome. Usada
 * onde o contexto do pai importa (sugestão pelo histórico), diferente do resto do app, que
 * mostra só o nome (ex.: `CategoryPicker`, `rule-preview-card.tsx`).
 */
export function categoryPath(category: Category, categoriesById: ReadonlyMap<number, Category>): string {
  if (category.parent_id === null) return category.name
  const parent = categoriesById.get(category.parent_id)
  return parent ? `${parent.name} › ${category.name}` : category.name
}
