import { ArrowDownLeft, ArrowUpRight, LoaderCircle } from 'lucide-react'
import { toast } from 'sonner'
import { useAcceptSuggestion, useDismissSuggestion } from '@/api/queries/transfer-suggestions'
import type { Transaction, TransferSuggestion } from '@/api/types'
import { MoneyText } from '@/components/shared/money-text'
import { Button } from '@/components/ui/button'
import { formatDate } from '@/lib/date'
import { notifyError } from '@/lib/form-errors'
import { cn } from '@/lib/utils'

type SuggestionRowProps = {
  suggestion: TransferSuggestion
}

/** Uma sugestão: as duas pernas (saída e entrada) lado a lado, com "Juntar" e "Não é transferência". */
export function SuggestionRow({ suggestion }: SuggestionRowProps) {
  const accept = useAcceptSuggestion()
  const dismiss = useDismissSuggestion()
  const pending = accept.isPending || dismiss.isPending

  async function handleAccept() {
    try {
      await accept.mutateAsync(suggestion.id)
      toast.success('Transferência ligada.')
    } catch (error) {
      notifyError(error)
    }
  }

  async function handleDismiss() {
    try {
      await dismiss.mutateAsync(suggestion.id)
    } catch (error) {
      notifyError(error)
    }
  }

  // Identifica o par para quem usa leitor de tela: "Juntar"/"Não é transferência" se repetem
  // em toda linha da lista, sem o par descrito ao lado ficariam indistinguíveis fora do
  // contexto visual (ver aria-describedby, que aponta para as duas pernas abaixo).
  const outId = `suggestion-${suggestion.id}-out`
  const inId = `suggestion-${suggestion.id}-in`
  const pairLabel = `${suggestion.out.description} e ${suggestion.in.description}`

  return (
    <li className="space-y-3 px-3 py-3">
      <div className="space-y-2">
        <Leg id={outId} transaction={suggestion.out} />
        <Leg id={inId} transaction={suggestion.in} />
      </div>
      <div className="flex justify-end gap-2">
        <Button
          variant="outline"
          size="sm"
          disabled={pending}
          aria-label={`Não é transferência: ${pairLabel}`}
          aria-describedby={`${outId} ${inId}`}
          onClick={handleDismiss}
        >
          {dismiss.isPending && <LoaderCircle className="h-4 w-4 animate-spin" />}
          Não é transferência
        </Button>
        <Button
          size="sm"
          disabled={pending}
          aria-label={`Juntar: ${pairLabel}`}
          aria-describedby={`${outId} ${inId}`}
          onClick={handleAccept}
        >
          {accept.isPending && <LoaderCircle className="h-4 w-4 animate-spin" />}
          Juntar
        </Button>
      </div>
    </li>
  )
}

function Leg({ id, transaction }: { id: string; transaction: Transaction }) {
  const isOut = transaction.direction === 'out'
  const Icon = isOut ? ArrowUpRight : ArrowDownLeft

  return (
    <div id={id} className="flex items-center gap-3">
      <span
        aria-hidden
        className={cn(
          'flex h-9 w-9 shrink-0 items-center justify-center rounded-full',
          isOut ? 'bg-expense/10 text-expense' : 'bg-income/10 text-income',
        )}
      >
        <Icon className="h-4 w-4" />
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium">{transaction.description}</p>
        <p className="truncate text-xs text-muted-foreground">
          {transaction.account?.name ?? 'Conta'} · {formatDate(transaction.date)}
        </p>
      </div>
      <MoneyText
        cents={transaction.amount}
        currency={transaction.currency}
        direction={transaction.direction}
        className="shrink-0 font-semibold"
      />
    </div>
  )
}
