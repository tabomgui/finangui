import { useQueryClient } from '@tanstack/react-query'
import { format, parseISO } from 'date-fns'
import { LoaderCircle } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { toast } from 'sonner'
import { ApiError } from '@/api/errors'
import { useApplyRule, useRule, useRulePreview, type PreviewRuleBody } from '@/api/queries/rules'
import { invalidateLedger, invalidateRules } from '@/api/query-keys'
import type { Rule } from '@/api/types'
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Button } from '@/components/ui/button'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { notifyError } from '@/lib/form-errors'
import { pluralize } from './rule-labels'
import { ruleDefaults, toRuleBody } from './rule-form-values'

type ApplyRuleDialogProps = {
  rule: Rule
  /** Formulário com alterações ainda não salvas: a aplicação usaria uma versão velha da regra. */
  disabled: boolean
}

const POLL_INTERVAL_MS = 2_000
const TIMEOUT_MS = 60_000

function formatAppliedAt(value: string): string {
  return format(parseISO(value), "dd/MM/yyyy 'às' HH:mm")
}

type Scope = 'uncategorized' | 'automatic'

const SCOPE_OPTIONS: { value: Scope; label: string }[] = [
  { value: 'uncategorized', label: 'Só os lançamentos sem categoria' },
  { value: 'automatic', label: 'Também corrigir os que foram categorizados sozinhos (pelo histórico, pelo banco ou por outra regra)' },
]

function changedCountLabel(count: number): string {
  return `${count} ${pluralize(count, 'alterado', 'alterados')}`
}

/**
 * Confirma e dispara a aplicação retroativa (job por regra), depois acompanha `last_applied_at`
 * via polling até o job terminar ou até 60s passarem — o que vier primeiro.
 */
export function ApplyRuleDialog({ rule, disabled }: ApplyRuleDialogProps) {
  const [open, setOpen] = useState(false)
  const [scope, setScope] = useState<Scope>('uncategorized')
  const overwrite = scope === 'automatic'
  const [applying, setApplying] = useState(false)
  // Compara com o `last_applied_at` anterior (não com um timestamp absoluto): evita depender do
  // relógio do servidor estar sincronizado com o do navegador.
  const previousLastAppliedAtRef = useRef<string | null>(null)
  const applyingRef = useRef(applying)
  const queryClient = useQueryClient()
  const apply = useApplyRule()

  // Sem `overwrite` quando desligado (o padrão do backend): o mesmo corpo da prévia do editor com a
  // regra salva, então as duas compartilham o cache em vez de pedir a mesma conta duas vezes.
  const previewBody: PreviewRuleBody = useMemo(() => {
    const body = toRuleBody(ruleDefaults(rule))
    return overwrite ? { ...body, overwrite: true } : body
  }, [rule, overwrite])
  // Só com o diálogo aberto: fechado, nem a contagem nem a opção escolhida importam.
  const preview = useRulePreview(previewBody, open && !disabled)
  const { data: polledRule } = useRule(rule.id, applying ? POLL_INTERVAL_MS : undefined)

  useEffect(() => {
    applyingRef.current = applying
  }, [applying])

  // Detecta o fim do job: quando `last_applied_at` muda, pare de sondar e avise. Isso só roda
  // quando o GET de fato traz dados diferentes — por isso o timeout de 60s (abaixo) não pode
  // depender deste efeito: se a resposta nunca mudar, ele nunca executa de novo.
  useEffect(() => {
    if (!applying) return
    if (polledRule?.last_applied_at && polledRule.last_applied_at !== previousLastAppliedAtRef.current) {
      setApplying(false)
      toast.success(`Regra aplicada: ${changedCountLabel(polledRule.last_applied_changes ?? 0)}.`)
      invalidateLedger(queryClient)
      invalidateRules(queryClient)
    }
  }, [applying, polledRule, queryClient])

  // Timeout real, independente de qualquer resposta de rede: garante que `applying` volte a
  // `false` em 60s mesmo que o GET de polling continue devolvendo exatamente o mesmo corpo
  // (nesse caso, o efeito acima nunca dispara de novo).
  useEffect(() => {
    if (!applying) return
    const timer = setTimeout(() => {
      setApplying(false)
      toast('A aplicação continua em segundo plano.')
    }, TIMEOUT_MS)
    return () => clearTimeout(timer)
  }, [applying])

  // Se a página for desmontada (navegação) enquanto o job ainda está em voo, não há mais quem
  // vá notar o fim dele — invalida de uma vez para a próxima visita já vir com dados frescos.
  useEffect(() => {
    return () => {
      if (applyingRef.current) {
        invalidateLedger(queryClient)
        invalidateRules(queryClient)
      }
    }
  }, [queryClient])

  async function confirm() {
    if (applying || apply.isPending) return
    previousLastAppliedAtRef.current = rule.last_applied_at
    setOpen(false)
    setApplying(true)
    try {
      await apply.mutateAsync({ id: rule.id, body: { overwrite } })
    } catch (error) {
      // Já tinha uma aplicação desta regra em voo (ex.: duplo clique, outra aba): o backend
      // rejeitou o despacho, mas o job que já está rodando ainda vai terminar — continua
      // sondando em vez de voltar ao botão normal com um erro assustador.
      if (error instanceof ApiError && error.code === 'rule_apply_in_progress') return
      setApplying(false)
      notifyError(error)
    }
  }

  return (
    <div className="space-y-2">
      <Button
        type="button"
        variant="outline"
        disabled={disabled || applying}
        onClick={() => {
          setScope('uncategorized')
          setOpen(true)
        }}
      >
        {applying && <LoaderCircle className="h-4 w-4 animate-spin" />}
        {applying ? 'Aplicando…' : 'Aplicar às existentes'}
      </Button>

      {disabled && <p className="text-xs text-muted-foreground">Salve a regra antes de aplicar.</p>}

      {rule.last_applied_at && (
        <p className="text-xs text-muted-foreground">
          Aplicada em {formatAppliedAt(rule.last_applied_at)} · {changedCountLabel(rule.last_applied_changes ?? 0)}.
        </p>
      )}

      <AlertDialog open={open} onOpenChange={setOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Aplicar às existentes?</AlertDialogTitle>
            <AlertDialogDescription>Escolha quais lançamentos já existentes a regra pode alterar.</AlertDialogDescription>
          </AlertDialogHeader>

          <RadioGroup
            value={scope}
            onValueChange={(value) => setScope(value as Scope)}
            aria-label="Quais lançamentos"
            className="gap-2"
          >
            {SCOPE_OPTIONS.map((option) => (
              <label
                key={option.value}
                className="flex cursor-pointer items-start gap-3 rounded-xl border border-border p-3 has-[[data-state=checked]]:border-primary"
              >
                <RadioGroupItem value={option.value} className="mt-0.5" />
                <span className="text-sm">{option.label}</span>
              </label>
            ))}
          </RadioGroup>
          <div className="space-y-1">
            <p className="text-xs text-muted-foreground">Os que você categorizou à mão nunca mudam.</p>
            <p className="text-sm font-medium" aria-live="polite">
              {preview.isPending || preview.isPlaceholderData || preview.data === undefined
                ? 'Calculando quantos lançamentos seriam alterados…'
                : `Isso vai alterar cerca de ${preview.data.changed} ${pluralize(preview.data.changed, 'lançamento', 'lançamentos')}.`}
            </p>
          </div>

          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <Button type="button" onClick={confirm}>
              Aplicar
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
