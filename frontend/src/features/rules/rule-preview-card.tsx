import { useWatch, type UseFormReturn } from 'react-hook-form'
import { ApiError } from '@/api/errors'
import { useCategories } from '@/api/queries/categories'
import { useRulePreview, type PreviewRuleBody } from '@/api/queries/rules'
import { useTags } from '@/api/queries/tags'
import type { Category, RulePreview, Tag } from '@/api/types'
import { CategoryIcon } from '@/components/shared/category-icon'
import { MoneyText } from '@/components/shared/money-text'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { useDebouncedValue } from '@/lib/use-debounced-value'
import { cn } from '@/lib/utils'
import { ruleSchema, toRuleBody, type RuleFormValues } from './rule-form-values'

type RulePreviewCardProps = {
  form: UseFormReturn<RuleFormValues>
  overwrite: boolean
  onOverwriteChange: (value: boolean) => void
}

const EMPTY_BODY: PreviewRuleBody = { match: 'all', conditions: [], actions: [] }

function pluralize(count: number, singular: string, plural: string): string {
  return count === 1 ? singular : plural
}

type SampleChanges = RulePreview['sample'][number]['changes']

function describeChanges(changes: SampleChanges, categories: Category[], tags: Tag[]): string[] {
  const lines: string[] = []

  if (changes.category_id !== null) {
    const name = categories.find((category) => category.id === changes.category_id)?.name ?? 'categoria excluída'
    lines.push(`Categoria → ${name}`)
  }
  if (changes.description !== null) lines.push(`Descrição → ${changes.description}`)
  if (changes.payee !== null) lines.push(`Favorecido → ${changes.payee}`)
  for (const tagId of changes.tag_ids) {
    const name = tags.find((tag) => tag.id === tagId)?.name
    if (name) lines.push(`+#${name}`)
  }
  if (changes.is_ignored) lines.push('Será ignorado')

  return lines
}

function SampleRow({ item, categories, tags }: { item: RulePreview['sample'][number]; categories: Category[]; tags: Tag[] }) {
  const { transaction, changes } = item
  const changeLines = describeChanges(changes, categories, tags)

  return (
    <div className="space-y-1 rounded-xl border border-border p-3">
      <div className="flex items-center gap-3">
        <CategoryIcon icon={transaction.category?.icon} color={transaction.category?.color} size="sm" />
        <p className="min-w-0 flex-1 truncate text-sm font-medium">{transaction.description}</p>
        <MoneyText cents={transaction.amount} currency={transaction.currency} direction={transaction.direction} className="shrink-0 text-sm" />
      </div>
      {changeLines.length > 0 && (
        <ul className="pl-12 text-xs text-muted-foreground">
          {changeLines.map((line) => (
            <li key={line}>{line}</li>
          ))}
        </ul>
      )}
    </div>
  )
}

export function RulePreviewCard({ form, overwrite, onOverwriteChange }: RulePreviewCardProps) {
  const values = useWatch({ control: form.control }) as RuleFormValues
  const debouncedValues = useDebouncedValue(values, 500)
  const parsed = ruleSchema.safeParse(debouncedValues)
  const enabled = parsed.success
  const body: PreviewRuleBody = enabled ? { ...toRuleBody(parsed.data), overwrite } : EMPTY_BODY

  const { data, isPending, isPlaceholderData, isError, error, refetch } = useRulePreview(body, enabled)
  const { data: categories = [] } = useCategories(true)
  const { data: tags = [] } = useTags()

  const invalid = error instanceof ApiError && error.status === 422

  return (
    <div className="space-y-3 rounded-xl border border-border p-3">
      <p className="text-sm font-medium">Prévia</p>

      <div className="flex items-start gap-3">
        <Switch id="rule-preview-overwrite" checked={overwrite} onCheckedChange={onOverwriteChange} />
        <div className="space-y-1">
          <label htmlFor="rule-preview-overwrite" className="text-sm font-medium">
            Sobrescrever categorias existentes
          </label>
          <p className="text-xs text-muted-foreground">Categorias definidas à mão nunca são sobrescritas.</p>
        </div>
      </div>

      {!enabled && <p className="text-sm text-muted-foreground">Complete a regra para ver a prévia.</p>}

      {enabled && isPending && (
        <div className="space-y-2">
          <Skeleton className="h-4 w-48" />
          <Skeleton className="h-16 w-full" />
        </div>
      )}

      {enabled && isError && (
        <div className="space-y-2">
          <p className="text-sm text-muted-foreground">{invalid ? 'Corrija a regra para ver a prévia.' : error.message}</p>
          {!invalid && (
            <Button type="button" variant="outline" size="sm" onClick={() => refetch()}>
              Tentar de novo
            </Button>
          )}
        </div>
      )}

      {enabled && !isPending && !isError && data && (
        <div className={cn('space-y-3 transition-opacity', isPlaceholderData && 'opacity-60')}>
          <p className="text-sm">
            Casa {data.matched} {pluralize(data.matched, 'lançamento', 'lançamentos')} · mudaria {data.changed}{' '}
            {pluralize(data.changed, 'lançamento', 'lançamentos')}
          </p>

          {data.sample.length > 0 && (
            <div className="space-y-2">
              {data.sample.map((item) => (
                <SampleRow key={item.transaction.id} item={item} categories={categories} tags={tags} />
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
