import { arrayMove } from '@dnd-kit/sortable'

/**
 * Separado de `rule-labels.ts` de propósito: só a lista arrastável (`rules-page.tsx`) precisa de
 * `@dnd-kit`. `rule-labels.ts` é importado também pelo editor (`rule-editor-page.tsx`), que não
 * arrasta nada — se `arrayMove` ficasse ali, o chunk lazy do editor carregaria `@dnd-kit` à toa.
 */

/** Aplica o `DragEndEvent` do dnd-kit (ids de `active`/`over`) a uma lista de ids ordenada. */
export function reorderIds(ids: number[], activeId: number, overId: number): number[] {
  const from = ids.indexOf(activeId)
  const to = ids.indexOf(overId)
  if (from === -1 || to === -1) return ids
  return arrayMove(ids, from, to)
}
