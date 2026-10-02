import type { TransactionFilters } from '@/api/query-keys'
import type { Direction } from '@/api/types'

/** Nomes curtos e em português na URL, para links legíveis e compartilháveis. */
const PARAM_NAMES: Record<keyof TransactionFilters, string> = {
  account_id: 'conta',
  category_id: 'categoria',
  tag_id: 'tag',
  from: 'de',
  to: 'ate',
  direction: 'tipo',
  search: 'busca',
}

const DATE_ONLY = /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/

function positiveInt(value: string | null): number | undefined {
  if (value === null || !/^\d+$/.test(value)) return undefined
  const number = Number(value)
  return number > 0 ? number : undefined
}

export function filtersFromParams(params: URLSearchParams): TransactionFilters {
  const direction = params.get(PARAM_NAMES.direction)
  const from = params.get(PARAM_NAMES.from)
  const to = params.get(PARAM_NAMES.to)
  const search = params.get(PARAM_NAMES.search)?.trim()

  const filters: TransactionFilters = {
    account_id: positiveInt(params.get(PARAM_NAMES.account_id)),
    category_id: positiveInt(params.get(PARAM_NAMES.category_id)),
    tag_id: positiveInt(params.get(PARAM_NAMES.tag_id)),
    from: from && DATE_ONLY.test(from) ? from : undefined,
    to: to && DATE_ONLY.test(to) ? to : undefined,
    direction: direction === 'in' || direction === 'out' ? (direction as Direction) : undefined,
    search: search ? search : undefined,
  }

  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== undefined)) as TransactionFilters
}

export function paramsWithFilter<K extends keyof TransactionFilters>(
  params: URLSearchParams,
  key: K,
  value: TransactionFilters[K] | undefined,
): URLSearchParams {
  const next = new URLSearchParams(params)
  if (value === undefined || value === '') {
    next.delete(PARAM_NAMES[key])
  } else {
    next.set(PARAM_NAMES[key], String(value))
  }
  return next
}

export function activeFilterCount(filters: TransactionFilters): number {
  return Object.entries(filters).filter(([key, value]) => key !== 'search' && value !== undefined).length
}
