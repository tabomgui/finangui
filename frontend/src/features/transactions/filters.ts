import type { TransactionFilters } from '@/api/query-keys'
import type { Direction } from '@/api/types'
import { today } from '@/lib/date'

/**
 * Nomes curtos e em português na URL, para links legíveis e compartilháveis.
 * `statement_id` não é filtro de URL: é passado direto pela tela de fatura, não pela lista.
 */
const PARAM_NAMES: Record<keyof Omit<TransactionFilters, 'statement_id'>, string> = {
  account_id: 'conta',
  category_id: 'categoria',
  category_exact: 'exata',
  no_category: 'sem_categoria',
  tag_id: 'tag',
  from: 'de',
  to: 'ate',
  direction: 'tipo',
  currency: 'moeda',
  reportable: 'relatorio',
  search: 'busca',
}

/**
 * `category_exact` é um modificador do filtro de categoria (não um filtro à parte): fica fora da
 * contagem exibida no botão "Filtros" para não inflar o número por algo que não é uma escolha
 * independente. `reportable`/`currency` já aparecem como uma linha própria e removível no painel
 * (ver `reportableFilterLabel`), então contam normalmente.
 */
const EXCLUDED_FROM_COUNT = new Set(['search', 'category_exact'])

const DATE_ONLY = /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/
const CURRENCY_CODE = /^[A-Z]{3}$/

function positiveInt(value: string | null): number | undefined {
  if (value === null || !/^\d+$/.test(value)) return undefined
  const number = Number(value)
  return number > 0 ? number : undefined
}

function boolFlag(value: string | null): boolean | undefined {
  return value === '1' ? true : undefined
}

export function filtersFromParams(params: URLSearchParams): TransactionFilters {
  const direction = params.get(PARAM_NAMES.direction)
  const from = params.get(PARAM_NAMES.from)
  const to = params.get(PARAM_NAMES.to)
  const search = params.get(PARAM_NAMES.search)?.trim()
  const currency = params.get(PARAM_NAMES.currency)?.trim()

  const noCategory = boolFlag(params.get(PARAM_NAMES.no_category))
  // `no_category` e `category_id` filtram categoria de formas incompatíveis (mesma regra do
  // backend, `IndexTransactionsRequest`): um valor malformado/conflitante na URL não chega a
  // `category_id` nenhum — `sem_categoria=1` sempre vence. `category_exact` só faz sentido junto
  // de `category_id`; sem ele (direto, ou porque `sem_categoria` acabou de zerá-lo), também cai.
  const categoryId = noCategory ? undefined : positiveInt(params.get(PARAM_NAMES.category_id))
  const categoryExact = categoryId ? boolFlag(params.get(PARAM_NAMES.category_exact)) : undefined

  const filters: TransactionFilters = {
    account_id: positiveInt(params.get(PARAM_NAMES.account_id)),
    category_id: categoryId,
    category_exact: categoryExact,
    no_category: noCategory,
    tag_id: positiveInt(params.get(PARAM_NAMES.tag_id)),
    from: from && DATE_ONLY.test(from) ? from : undefined,
    to: to && DATE_ONLY.test(to) ? to : undefined,
    direction: direction === 'in' || direction === 'out' ? (direction as Direction) : undefined,
    currency: currency && CURRENCY_CODE.test(currency) ? currency : undefined,
    reportable: boolFlag(params.get(PARAM_NAMES.reportable)),
    search: search ? search : undefined,
  }

  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== undefined)) as TransactionFilters
}

/**
 * Texto da linha removível de `relatorio`/`moeda` no painel de filtros (ver `transaction-filters.tsx`):
 * `null` quando nenhum dos dois está ativo, para o painel não mostrar a linha à toa.
 */
export function reportableFilterLabel(filters: TransactionFilters): string | null {
  if (!filters.reportable && !filters.currency) return null
  const parts: string[] = []
  if (filters.reportable) parts.push('Só lançamentos que entram em relatórios')
  if (filters.currency) parts.push(`(${filters.currency})`)
  return parts.join(' ')
}

export function paramsWithFilter<K extends keyof Omit<TransactionFilters, 'statement_id'>>(
  params: URLSearchParams,
  key: K,
  value: TransactionFilters[K] | undefined,
): URLSearchParams {
  const next = new URLSearchParams(params)
  if (value === undefined || value === '' || value === false) {
    next.delete(PARAM_NAMES[key])
  } else {
    next.set(PARAM_NAMES[key], value === true ? '1' : String(value))
  }
  return next
}

/** Mesmos nomes curtos de `filtersFromParams`, na direção contrária: usado para montar o link "Ver todos" do card de distribuição de gastos. */
export function paramsForFilters(filters: TransactionFilters): URLSearchParams {
  return (Object.keys(PARAM_NAMES) as (keyof typeof PARAM_NAMES)[]).reduce(
    (params, key) => paramsWithFilter(params, key, filters[key]),
    new URLSearchParams(),
  )
}

export function activeFilterCount(filters: TransactionFilters): number {
  return Object.entries(filters).filter(([key, value]) => !EXCLUDED_FROM_COUNT.has(key) && value !== undefined).length
}

const FUTURE_PARAM = 'futuros'

/** `futuros=1` tira o limite implícito de hoje da lista de transações (ver effectiveTransactionFilters). */
export function showFutureFromParams(params: URLSearchParams): boolean {
  return params.get(FUTURE_PARAM) === '1'
}

export function paramsWithFuture(params: URLSearchParams, show: boolean): URLSearchParams {
  const next = new URLSearchParams(params)
  if (show) next.set(FUTURE_PARAM, '1')
  else next.delete(FUTURE_PARAM)
  return next
}

/**
 * Por padrão, a lista de transações não mostra parcelas projetadas futuras:
 * limita implicitamente a hoje. Um "até" explícito sempre vence; o switch
 * "Mostrar lançamentos futuros" tira esse limite implícito.
 */
export function effectiveTransactionFilters(filters: TransactionFilters, showFuture: boolean): TransactionFilters {
  if (showFuture || filters.to !== undefined) return filters
  return { ...filters, to: today() }
}
