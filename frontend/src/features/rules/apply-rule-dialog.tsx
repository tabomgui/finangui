import { useQueryClient } from '@tanstack/react-query'
import { format, parseISO } from 'date-fns'
import { LoaderCircle } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { toast } from 'sonner'
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
import { Switch } from '@/components/ui/switch'
import { notifyError } from '@/lib/form-errors'
import { ruleDefaults, toRuleBody } from './rule-form-values'

type ApplyRuleDialogProps = {
  rule: Rule
  overwrite: boolean
  onOverwriteChange: (value: boolean) => void
  /** Formulário com alterações ainda não salvas: a aplicação usaria uma versão velha da regra. */
  disabled: boolean
}

const POLL_INTERVAL_MS = 2_000
const TIMEOUT_MS = 60_000

function formatAppliedAt(value: string): string {
  return format(parseISO(value), "dd/MM/yyyy 'às' HH:mm")
}

/**
 * Confirma e dispara a aplicação retroativa (job por regra), depois acompanha `last_applied_at`
 * via polling até o job terminar ou até 60s passarem — o que vier primeiro.
 */
export function ApplyRuleDialog({ rule, overwrite, onOverwriteChange, disabled }: ApplyRuleDialogProps) {
  const [open, setOpen] = useState(false)
  const [applying, setApplying] = useState(false)
  // Compara com o `last_applied_at` anterior (não com um timestamp absoluto): evita depender do
  // relógio do servidor estar sincronizado com o do navegador.
  const previousLastAppliedAtRef = useRef<string | null>(null)
  const startedAtRef = useRef<number | null>(null)
  const queryClient = useQueryClient()
  const apply = useApplyRule()

  const previewBody: PreviewRuleBody = useMemo(() => ({ ...toRuleBody(ruleDefaults(rule)), overwrite }), [rule, overwrite])
  const preview = useRulePreview(previewBody, !disabled)
  const { data: polledRule } = useRule(rule.id, applying ? POLL_INTERVAL_MS : undefined)

  useEffect(() => {
    if (!applying) return

    if (polledRule?.last_applied_at && polledRule.last_applied_at !== previousLastAppliedAtRef.current) {
      setApplying(false)
      toast.success(`Regra aplicada: ${polledRule.last_applied_changes ?? 0} lançamentos alterados.`)
      invalidateLedger(queryClient)
      invalidateRules(queryClient)
      return
    }

    if (startedAtRef.current !== null && Date.now() - startedAtRef.current >= TIMEOUT_MS) {
      setApplying(false)
      toast('A aplicação continua em segundo plano.')
    }
  }, [applying, polledRule, queryClient])

  async function confirm() {
    previousLastAppliedAtRef.current = rule.last_applied_at
    startedAtRef.current = Date.now()
    setOpen(false)
    setApplying(true)
    try {
      await apply.mutateAsync({ id: rule.id, body: { overwrite } })
    } catch (error) {
      setApplying(false)
      notifyError(error)
    }
  }

  return (
    <div className="space-y-2">
      <Button type="button" variant="outline" disabled={disabled || applying} onClick={() => setOpen(true)}>
        {applying && <LoaderCircle className="h-4 w-4 animate-spin" />}
        {applying ? 'Aplicando…' : 'Aplicar às existentes'}
      </Button>

      {disabled && <p className="text-xs text-muted-foreground">Salve a regra antes de aplicar.</p>}

      {rule.last_applied_at && (
        <p className="text-xs text-muted-foreground">
          Aplicada em {formatAppliedAt(rule.last_applied_at)} · {rule.last_applied_changes ?? 0} alterados.
        </p>
      )}

      <AlertDialog open={open} onOpenChange={setOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Aplicar às existentes?</AlertDialogTitle>
            <AlertDialogDescription>Isso vai alterar cerca de {preview.data?.changed ?? 0} lançamentos.</AlertDialogDescription>
          </AlertDialogHeader>

          <div className="flex items-start gap-3 rounded-xl border border-border p-3">
            <Switch id="apply-rule-overwrite" checked={overwrite} onCheckedChange={onOverwriteChange} />
            <div className="space-y-1">
              <label htmlFor="apply-rule-overwrite" className="text-sm font-medium">
                Sobrescrever categorias existentes
              </label>
              <p className="text-xs text-muted-foreground">Categorias definidas à mão nunca são sobrescritas.</p>
            </div>
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
