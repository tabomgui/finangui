import { History, Landmark, Wand2 } from 'lucide-react'
import type { ComponentType } from 'react'
import { useRules } from '@/api/queries/rules'
import type { Transaction } from '@/api/types'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'

type Categorization = Transaction['categorization']

type CategorizationBadgeProps = {
  categorization: Categorization
}

/** Ícone pequeno com a origem da categoria (regra, histórico ou banco); nada para manual/sem categoria. */
export function CategorizationBadge({ categorization }: CategorizationBadgeProps) {
  const { data: rules } = useRules()

  if (categorization === null || categorization.source === 'manual') return null

  const { Icon, label } = describe(categorization, rules)

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span tabIndex={0} aria-label={label} className="inline-flex shrink-0 text-muted-foreground">
          <Icon className="h-3.5 w-3.5" />
        </span>
      </TooltipTrigger>
      <TooltipContent>{label}</TooltipContent>
    </Tooltip>
  )
}

type NonManualCategorization = Exclude<NonNullable<Categorization>, { source: 'manual' }>

function describe(
  categorization: NonManualCategorization,
  rules: { id: number; name: string }[] | undefined,
): { Icon: ComponentType<{ className?: string }>; label: string } {
  switch (categorization.source) {
    case 'rule': {
      const rule = rules?.find((item) => item.id === categorization.rule_id)
      return {
        Icon: Wand2,
        label: rule ? `Categorizada pela regra ${rule.name}` : 'Categorizada por uma regra excluída',
      }
    }
    case 'history':
      return { Icon: History, label: 'Categorizada pelo histórico' }
    case 'pluggy':
      return { Icon: Landmark, label: 'Categoria informada pelo banco' }
  }
}
