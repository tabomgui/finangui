import { LoaderCircle, Tags, X } from 'lucide-react'
import { toast } from 'sonner'
import { useBulkUpdateTransactions } from '@/api/queries/transactions'
import { useTags } from '@/api/queries/tags'
import type { components } from '@/api/schema'
import type { Transaction } from '@/api/types'
import { CategoryPicker } from '@/components/shared/category-picker'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { mergeTagIds, selectionKind, transacaoCount } from './bulk'

type UpdateTransactionRequest = components['schemas']['UpdateTransactionRequest']

type BulkActionBarProps = {
  selected: Transaction[]
  onDone: () => void
}

export function BulkActionBar({ selected, onDone }: BulkActionBarProps) {
  const { data: tags = [] } = useTags()
  const bulkUpdate = useBulkUpdateTransactions()
  const pending = bulkUpdate.isPending
  // Entradas e saídas têm árvores de categoria diferentes; conta pernas de transferência pela
  // própria direction. Seleção mista desabilita a categoria em vez de adivinhar qual árvore mostrar.
  const kind = selectionKind(selected)

  const apply = async (label: string, update: (transaction: Transaction) => UpdateTransactionRequest) => {
    const byId = new Map(selected.map((transaction) => [transaction.id, transaction]))
    const result = await bulkUpdate.mutateAsync({
      ids: [...byId.keys()],
      body: (id) => update(byId.get(id) as Transaction),
    })

    if (result.failed.length === 0) {
      toast.success(`${label}: ${transacaoCount(result.succeeded.length)} atualizada${result.succeeded.length === 1 ? '' : 's'}.`)
    } else {
      toast.error(
        `${label}: ${transacaoCount(result.failed.length)} de ${selected.length} não ${result.failed.length === 1 ? 'pôde' : 'puderam'} ser atualizada${result.failed.length === 1 ? '' : 's'}.`,
      )
    }
    onDone()
  }

  return (
    // Acima da bottom nav (h-16 + safe-area) e do botão flutuante de nova transação, que sobe
    // mais 1.75rem além dela; no desktop, deslocada pela sidebar fixa (w-60).
    <div className="fixed inset-x-0 bottom-[calc(6rem+env(safe-area-inset-bottom))] z-40 px-4 md:bottom-6 md:pl-60">
      <div className="mx-auto flex max-w-3xl flex-wrap items-center gap-2 rounded-2xl border border-border bg-card p-3 shadow-lg">
        <span className="flex-1 text-sm font-medium">
          {selected.length} selecionada{selected.length === 1 ? '' : 's'}
          {pending && <LoaderCircle className="ml-2 inline h-4 w-4 animate-spin" />}
        </span>
        <div className="w-48">
          <CategoryPicker
            value={null}
            allowNone
            kind={kind ?? undefined}
            placeholder="Definir categoria"
            aria-label="Definir categoria"
            disabled={pending || kind === null}
            onChange={(categoryId) => apply('Categoria', () => ({ category_id: categoryId }))}
          />
        </div>
        {kind === null && (
          <p className="w-full text-xs text-muted-foreground">Selecione só entradas ou só saídas para definir a categoria</p>
        )}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="outline" disabled={pending || tags.length === 0} className="gap-2">
              <Tags className="h-4 w-4" />
              Adicionar tag
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {tags.map((tag) => (
              <DropdownMenuItem
                key={tag.id}
                onSelect={() =>
                  apply('Tag', (transaction) => ({
                    tag_ids: mergeTagIds((transaction.tags ?? []).map((each) => each.id), tag.id),
                  }))
                }
              >
                #{tag.name}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
        <Button variant="ghost" size="icon" aria-label="Cancelar seleção" disabled={pending} onClick={onDone}>
          <X className="h-4 w-4" />
        </Button>
      </div>
    </div>
  )
}
