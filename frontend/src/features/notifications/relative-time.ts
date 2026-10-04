import { formatDistanceStrict, parseISO } from 'date-fns'
import { ptBR } from 'date-fns/locale'

/**
 * "há 5 minutos" / "há 2 horas" / "há 3 dias", a partir de `created_at` (timestamp ISO). `now` é
 * injetável para teste determinístico; em produção é sempre o momento da renderização.
 */
export function formatRelativeTime(value: string, now: Date = new Date()): string {
  return formatDistanceStrict(parseISO(value), now, { locale: ptBR, addSuffix: true, roundingMethod: 'floor' })
}
