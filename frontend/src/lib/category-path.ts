import type { Category } from '@/api/types'

/**
 * "Pai › Sub" quando a categoria tem pai e ele está no mapa carregado; senão só o nome. Usada
 * onde o contexto do pai importa (sugestão pelo histórico, troca de categoria por regra),
 * diferente do resto do app, que mostra só o nome (ex.: `CategoryPicker`).
 */
export function categoryPath(category: Category, categoriesById: ReadonlyMap<number, Category>): string {
  if (category.parent_id === null) return category.name
  const parent = categoriesById.get(category.parent_id)
  return parent ? `${parent.name} › ${category.name}` : category.name
}

/**
 * Rótulo de uma categoria referida só pelo id (ex.: `current_category_id`/`new_category_id` de
 * uma troca de categoria): "sem categoria" sem id, o caminho quando ela está carregada, "…"
 * enquanto a lista carrega e "categoria excluída" quando não existe mais.
 */
export function categoryPathById(
  id: number | null | undefined,
  categoriesById: ReadonlyMap<number, Category>,
  loading: boolean,
): string {
  if (id === null || id === undefined) return 'sem categoria'
  const category = categoriesById.get(id)
  if (category) return categoryPath(category, categoriesById)
  return loading ? '…' : 'categoria excluída'
}
