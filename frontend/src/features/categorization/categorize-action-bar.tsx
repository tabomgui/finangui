import { Check, Info, LoaderCircle, X } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { ApiError } from '@/api/errors'
import type { ApplyCategorizationVariables } from '@/api/queries/categorization'
import type {
  CategorizationApplyResult,
  Category,
  CategoryKind,
  PendingCategorizationGroup,
  PendingCategorizationTransaction,
} from '@/api/types'
import { CategoryPicker } from '@/components/shared/category-picker'
import { MoneyText } from '@/components/shared/money-text'
import { Button } from '@/components/ui/button'
import { useSidebar } from '@/components/ui/sidebar'
import { pluralize } from '@/features/rules/rule-labels'
import { notifyError } from '@/lib/form-errors'
import { cn } from '@/lib/utils'
import { categoryPath } from '@/lib/category-path'
import {
  compactRule,
  defaultAlternatives,
  defaultRuleName,
  editorErrors,
  MAX_RULE_CONDITIONS,
  ruleConditionCount,
  type RuleAlternative,
} from './default-rule'
import { RuleBlock } from './rule-block'
import { RuleEditorDialog, type RuleErrors } from './rule-editor-dialog'
import { hasPartialGroupSelection, selectionKind } from './selection'
import { useRuleMatchCount } from './use-rule-match-count'

const RULE_ENABLED_KEY = 'finangui:categorizeRuleEnabled'
const NO_ERRORS: RuleErrors = {}

/** Última escolha manual do interruptor "Criar regra", ou `null` se o usuário nunca mexeu nele. */
function storedRuleEnabled(): boolean | null {
  const raw = window.localStorage.getItem(RULE_ENABLED_KEY)
  return raw === 'true' ? true : raw === 'false' ? false : null
}

function rememberRuleEnabled(value: boolean): void {
  window.localStorage.setItem(RULE_ENABLED_KEY, String(value))
}

/** Erros 422 de `rule.match`/`rule.conditions*`, sem o prefixo `rule.`; vazio se não houver. */
function ruleErrorsFrom(error: unknown): RuleErrors {
  if (!(error instanceof ApiError) || error.status !== 422) return NO_ERRORS
  const errors: RuleErrors = {}
  for (const [path, messages] of Object.entries(error.fieldErrors)) {
    if ((path === 'rule.match' || path.startsWith('rule.conditions')) && messages[0]) errors[path.slice('rule.'.length)] = messages[0]
  }
  return errors
}

type CategorizeActionBarProps = {
  /** Grupos carregados (a resposta atual de pendentes). */
  groups: PendingCategorizationGroup[]
  /** Pendentes carregados, para a contagem local da regra. */
  transactions: PendingCategorizationTransaction[]
  selected: PendingCategorizationTransaction[]
  selectedIds: ReadonlySet<number>
  /**
   * A lista carregada é só um recorte dos pendentes (busca ativa ou resposta `truncated`): a
   * contagem da regra vem da prévia do servidor em vez de contar só o que está na tela.
   */
  partialList: boolean
  categoriesById: ReadonlyMap<number, Category>
  applyCategorization: (variables: ApplyCategorizationVariables) => Promise<CategorizationApplyResult>
  applyPending: boolean
  onClear: () => void
  /** Aplicação concluída: a página limpa a seleção (e esta barra some). */
  onApplied: () => void
}

/**
 * Barra fixa da tela Categorizar, no padrão da `BulkActionBar` de Transações: resumo da seleção,
 * categoria (com criação na hora), bloco da regra e "Aplicar". Só montada com seleção: escolha
 * de categoria e regra personalizada recomeçam do zero quando a seleção é limpa.
 */
export function CategorizeActionBar({
  groups,
  transactions,
  selected,
  selectedIds,
  partialList,
  categoriesById,
  applyCategorization,
  applyPending,
  onClear,
  onApplied,
}: CategorizeActionBarProps) {
  const { state: sidebarState } = useSidebar()
  const [categoryId, setCategoryId] = useState<number | null>(null)
  // Tipo da seleção quando a categoria foi escolhida: se a seleção passa a ser do outro tipo, a
  // categoria escolhida (de receita/despesa) não serve mais e é descartada.
  const [categoryKind, setCategoryKind] = useState<CategoryKind | null>(null)
  const [createdCategory, setCreatedCategory] = useState<Category | null>(null)
  const [manualRuleEnabled, setManualRuleEnabled] = useState<boolean | null>(null)
  const [storedChoice] = useState(storedRuleEnabled)
  const [applyToPending, setApplyToPending] = useState(true)
  const [customRule, setCustomRule] = useState<RuleAlternative[] | null>(null)
  const [editorOpen, setEditorOpen] = useState(false)
  const [ruleErrors, setRuleErrors] = useState<RuleErrors>(NO_ERRORS)

  const kind = selectionKind(selected)
  const mixed = kind === 'mixed'
  const direction = kind === 'income' ? 'in' : 'out'

  if (categoryId !== null && kind !== null && kind !== 'mixed' && categoryKind !== kind) {
    setCategoryId(null)
    setCategoryKind(null)
    setCreatedCategory(null)
  }

  // A regra personalizada vale só para a seleção em que foi feita: qualquer mudança na seleção
  // volta ao padrão (refazer a mesma seleção depois não traz a personalização antiga de volta).
  const selectionKey = [...selectedIds].sort((a, b) => a - b).join(',')
  const [customSelectionKey, setCustomSelectionKey] = useState(selectionKey)
  if (customSelectionKey !== selectionKey) {
    setCustomSelectionKey(selectionKey)
    setCustomRule(null)
  }

  const selectedGroups = groups.filter((group) => group.transactions.some((transaction) => selectedIds.has(transaction.id)))
  const partial = !mixed && hasPartialGroupSelection(groups, selectedIds, direction)
  // Soma de uma seleção temporária, que só existe na tela: o backend não tem como devolvê-la pronta.
  const net = selected.reduce((sum, transaction) => sum + (transaction.direction === 'in' ? transaction.amount : -transaction.amount), 0)

  const category = categoryId === null ? undefined : (categoriesById.get(categoryId) ?? (createdCategory?.id === categoryId ? createdCategory : undefined))
  const categoryLabel = category ? categoryPath(category, categoriesById) : null

  const groupNames = selectedGroups.map((group) => group.name)
  // Regra padrão estável enquanto os grupos marcados e a direção não mudam (a contagem local e
  // as chaves das condições dependem da mesma referência); refeita durante o render quando mudam.
  const defaultsKey = `${direction}\n${groupNames.join('\n')}`
  const [defaultRule, setDefaultRule] = useState(() => ({ key: defaultsKey, alternatives: defaultAlternatives(groupNames, direction) }))
  if (defaultRule.key !== defaultsKey) setDefaultRule({ key: defaultsKey, alternatives: defaultAlternatives(groupNames, direction) })
  const defaults = defaultRule.alternatives
  const customized = customRule !== null
  const alternatives = customRule ?? defaults
  const compact = compactRule(alternatives)

  // Muitos grupos marcados: nem a regra padrão compacta cabe no limite de condições do backend.
  const tooLarge = !customized && ruleConditionCount(compactRule(defaults).body.conditions) > MAX_RULE_CONDITIONS

  // Seleção parcial num grupo (ex.: 3 de 38) é provável exceção: começa sem regra, até o usuário
  // ligar à mão. Sem seleção parcial, vale a última escolha manual guardada (ligada por padrão).
  const ruleEnabled = !tooLarge && (manualRuleEnabled ?? (partial ? false : (storedChoice ?? true)))

  const count = useRuleMatchCount({
    alternatives,
    transactions,
    selectedIds,
    direction,
    categoryId,
    enabled: ruleEnabled && !mixed,
    partialList,
  })
  const extra = ruleEnabled && applyToPending && count.status === 'local' ? count.extra : 0
  const applyCount = selected.length + extra
  // Contagem vinda do servidor (sem saber quais já estão marcados): o número do botão fica só nos
  // marcados, e uma dica avisa que a regra ainda categoriza os outros pendentes que casarem.
  const ruleAddsUnknown = ruleEnabled && applyToPending && count.status !== 'local'

  const changeRuleEnabled = (value: boolean) => {
    setManualRuleEnabled(value)
    rememberRuleEnabled(value)
  }

  const changeCategory = (id: number | null) => {
    setCategoryId(id)
    setCategoryKind(id === null || kind === 'mixed' ? null : kind)
    if (id !== createdCategory?.id) setCreatedCategory(null)
  }

  const openEditor = () => {
    setRuleErrors(NO_ERRORS)
    setEditorOpen(true)
  }

  const saveRule = (next: RuleAlternative[]) => {
    setCustomRule(next)
    setRuleErrors(NO_ERRORS)
    setEditorOpen(false)
  }

  const apply = async () => {
    if (categoryId === null || mixed) return
    const label = categoryLabel ?? 'categoria'
    const variables: ApplyCategorizationVariables = {
      transaction_ids: selected.map((transaction) => transaction.id),
      category_id: categoryId,
      ...(ruleEnabled && {
        rule: {
          name: defaultRuleName(groupNames, label),
          ...compact.body,
          apply_to_pending: applyToPending,
        },
      }),
    }

    try {
      const result = await applyCategorization(variables)
      const total = result.categorized + (result.rule_applied ?? 0)
      if (total === 0) {
        toast(result.rule ? 'Regra criada; esses lançamentos já tinham categoria.' : 'Esses lançamentos já tinham categoria.')
      } else {
        toast.success(`${total} ${pluralize(total, 'lançamento', 'lançamentos')} → ${label}${result.rule ? ' · regra criada' : ''}`)
      }
      onApplied()
    } catch (error) {
      const errors = ruleErrorsFrom(error)
      if (ruleEnabled && Object.keys(errors).length > 0) {
        // O erro é numa condição: abre o editor com a regra enviada e o erro na linha certa (os
        // caminhos do 422 são os do corpo compactado, traduzidos para as alternativas do editor).
        setCustomRule(alternatives)
        setRuleErrors(editorErrors(errors, compact.origins))
        setEditorOpen(true)
      } else {
        notifyError(error)
      }
    }
  }

  return (
    // Acima da bottom nav (h-16 + safe-area) e do botão flutuante de nova transação, como a
    // `BulkActionBar`; no desktop, deslocada pela largura atual da sidebar colapsável.
    <div
      className={cn(
        'fixed inset-x-0 bottom-[calc(6rem+env(safe-area-inset-bottom))] z-40 px-4 md:bottom-6',
        sidebarState === 'collapsed' ? 'md:pl-[var(--sidebar-width-icon)]' : 'md:pl-[var(--sidebar-width)]',
      )}
    >
      <div className="mx-auto max-h-[calc(100dvh-8rem)] max-w-3xl overflow-y-auto rounded-2xl border border-border bg-card p-3 shadow-lg">
        <div className="flex flex-wrap items-center gap-2">
          <span className="min-w-0 flex-1 text-sm font-medium">
            <span>{selected.length} {pluralize(selected.length, 'selecionado', 'selecionados')}</span>
            <span className="text-muted-foreground">
              {' · '}
              <MoneyText cents={Math.abs(net)} direction={net >= 0 ? 'in' : 'out'} colored={false} />
              {' · '}
              {selectedGroups.length} {pluralize(selectedGroups.length, 'grupo', 'grupos')}
            </span>
          </span>
          <div className="order-last w-full sm:order-none sm:w-64">
            <CategoryPicker
              value={categoryId}
              onChange={changeCategory}
              onCreate={setCreatedCategory}
              allowCreate
              allowNone={false}
              kind={mixed || kind === null ? undefined : kind}
              placeholder="Definir categoria"
              aria-label="Definir categoria"
              disabled={mixed || applyPending}
            />
          </div>
          <Button type="button" variant="ghost" size="icon" aria-label="Cancelar seleção" disabled={applyPending} onClick={onClear}>
            <X aria-hidden className="h-4 w-4" />
          </Button>
        </div>

        {mixed ? (
          <p className="mt-2 flex items-start gap-1.5 text-xs text-muted-foreground">
            <Info aria-hidden className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            Selecione só entradas ou só saídas para definir a categoria (use “só saídas”/“só entradas” no grupo).
          </p>
        ) : (
          <>
            <div className="mt-3">
              <RuleBlock
                enabled={ruleEnabled}
                onEnabledChange={changeRuleEnabled}
                categoryLabel={categoryLabel}
                body={compact.body}
                tooLarge={tooLarge}
                customized={customized}
                count={count}
                applyToPending={applyToPending}
                onApplyToPendingChange={setApplyToPending}
                onCustomize={openEditor}
                onReset={() => setCustomRule(null)}
              />
            </div>
            <div className="mt-3 flex items-center justify-end gap-3">
              {ruleAddsUnknown && <span className="text-xs text-muted-foreground">+ os que a regra casar</span>}
              <Button
                type="button"
                disabled={categoryId === null || applyPending}
                onClick={apply}
                aria-label={`Aplicar a categoria a ${applyCount} ${pluralize(applyCount, 'lançamento', 'lançamentos')}`}
              >
                {applyPending ? <LoaderCircle aria-hidden className="h-4 w-4 animate-spin" /> : <Check aria-hidden className="h-4 w-4" />}
                Aplicar a {applyCount}
              </Button>
            </div>
          </>
        )}
      </div>

      <RuleEditorDialog
        open={editorOpen}
        onOpenChange={setEditorOpen}
        initial={alternatives}
        errors={ruleErrors}
        transactions={transactions}
        selectedIds={selectedIds}
        direction={direction}
        categoryId={categoryId}
        partialList={partialList}
        onSave={saveRule}
      />
    </div>
  )
}
