import { ArrowLeftRight } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useTransferSuggestions } from '@/api/queries/transfer-suggestions'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { firstPageSuggestionsCount, suggestionsCountLabel } from '../transfers/suggestions-count'

/**
 * "Pendências" no Início: por ora, só a contagem de sugestões de transferência (recorrências não
 * confirmadas e conexões a reconectar entram aqui depois; reconexão já aparece no `ReauthBanner`).
 */
export function PendingCard() {
  const { data } = useTransferSuggestions()
  const { count, hasMore } = firstPageSuggestionsCount(data?.pages[0])
  if (count === 0) return null

  return (
    <Card className="rounded-2xl shadow-card">
      <CardHeader>
        <CardTitle className="text-base">
          <h2>Pendências</h2>
        </CardTitle>
      </CardHeader>
      <CardContent className="p-2 pt-0">
        <Link to="/transferencias/sugestoes" className="flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-muted/50">
          <span aria-hidden className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
            <ArrowLeftRight className="h-5 w-5" />
          </span>
          <span className="flex-1 text-sm font-medium">{suggestionsCountLabel({ count, hasMore })}</span>
        </Link>
      </CardContent>
    </Card>
  )
}
