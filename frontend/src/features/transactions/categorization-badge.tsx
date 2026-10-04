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
  if (categorization === null || categorization.source === 'manual') return null

  if (categorization.source === 'rule') {
    return <RuleBadge ruleId={categorization.rule_id} />
  }

  const { Icon, label } = describe(categorization)

  return <Badge Icon={Icon} label={label} />
}

/**
 * `useRules` só é chamado aqui, isolado num componente próprio: carregar a lista de regras não
 * importa para history/pluggy, e cada linha de transação monta um `CategorizationBadge` — chamar
 * o hook incondicionalmente multiplicaria esse custo por nada.
 */
function RuleBadge({ ruleId }: { ruleId: number }) {
  const { data: rules } = useRules()
  const rule = rules?.find((item) => item.id === ruleId)
  // Enquanto `rules` ainda não carregou, um id sem nome não significa "regra excluída" — significa
  // "ainda não sabemos": um rótulo neutro evita afirmar que a regra foi excluída por um instante.
  const label = rules === undefined ? 'Categorizada por uma regra' : rule ? `Categorizada pela regra ${rule.name}` : 'Categorizada por uma regra excluída'

  return <Badge Icon={Wand2} label={label} />
}

function Badge({ Icon, label }: { Icon: ComponentType<{ className?: string }>; label: string }) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span role="img" aria-label={label} className="inline-flex shrink-0 text-muted-foreground">
          <Icon className="h-3.5 w-3.5" />
        </span>
      </TooltipTrigger>
      <TooltipContent>{label}</TooltipContent>
    </Tooltip>
  )
}

type NonManualCategorization = Exclude<NonNullable<Categorization>, { source: 'manual' } | { source: 'rule' }>

function describe(categorization: NonManualCategorization): { Icon: ComponentType<{ className?: string }>; label: string } {
  switch (categorization.source) {
    case 'history':
      return { Icon: History, label: 'Categorizada pelo histórico' }
    case 'pluggy':
      return { Icon: Landmark, label: 'Categoria informada pelo banco' }
  }
}
