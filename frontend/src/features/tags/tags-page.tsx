import { Hash, Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useDeleteTag, useTags } from '@/api/queries/tags'
import type { Tag } from '@/api/types'
import { PageBody } from '@/components/layout/page-body'
import { headerButton, PageHeader } from '@/components/layout/page-header'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { TagFormDialog } from './tag-form-dialog'

type FormState = { open: boolean; tag?: Tag }

export function TagsPage() {
  const { data: tags, isPending } = useTags()
  const remove = useDeleteTag()
  const [form, setForm] = useState<FormState>({ open: false })
  const [deleting, setDeleting] = useState<Tag | null>(null)

  const openCreate = () => {
    setForm({ open: true, tag: undefined })
  }

  return (
    <>
      <PageHeader
        title="Tags"
        subtitle="Marcadores livres para agrupar lançamentos"
        actions={
          <Button className={headerButton} onClick={openCreate}>
            <Plus className="h-4 w-4" />
            Nova tag
          </Button>
        }
      />
      <PageBody>
        <Card className="rounded-2xl p-2 shadow-card">
          {isPending ? (
            <div className="space-y-2 p-2">
              {[0, 1, 2].map((i) => (
                <Skeleton key={i} className="h-10 w-full rounded-xl" />
              ))}
            </div>
          ) : tags && tags.length > 0 ? (
            <ul className="divide-y divide-border">
              {tags.map((tag) => (
                <li key={tag.id} className="flex items-center gap-3 px-3 py-2">
                  <span
                    aria-hidden
                    className="h-3 w-3 shrink-0 rounded-full bg-muted-foreground"
                    style={tag.color ? { backgroundColor: tag.color } : undefined}
                  />
                  <span className="min-w-0 flex-1 truncate font-medium">#{tag.name}</span>
                  <Button
                    variant="ghost"
                    size="icon"
                    aria-label={`Editar ${tag.name}`}
                    onClick={() => setForm({ open: true, tag })}
                  >
                    <Pencil className="h-4 w-4" />
                  </Button>
                  <Button
                    variant="ghost"
                    size="icon"
                    aria-label={`Excluir ${tag.name}`}
                    onClick={() => setDeleting(tag)}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState
              icon={Hash}
              title="Nenhuma tag"
              description="Use tags para agrupar lançamentos de um mesmo assunto, como uma viagem."
              action={<Button onClick={openCreate}>Criar tag</Button>}
            />
          )}
        </Card>
      </PageBody>

      <TagFormDialog open={form.open} onOpenChange={(open) => setForm((current) => ({ ...current, open }))} tag={form.tag} />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Excluir #${deleting?.name ?? 'tag'}?`}
        description="A tag sai de todos os lançamentos."
        confirmLabel="Excluir"
        destructive
        onConfirm={async () => {
          if (deleting) await remove.mutateAsync(deleting.id)
        }}
      />
    </>
  )
}
