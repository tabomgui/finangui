import { useMemo } from 'react'
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
import { categoryPathById } from '@/lib/category-path'
import { useDebouncedValue } from '@/lib/use-debounced-value'
import { cn } from '@/lib/utils'
import { LOADING_PLACEHOLDER, pluralize } from './rule-labels'
import { rulePreviewSchema, toRuleBody, type RuleFormValues } from './rule-form-values'

type RulePreviewCardProps = {
  form: UseFormReturn<RuleFormValues>
}

const EMPTY_BODY: PreviewRuleBody = { match: 'all', conditions: [], actions: [] }
const DEBOUNCE_MS = 800
const TOO_MANY_REQUESTS_MESSAGE = 'Muitas prévias seguidas; aguarde um instante.'

type SampleItem = RulePreview['sample'][number]

function describeChanges(
  { transaction, changes }: SampleItem,
  categoriesById: ReadonlyMap<number, Category>,
  tags: Tag[],
  categoriesLoading: boolean,
  tagsLoading: boolean,
): string[] {
  const lines: string[] = []

  if (changes.category_id !== null) {
    const current = categoryPathById(transaction.category_id, categoriesById, categoriesLoading)
    lines.push(`${current} → ${categoryPathById(changes.category_id, categoriesById, categoriesLoading)}`)
  }
  if (changes.description !== null) lines.push(`Descrição → ${changes.description}`)
  if (changes.payee !== null) lines.push(`Favorecido → ${changes.payee}`)
  for (const tagId of changes.tag_ids) {
    const name = tags.find((tag) => tag.id === tagId)?.name
    lines.push(`+#${name ?? (tagsLoading ? LOADING_PLACEHOLDER : 'tag excluída')}`)
  }
  if (changes.is_ignored) lines.push('Será ignorado')

  return lines
}

type SampleRowProps = {
  item: SampleItem
  categoriesById: ReadonlyMap<number, Category>
  tags: Tag[]
  categoriesLoading: boolean
  tagsLoading: boolean
}

function SampleRow({ item, categoriesById, tags, categoriesLoading, tagsLoading }: SampleRowProps) {
  const { transaction } = item
  const changeLines = describeChanges(item, categoriesById, tags, categoriesLoading, tagsLoading)

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

export function RulePreviewCard({ form }: RulePreviewCardProps) {
  const values = useWatch({ control: form.control }) as RuleFormValues
  const debouncedValues = useDebouncedValue(values, DEBOUNCE_MS)
  // A prévia não depende do nome da regra: `rulePreviewSchema` valida só condições/ações, então
  // uma regra ainda sem nome já pode ser consultada enquanto o usuário monta o resto.
  const parsed = rulePreviewSchema.safeParse(debouncedValues)
  const enabled = parsed.success
  // Sem `overwrite`: a prévia é neutra (contagens independentes dele); quem escolhe trocar
  // categorias automáticas é o diálogo "Aplicar às existentes".
  const body: PreviewRuleBody = enabled ? toRuleBody(parsed.data) : EMPTY_BODY

  const { data, isPending, isPlaceholderData, isError, error, refetch } = useRulePreview(body, enabled)
  const { data: categories, isPending: categoriesLoading } = useCategories(true)
  const { data: tags = [], isPending: tagsLoading } = useTags()

  const invalid = error instanceof ApiError && error.status === 422
  const tooManyRequests = error instanceof ApiError && error.status === 429
  const hasIgnoreAction = values.actions.some((action) => action.type === 'ignore')
  const categoriesById = useMemo(() => new Map((categories ?? []).map((category) => [category.id, category] as const)), [categories])
  // Categoria da prévia que de fato chegou (o corpo debounced, não o que está sendo digitado).
  const ruleCategoryId = enabled ? (parsed.data.actions.find((action) => action.type === 'set_category')?.category_id ?? null) : null

  return (
    <div className="space-y-3 rounded-xl border border-border p-3">
      <p className="text-sm font-medium">Lançamentos que esta regra pega</p>

      {hasIgnoreAction && (
        <p className="text-xs text-muted-foreground">Lançamentos ignorados saem do saldo, dos relatórios e do total das faturas.</p>
      )}

      {!enabled && <p className="text-sm text-muted-foreground">Complete a regra para ver a prévia.</p>}

      {enabled && isPending && (
        <div className="space-y-2">
          <Skeleton className="h-4 w-48" />
          <Skeleton className="h-16 w-full" />
        </div>
      )}

      {enabled && isError && (
        <div className="space-y-2">
          <p className="text-sm text-muted-foreground">
            {invalid ? 'Corrija a regra para ver a prévia.' : tooManyRequests ? TOO_MANY_REQUESTS_MESSAGE : error.message}
          </p>
          {!invalid && (
            <Button type="button" variant="outline" size="sm" onClick={() => refetch()}>
              Tentar de novo
            </Button>
          )}
        </div>
      )}

      {enabled && !isPending && !isError && data && (
        <div className={cn('space-y-3 transition-opacity', isPlaceholderData && 'opacity-60')}>
          <div className="space-y-1">
            <p className="text-sm">
              Pega {data.matched} {pluralize(data.matched, 'lançamento', 'lançamentos')} hoje
            </p>
            {ruleCategoryId !== null && data.matched > 0 && (
              <ul className="space-y-0.5 text-xs text-muted-foreground">
                <li>
                  {data.uncategorized} sem categoria {pluralize(data.uncategorized, 'recebe', 'recebem')}{' '}
                  <span className="font-medium text-foreground">{categoryPathById(ruleCategoryId, categoriesById, categoriesLoading)}</span>
                </li>
                <li>
                  {data.other_category} já {pluralize(data.other_category, 'tem', 'têm')} outra categoria
                </li>
              </ul>
            )}
          </div>

          {data.sample.length > 0 && (
            <div className="space-y-2">
              {data.sample.map((item) => (
                <SampleRow
                  key={item.transaction.id}
                  item={item}
                  categoriesById={categoriesById}
                  tags={tags}
                  categoriesLoading={categoriesLoading}
                  tagsLoading={tagsLoading}
                />
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
