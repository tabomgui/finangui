import { ArrowLeftRight, LoaderCircle, Search, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'
import { useDetectTransfers, useTransferSuggestions } from '@/api/queries/transfer-suggestions'
import { PageBody } from '@/components/layout/page-body'
import { headerButton, PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { LoadMore } from '@/components/shared/load-more'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { notifyError } from '@/lib/form-errors'
import { cn } from '@/lib/utils'
import { SuggestionRow } from './suggestion-row'

function detectResultLabel(linked: number, suggested: number): string {
  const linkedLabel = `${linked} ${linked === 1 ? 'ligada' : 'ligadas'}`
  const suggestedLabel = `${suggested} ${suggested === 1 ? 'sugestão' : 'sugestões'}`
  return `${linkedLabel}, ${suggestedLabel}`
}

export function TransferSuggestionsPage() {
  const query = useTransferSuggestions()
  const detect = useDetectTransfers()
  const suggestions = query.data?.pages.flatMap((page) => page.data) ?? []

  async function handleDetect() {
    try {
      const result = await detect.mutateAsync()
      toast.success(detectResultLabel(result.linked, result.suggested))
    } catch (error) {
      notifyError(error)
    }
  }

  return (
    <>
      <PageHeader
        title="Transferências encontradas"
        back
        actions={
          <Button className={headerButton} disabled={detect.isPending} onClick={handleDetect}>
            {detect.isPending ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Search className="h-4 w-4" />}
            Procurar agora
          </Button>
        }
      />
      <PageBody>
        {query.isError ? (
          <Card className="rounded-2xl p-0 shadow-card">
            <EmptyState
              icon={TriangleAlert}
              title="Não foi possível carregar as sugestões."
              action={
                <Button variant="outline" onClick={() => query.refetch()}>
                  Tentar de novo
                </Button>
              }
            />
          </Card>
        ) : query.isPending ? (
          <div className="space-y-2">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-28 w-full rounded-2xl" />
            ))}
          </div>
        ) : suggestions.length === 0 ? (
          <Card className="rounded-2xl p-0 shadow-card">
            <EmptyState icon={ArrowLeftRight} title="Nenhuma sugestão pendente." />
          </Card>
        ) : (
          <Card
            aria-busy={query.isPlaceholderData}
            className={cn('gap-0 overflow-clip rounded-2xl p-0 shadow-card transition-opacity', query.isPlaceholderData && 'opacity-60')}
          >
            <ul className="divide-y divide-border">
              {suggestions.map((suggestion) => (
                <SuggestionRow key={suggestion.id} suggestion={suggestion} />
              ))}
            </ul>
          </Card>
        )}
        <LoadMore
          hasMore={Boolean(query.hasNextPage) && !query.isPlaceholderData}
          loading={query.isFetchingNextPage}
          onLoadMore={() => query.fetchNextPage()}
        />
      </PageBody>
    </>
  )
}
