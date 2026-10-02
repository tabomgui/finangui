import { Archive, ArchiveRestore, EllipsisVertical, Pencil, Plus, Shapes, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { useCategories, useDeleteCategory, useUpdateCategory } from '@/api/queries/categories'
import type { Category, CategoryKind } from '@/api/types'
import { PageBody } from '@/components/layout/page-body'
import { headerButton, PageHeader } from '@/components/layout/page-header'
import { CategoryIcon } from '@/components/shared/category-icon'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { EmptyState } from '@/components/shared/empty-state'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { notifyError } from '@/lib/form-errors'
import { cn } from '@/lib/utils'
import { CategoryFormDialog } from './category-form-dialog'
import { buildCategoryTree } from './category-tree'

type FormState = { open: boolean; category?: Category; parentId?: number | null }

export function CategoriesPage() {
  const [kind, setKind] = useState<CategoryKind>('expense')
  const [showArchived, setShowArchived] = useState(false)
  const { data: categories, isPending } = useCategories(showArchived)
  const update = useUpdateCategory()
  const remove = useDeleteCategory()
  const [form, setForm] = useState<FormState>({ open: false })
  const [deleting, setDeleting] = useState<Category | null>(null)

  const tree = categories ? buildCategoryTree(categories, kind) : []

  const toggleArchive = async (category: Category) => {
    try {
      await update.mutateAsync({ id: category.id, body: { is_archived: !category.is_archived } })
      toast.success(category.is_archived ? 'Categoria desarquivada.' : 'Categoria arquivada.')
    } catch (error) {
      notifyError(error)
    }
  }

  const row = (category: Category, isChild: boolean) => (
    <li key={category.id} className={cn('flex items-center gap-3 py-3 pr-3', isChild ? 'pl-12' : 'pl-3')}>
      <CategoryIcon icon={category.icon} color={category.color} size={isChild ? 'sm' : 'md'} />
      <div className="min-w-0 flex-1">
        <p className={cn('flex flex-wrap items-center gap-2', !isChild && 'font-medium')}>
          <span className="truncate">{category.name}</span>
          {category.is_transfer_effective && <Badge variant="outline">Transferência</Badge>}
          {category.is_archived && <Badge variant="secondary">Arquivada</Badge>}
        </p>
      </div>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" aria-label={`Ações da categoria ${category.name}`}>
            <EllipsisVertical className="h-4 w-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          {!isChild && (
            <DropdownMenuItem onSelect={() => setForm({ open: true, parentId: category.id })}>
              <Plus className="h-4 w-4" />
              Nova subcategoria
            </DropdownMenuItem>
          )}
          <DropdownMenuItem onSelect={() => setForm({ open: true, category })}>
            <Pencil className="h-4 w-4" />
            Editar
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={() => toggleArchive(category)}>
            {category.is_archived ? <ArchiveRestore className="h-4 w-4" /> : <Archive className="h-4 w-4" />}
            {category.is_archived ? 'Desarquivar' : 'Arquivar'}
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem className="text-destructive" onSelect={() => setDeleting(category)}>
            <Trash2 className="h-4 w-4" />
            Excluir
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </li>
  )

  return (
    <>
      <PageHeader
        title="Categorias"
        subtitle="Organize receitas e despesas"
        actions={
          <Button className={headerButton} onClick={() => setForm({ open: true, parentId: null })}>
            <Plus className="h-4 w-4" />
            Nova
          </Button>
        }
      />
      <PageBody>
        <Tabs value={kind} onValueChange={(value) => setKind(value as CategoryKind)}>
          <TabsList className="w-full">
            <TabsTrigger value="expense" className="flex-1">
              Despesas
            </TabsTrigger>
            <TabsTrigger value="income" className="flex-1">
              Receitas
            </TabsTrigger>
          </TabsList>
        </Tabs>
        <Card className="rounded-2xl p-2 shadow-card">
          {isPending ? (
            <div className="space-y-2 p-2">
              {[0, 1, 2, 3].map((i) => (
                <Skeleton key={i} className="h-12 w-full rounded-xl" />
              ))}
            </div>
          ) : tree.length > 0 ? (
            <ul className="divide-y divide-border">
              {tree.flatMap(({ category, children }) => [row(category, false), ...children.map((child) => row(child, true))])}
            </ul>
          ) : (
            <EmptyState icon={Shapes} title="Nenhuma categoria" description="Crie categorias para organizar seus lançamentos." />
          )}
        </Card>
        <div className="flex items-center justify-end gap-2 px-1">
          <Switch id="show-archived-categories" checked={showArchived} onCheckedChange={setShowArchived} />
          <Label htmlFor="show-archived-categories" className="text-sm text-muted-foreground">
            Mostrar arquivadas
          </Label>
        </div>
      </PageBody>

      <CategoryFormDialog
        open={form.open}
        onOpenChange={(open) => setForm((current) => ({ ...current, open }))}
        kind={form.category?.kind ?? kind}
        category={form.category}
        parentId={form.parentId}
      />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Excluir ${deleting?.name ?? 'categoria'}?`}
        description="Os lançamentos desta categoria ficam sem categoria. Categorias com subcategorias não podem ser excluídas."
        confirmLabel="Excluir"
        destructive
        onConfirm={async () => {
          if (deleting) await remove.mutateAsync(deleting.id)
        }}
      />
    </>
  )
}
