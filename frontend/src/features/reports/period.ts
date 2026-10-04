import { format } from 'date-fns'
import { ptBR } from 'date-fns/locale'
import { parseDateOnly, shiftMonth } from '@/lib/date'

export type EvolutionRangeKey = 'last6' | 'last12' | 'year'

export const EVOLUTION_RANGE_OPTIONS: { value: EvolutionRangeKey; label: string }[] = [
  { value: 'last6', label: 'Últimos 6 meses' },
  { value: 'last12', label: 'Últimos 12 meses' },
  { value: 'year', label: 'Ano atual' },
]

/** Intervalo `from`/`to` ("YYYY-MM") para a evolução mensal, a partir do mês atual. */
export function evolutionRange(key: EvolutionRangeKey, currentMonth: string): { from: string; to: string } {
  if (key === 'last6') return { from: shiftMonth(currentMonth, -5), to: currentMonth }
  if (key === 'last12') return { from: shiftMonth(currentMonth, -11), to: currentMonth }
  return { from: `${currentMonth.slice(0, 4)}-01`, to: currentMonth }
}

/** Rótulo curto do eixo X ("out/26"), só para o gráfico — o nome completo aparece no tooltip. */
export function monthAxisLabel(month: string): string {
  return format(parseDateOnly(`${month}-01`), 'MMM/yy', { locale: ptBR })
}
