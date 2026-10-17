import { Plus, ScanSearch, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import type { Direction } from '@/api/types'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { ConditionRow, type ConditionErrors } from '@/features/rules/condition-row'
import { pluralize } from '@/features/rules/rule-labels'
import { emptyCondition, type ConditionValues } from '@/features/rules/rule-form-values'
import {
  emptyAlternative,
  MAX_RULE_CONDITIONS,
  ruleConditionCount,
  toRuleBody,
  type MatchableTransaction,
  type RuleAlternative,
} from './default-rule'
import { useRuleMatchCount, type RuleMatchState } from './use-rule-match-count'

/**
 * Erros por caminho, no formato do corpo da regra (`conditions.0.conditions.1.value`,
 * `conditions.0.conditions`, `conditions`, `match`) — o mesmo dos 422 de `rule.*` do apply, sem o
 * prefixo `rule.`.
 */
export type RuleErrors = Record<string, string>

type RuleEditorDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Regra atual (padrão ou já personalizada), ponto de partida do editor a cada abertura. */
  initial: RuleAlternative[]
  /** Erros vindos do servidor ao aplicar, mostrados nas condições correspondentes. */
  errors: RuleErrors
  transactions: MatchableTransaction[]
  selectedIds: ReadonlySet<number>
  direction: Direction
  categoryId: number | null
  /** A lista carregada é só um recorte (busca/truncada): a contagem vem da prévia do servidor. */
  partialList: boolean
  onSave: (alternatives: RuleAlternative[]) => void
}

export function RuleEditorDialog({ open, onOpenChange, ...props }: RuleEditorDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Regra de categorização</DialogTitle>
          <DialogDescription>
            Começa pelo padrão sugerido. Cada bloco é uma alternativa (“ou”); dentro do bloco, tudo precisa bater (“e”).
          </DialogDescription>
        </DialogHeader>
        {/* Montado só com o diálogo aberto: o rascunho recomeça de `initial` a cada abertura. */}
        {open && <RuleEditorBody {...props} onCancel={() => onOpenChange(false)} />}
      </DialogContent>
    </Dialog>
  )
}

type RuleEditorBodyProps = Omit<RuleEditorDialogProps, 'open' | 'onOpenChange'> & { onCancel: () => void }

function copyAlternatives(alternatives: RuleAlternative[]): RuleAlternative[] {
  return alternatives.map((alternative) => ({ ...alternative, conditions: alternative.conditions.map((condition) => ({ ...condition })) }))
}

function validate(alternatives: RuleAlternative[]): RuleErrors {
  const errors: RuleErrors = {}
  if (alternatives.length === 0) errors.conditions = 'Adicione pelo menos uma alternativa.'
  // Mesma conta do backend, sobre o corpo que de fato vai ser enviado (já compactado).
  if (ruleConditionCount(toRuleBody(alternatives).conditions) > MAX_RULE_CONDITIONS) {
    errors.conditions = `No máximo ${MAX_RULE_CONDITIONS} condições, contando as de dentro dos grupos.`
  }
  alternatives.forEach((alternative, i) => {
    alternative.conditions.forEach((condition, j) => {
      if (condition.value.trim() === '') errors[`conditions.${i}.conditions.${j}.value`] = 'Informe um valor.'
    })
  })
  return errors
}

function rowErrors(errors: RuleErrors, i: number, j: number): ConditionErrors {
  const at = (key: string) => {
    const message = errors[`conditions.${i}.conditions.${j}.${key}`]
    return message ? { message } : undefined
  }
  return { field: at('field'), op: at('op'), value: at('value') }
}

/** Mensagens que não pertencem a nenhum campo de uma condição (lista inteira, alternativa, índice). */
function looseErrors(errors: RuleErrors, prefix: string): string[] {
  return Object.entries(errors)
    .filter(([path]) => {
      if (prefix === '') return path === 'match' || path === 'conditions'
      if (!path.startsWith(prefix)) return false
      const rest = path.slice(prefix.length)
      return rest === '' || rest === '.conditions' || /^\.conditions\.\d+$/.test(rest)
    })
    .map(([, message]) => message)
}

/** Sem `tipo é igual a <direção>` em alguma alternativa, a regra salva casa a outra direção também. */
function missesDirection(alternatives: RuleAlternative[], direction: Direction): boolean {
  return alternatives.some(
    (alternative) => !alternative.conditions.some((each) => each.field === 'direction' && each.op === 'equals' && each.value === direction),
  )
}

function RuleEditorBody({
  initial,
  errors: serverErrors,
  transactions,
  selectedIds,
  direction,
  categoryId,
  partialList,
  onSave,
  onCancel,
}: RuleEditorBodyProps) {
  const [alternatives, setAlternatives] = useState(() => copyAlternatives(initial))
  const [errors, setErrors] = useState<RuleErrors>(serverErrors)
  const count = useRuleMatchCount({ alternatives, transactions, selectedIds, direction, categoryId, enabled: true, partialList })

  // Mudar a estrutura (adicionar/remover) desloca os índices dos caminhos de erro: descarta todos.
  const restructure = (next: RuleAlternative[]) => {
    setAlternatives(next)
    setErrors({})
  }

  const updateCondition = (i: number, j: number, next: ConditionValues) => {
    // Editar a linha resolve (ou muda) o erro que o servidor apontou nela: some só o dela.
    const rowPrefix = `conditions.${i}.conditions.${j}.`
    setErrors((current) => Object.fromEntries(Object.entries(current).filter(([path]) => !path.startsWith(rowPrefix))))
    setAlternatives((current) =>
      current.map((alternative, index) =>
        index === i ? { ...alternative, conditions: alternative.conditions.map((condition, k) => (k === j ? next : condition)) } : alternative,
      ),
    )
  }

  // Remover a última condição de uma alternativa remove a alternativa inteira.
  const removeCondition = (i: number, j: number) =>
    restructure(
      alternatives
        .map((alternative, index) => (index === i ? { ...alternative, conditions: alternative.conditions.filter((_, k) => k !== j) } : alternative))
        .filter((alternative) => alternative.conditions.length > 0),
    )

  const addCondition = (i: number) =>
    restructure(alternatives.map((alternative, index) => (index === i ? { ...alternative, conditions: [...alternative.conditions, emptyCondition()] } : alternative)))

  const save = () => {
    const found = validate(alternatives)
    if (Object.keys(found).length > 0) return setErrors(found)
    onSave(alternatives)
  }

  const rootMessages = looseErrors(errors, '')

  return (
    <>
      <div className="space-y-3">
        {rootMessages.map((message) => (
          <p key={message} role="alert" className="text-sm text-destructive">
            {message}
          </p>
        ))}
        {alternatives.map((alternative, i) => (
          <div key={alternative.uid} className="space-y-3 rounded-xl border border-border p-3">
            <p className="text-xs font-semibold text-muted-foreground">{i === 0 ? 'Se' : 'Ou se'}</p>
            {looseErrors(errors, `conditions.${i}`).map((message) => (
              <p key={message} role="alert" className="text-sm text-destructive">
                {message}
              </p>
            ))}
            {alternative.conditions.map((condition, j) => (
              <ConditionRow
                key={condition.uid ?? j}
                idPrefix={`categorize-rule-${i}-${j}`}
                rowLabel={`condição ${j + 1} da alternativa ${i + 1}`}
                value={condition}
                onChange={(next) => updateCondition(i, j, next)}
                onRemove={() => removeCondition(i, j)}
                errors={rowErrors(errors, i, j)}
              />
            ))}
            <Button type="button" variant="link" size="sm" className="h-auto px-0" onClick={() => addCondition(i)}>
              <Plus aria-hidden className="h-4 w-4" />
              condição
            </Button>
          </div>
        ))}
        <Button type="button" variant="link" size="sm" className="h-auto px-0" onClick={() => restructure([...alternatives, emptyAlternative()])}>
          <Plus aria-hidden className="h-4 w-4" />
          alternativa (“ou”)
        </Button>
      </div>

      {missesDirection(alternatives, direction) && (
        <p className="flex items-start gap-2 text-xs text-amber-700 dark:text-amber-500">
          <TriangleAlert aria-hidden className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          {direction === 'out'
            ? 'Sem “Tipo é igual a Saída” em toda alternativa, a regra salva também vai casar entradas daqui pra frente.'
            : 'Sem “Tipo é igual a Entrada” em toda alternativa, a regra salva também vai casar saídas daqui pra frente.'}
        </p>
      )}

      <p className="flex items-center gap-2 rounded-xl bg-muted/50 p-3 text-sm" aria-live="polite">
        <ScanSearch aria-hidden className="h-4 w-4 shrink-0 text-muted-foreground" />
        <DialogCount count={count} />
      </p>

      <DialogFooter>
        <Button type="button" variant="ghost" onClick={onCancel}>
          Cancelar
        </Button>
        <Button type="button" onClick={save}>
          Usar esta regra
        </Button>
      </DialogFooter>
    </>
  )
}

function DialogCount({ count }: { count: RuleMatchState }) {
  switch (count.status) {
    case 'local':
      return (
        <span>
          Casaria <strong>{count.matched}</strong> {pluralize(count.matched, 'lançamento pendente', 'lançamentos pendentes')} hoje.
        </span>
      )
    case 'server':
      return (
        <span>
          Casaria <strong>{count.matched}</strong> {pluralize(count.matched, 'lançamento sem categoria', 'lançamentos sem categoria')} hoje.
        </span>
      )
    case 'loading':
      return <span className="text-muted-foreground">Contando…</span>
    case 'needs-category':
      return <span className="text-muted-foreground">Escolha a categoria para contar quantos lançamentos a regra casaria.</span>
    case 'incomplete':
      return <span className="text-muted-foreground">Complete as condições para ver quantos lançamentos a regra casaria.</span>
    case 'error':
      return <span className="text-muted-foreground">Não foi possível contar agora.</span>
  }
}
