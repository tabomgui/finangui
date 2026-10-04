import { ArrowLeftRight, Hourglass } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useOverdueOccurrences } from '@/api/queries/recurrences'
import { useTransferSuggestions } from '@/api/queries/transfer-suggestions'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { firstPageSuggestionsCount, suggestionsCountLabel } from '../transfers/suggestions-count'

/** "1 lançamento previsto não confirmado" / "N lançamentos previstos não confirmados". */
function overdueOccurrencesLabel(count: number): string {
  return count === 1 ? '1 lançamento previsto não confirmado' : `${count} lançamentos previstos não confirmados`
}

type PendingCardProps = {
  /**
   * Abre `OverdueOccurrencesDialog`, que o `DashboardPage` possui (fora deste card): resolver a
   * última pendência zera `overdueCount`, e este card pode desmontar (ex.: sem sugestões também)
   * — o diálogo não pode ir junto enquanto ainda está em uso.
   */
  onOpenOverdue: () => void
}

/**
 * "Pendências" no Início: sugestões de transferência e previstas de recorrência já atrasadas e
 * ainda não confirmadas (conexão a reconectar já aparece no `ReauthBanner`). Some quando não há
 * nenhuma das duas.
 */
export function PendingCard({ onOpenOverdue }: PendingCardProps) {
  const { data } = useTransferSuggestions()
  const { count, hasMore } = firstPageSuggestionsCount(data?.pages[0])
  const { data: overdue } = useOverdueOccurrences()
  const overdueCount = overdue?.length ?? 0

  if (count === 0 && overdueCount === 0) return null

  return (
    <Card className="rounded-2xl shadow-card">
      <CardHeader>
        <CardTitle className="text-base">
          <h2>Pendências</h2>
        </CardTitle>
      </CardHeader>
      <CardContent className="p-2 pt-0">
        {count > 0 && (
          <Link to="/transferencias/sugestoes" className="flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-muted/50">
            <span aria-hidden className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
              <ArrowLeftRight className="h-5 w-5" />
            </span>
            <span className="flex-1 text-sm font-medium">{suggestionsCountLabel({ count, hasMore })}</span>
          </Link>
        )}
        {overdueCount > 0 && (
          <button
            type="button"
            onClick={onOpenOverdue}
            className="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left hover:bg-muted/50"
          >
            <span aria-hidden className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
              <Hourglass className="h-5 w-5" />
            </span>
            <span className="flex-1 text-sm font-medium">{overdueOccurrencesLabel(overdueCount)}</span>
          </button>
        )}
      </CardContent>
    </Card>
  )
}
