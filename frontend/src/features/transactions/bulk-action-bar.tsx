import { useQueryClient } from '@tanstack/react-query'
import { LoaderCircle, Tags, X } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { api, unwrap } from '@/api/client'
import { invalidateLedger } from '@/api/query-keys'
import { useTags } from '@/api/queries/tags'
import type { components } from '@/api/schema'
import type { Transaction } from '@/api/types'
import { CategoryPicker } from '@/components/shared/category-picker'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { mergeTagIds, runInBatches, transacaoCount } from './bulk'

type UpdateTransactionRequest = components['schemas']['UpdateTransactionRequest']

type BulkActionBarProps = {
  selected: Transaction[]
  onDone: () => void
}

export function BulkActionBar({ selected, onDone }: BulkActionBarProps) {
  const queryClient = useQueryClient()
  const { data: tags = [] } = useTags()
  const [pending, setPending] = useState(false)

  const apply = async (label: string, update: (transaction: Transaction) => UpdateTransactionRequest) => {
    setPending(true)
    const byId = new Map(selected.map((transaction) => [transaction.id, transaction]))
    const result = await runInBatches([...byId.keys()], (id) =>
      unwrap(
        api.PATCH('/transactions/{transaction}', {
          params: { path: { transaction: id } },
          body: update(byId.get(id) as Transaction),
        }),
      ),
    )
    await invalidateLedger(queryClient)
    setPending(false)

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
            placeholder="Definir categoria"
            onChange={(categoryId) => apply('Categoria', () => ({ category_id: categoryId }))}
          />
        </div>
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
