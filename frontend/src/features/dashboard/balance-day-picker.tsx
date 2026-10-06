import { ptBR } from 'react-day-picker/locale'
import { Button } from '@/components/ui/button'
import { Calendar } from '@/components/ui/calendar'
import { appToday, parseDateOnly, toDateOnly } from '@/lib/date'

type BalanceDayPickerProps = {
  selected: string
  onSelect: (day: string) => void
  onBackToToday: () => void
}

/**
 * Conteúdo do popover do `BalanceHero`, carregado sob demanda (`react-day-picker` só entra no
 * bundle quando o usuário abre o calendário). `selected` é o `balance_date` que já veio da API:
 * este componente não decide qual é o dia "certo", só deixa escolher outro.
 */
export function BalanceDayPicker({ selected, onSelect, onBackToToday }: BalanceDayPickerProps) {
  const todayValue = appToday()
  const todayDate = parseDateOnly(todayValue)
  const selectedDate = parseDateOnly(selected)

  return (
    <div>
      <Calendar
        mode="single"
        locale={ptBR}
        autoFocus
        selected={selectedDate}
        defaultMonth={selectedDate}
        today={todayDate}
        endMonth={todayDate}
        disabled={(day) => day > todayDate}
        onSelect={(day) => {
          if (day) onSelect(toDateOnly(day))
        }}
      />
      {selected !== todayValue && (
        <div className="border-t p-3 pt-2">
          <Button type="button" variant="ghost" size="sm" className="w-full" onClick={onBackToToday}>
            Voltar para hoje
          </Button>
        </div>
      )}
    </div>
  )
}
