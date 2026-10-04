const MINUTE = 60_000
const HOUR = 60 * MINUTE
const DAY = 24 * HOUR
const MONTH = 30 * DAY
const YEAR = 365 * DAY

const formatter = new Intl.RelativeTimeFormat('pt-BR', { numeric: 'auto' })

/**
 * "há 5 minutos" / "há 2 horas" / "ontem" / "há 3 dias", a partir de `created_at` (timestamp ISO).
 * `Intl.RelativeTimeFormat` evita carregar `date-fns` só por isto (o sino precisa ficar leve: ver
 * `notification-panel.tsx`). `now` é injetável para teste determinístico; em produção é sempre o
 * momento da renderização.
 */
export function formatRelativeTime(value: string, now: Date = new Date()): string {
  const diffMs = new Date(value).getTime() - now.getTime()
  const abs = Math.abs(diffMs)

  if (abs < MINUTE) return formatter.format(Math.round(diffMs / 1000), 'second')
  if (abs < HOUR) return formatter.format(Math.round(diffMs / MINUTE), 'minute')
  if (abs < DAY) return formatter.format(Math.round(diffMs / HOUR), 'hour')
  if (abs < MONTH) return formatter.format(Math.round(diffMs / DAY), 'day')
  if (abs < YEAR) return formatter.format(Math.round(diffMs / MONTH), 'month')
  return formatter.format(Math.round(diffMs / YEAR), 'year')
}
