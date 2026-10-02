const MONTH = /^\d{4}-(0[1-9]|1[0-2])$/

export type TopCategory = {
  category_id: number | null
  name: string
  icon: string | null
  color: string | null
  amount: number
}

export type CategoryShare = TopCategory & { barPercent: number; expensePercent: number }

/** Proporções só para desenhar as barras; os valores vêm prontos da API. */
export function categoryShares(categories: TopCategory[], expense: number): CategoryShare[] {
  const max = Math.max(0, ...categories.map((category) => category.amount))
  return categories.map((category) => ({
    ...category,
    barPercent: max > 0 ? Math.round((category.amount / max) * 100) : 0,
    expensePercent: expense > 0 ? Math.round((category.amount / expense) * 100) : 0,
  }))
}

export function monthFromParam(value: string | null, fallback: string): string {
  return value && MONTH.test(value) ? value : fallback
}
