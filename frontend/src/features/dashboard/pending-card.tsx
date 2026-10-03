import { ArrowLeftRight } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useTransferSuggestions } from '@/api/queries/transfer-suggestions'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'

/**
 * "Pendências" no Início: por ora, só a contagem de sugestões de transferência (recorrências não
 * confirmadas e conexões a reconectar entram aqui depois; reconexão já aparece no `ReauthBanner`).
 * Sem endpoint de contagem: usa a primeira página de `/transfer-suggestions` (mesma, PAGE_SIZE já
 * pequeno o bastante para um card do Início) e mostra "N+" quando há mais que essa página.
 */
export function PendingCard() {
  const { data } = useTransferSuggestions()
  const firstPage = data?.pages[0]
  const count = firstPage?.data.length ?? 0
  if (count === 0) return null

  const hasMore = firstPage?.meta.next_cursor != null
  const label =
    count === 1 && !hasMore ? '1 sugestão de transferência' : `${count}${hasMore ? '+' : ''} sugestões de transferência`

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
          <span className="flex-1 text-sm font-medium">{label}</span>
        </Link>
      </CardContent>
    </Card>
  )
}
