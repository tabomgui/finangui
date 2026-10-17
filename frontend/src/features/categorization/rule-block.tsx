import { Info, ScanSearch } from 'lucide-react'
import { Fragment, useId } from 'react'
import { useAccounts } from '@/api/queries/accounts'
import { Checkbox } from '@/components/ui/checkbox'
import { Switch } from '@/components/ui/switch'
import { describeSimpleCondition, pluralize, type RuleLookups } from '@/features/rules/rule-labels'
import { cn } from '@/lib/utils'
import { MAX_DEFAULT_RULE_GROUPS, type RuleConditionsBody } from './default-rule'
import type { RuleMatchState } from './use-rule-match-count'

const LINK_BUTTON = 'rounded-sm outline-none hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50'

type RuleBlockProps = {
  enabled: boolean
  onEnabledChange: (enabled: boolean) => void
  /** Caminho "Pai › Sub" da categoria escolhida, ou `null` ainda sem categoria. */
  categoryLabel: string | null
  /** Corpo que vai ser enviado (já compactado), resumido em texto. */
  body: RuleConditionsBody
  /** A regra padrão passa do limite de condições do backend: interruptor travado desligado. */
  tooLarge: boolean
  customized: boolean
  count: RuleMatchState
  applyToPending: boolean
  onApplyToPendingChange: (value: boolean) => void
  onCustomize: () => void
  onReset: () => void
}

/** Bloco "Criar regra para os próximos" da barra de ação da tela Categorizar. */
export function RuleBlock({
  enabled,
  onEnabledChange,
  categoryLabel,
  body,
  tooLarge,
  customized,
  count,
  applyToPending,
  onApplyToPendingChange,
  onCustomize,
  onReset,
}: RuleBlockProps) {
  const id = useId()
  const { data: accounts = [], isPending: accountsLoading } = useAccounts(true)
  const lookups: RuleLookups = {
    accountName: (accountId) => accounts.find((account) => account.id === accountId)?.name,
    categoryName: () => undefined,
    tagName: () => undefined,
    loading: accountsLoading,
  }

  return (
    <div className="rounded-xl border border-border p-3">
      <div className="flex items-start gap-3">
        <Switch
          id={`${id}-enabled`}
          checked={enabled}
          onCheckedChange={onEnabledChange}
          disabled={tooLarge}
          aria-describedby={tooLarge ? `${id}-too-large` : undefined}
          className="mt-0.5"
        />
        <div className="min-w-0 flex-1 text-sm">
          <label htmlFor={`${id}-enabled`} className="font-medium">
            Criar regra para os próximos
          </label>
          <p className="text-xs text-muted-foreground">
            Lançamentos novos que casarem vão direto pra{' '}
            {categoryLabel ? <span className="font-medium text-foreground">{categoryLabel}</span> : 'a categoria escolhida'}.
          </p>
          {tooLarge && (
            <p id={`${id}-too-large`} className="mt-1 flex items-start gap-1 text-xs text-muted-foreground">
              <Info aria-hidden className="mt-0.5 h-3.5 w-3.5 shrink-0" />
              Regra só para até {MAX_DEFAULT_RULE_GROUPS} grupos por vez. Marque menos grupos para criar uma.
            </p>
          )}
        </div>
      </div>

      {enabled && (
        <div className="mt-3 space-y-2 text-xs sm:pl-11">
          <div className="flex flex-wrap items-center gap-1.5">
            <span className="text-muted-foreground">Se</span>
            {body.conditions.map((node, i) => (
              <Fragment key={i}>
                {i > 0 && <span className="font-semibold text-muted-foreground">{connector(body.match)}</span>}
                <span className="inline-flex flex-wrap items-center gap-1 rounded-lg bg-muted px-2 py-1">
                  {'conditions' in node ? (
                    node.conditions.map((child, j) => (
                      <Fragment key={j}>
                        {j > 0 && <span className="text-muted-foreground">{connector(node.match)}</span>}
                        <span>{describeSimpleCondition(child, lookups)}</span>
                      </Fragment>
                    ))
                  ) : (
                    <span>{describeSimpleCondition(node, lookups)}</span>
                  )}
                </span>
              </Fragment>
            ))}
            <span className="text-muted-foreground">→ categoria</span>
          </div>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-2 text-muted-foreground">
            <span className="inline-flex items-center gap-1" aria-live="polite">
              <ScanSearch aria-hidden className="h-3.5 w-3.5" />
              <MatchCountText count={count} />
            </span>
            <span className="inline-flex items-center gap-1.5">
              <Checkbox
                id={`${id}-apply-to-pending`}
                checked={applyToPending}
                onCheckedChange={(checked) => onApplyToPendingChange(checked === true)}
              />
              <label htmlFor={`${id}-apply-to-pending`}>aplicar também a esses</label>
            </span>
            <button type="button" onClick={onCustomize} className={cn(LINK_BUTTON, 'font-medium text-primary')}>
              {customized ? 'Editar regra' : 'Personalizar'}
            </button>
            {customized && (
              <button type="button" onClick={onReset} className={LINK_BUTTON}>
                Voltar ao padrão
              </button>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

function connector(match: RuleConditionsBody['match']): string {
  return match === 'any' ? 'ou' : 'e'
}

function MatchCountText({ count }: { count: RuleMatchState }) {
  switch (count.status) {
    case 'local':
      return (
        <span>
          casa <strong className="text-foreground">{count.matched}</strong>{' '}
          {pluralize(count.matched, 'lançamento pendente', 'lançamentos pendentes')}
          {count.extra > 0 && (
            <>
              {' '}
              (<span className="text-amber-600 dark:text-amber-500">{count.extra} além dos marcados</span>)
            </>
          )}
        </span>
      )
    case 'server':
      return (
        <span>
          casa <strong className="text-foreground">{count.matched}</strong>{' '}
          {pluralize(count.matched, 'lançamento sem categoria', 'lançamentos sem categoria')}
        </span>
      )
    case 'loading':
      return <span>contando…</span>
    case 'needs-category':
      return <span>escolha a categoria para contar</span>
    case 'incomplete':
      return <span>complete a regra para contar</span>
    case 'error':
      return <span>não foi possível contar</span>
  }
}
