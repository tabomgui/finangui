import { LoaderCircle, TriangleAlert } from 'lucide-react'
import { useRef, useState } from 'react'
import { flushSync } from 'react-dom'
import { toast } from 'sonner'
import { type UpdateProviderCategoriesVariables, useUpdateProviderCategories } from '@/api/queries/auth'
import { useProviderCategorizedCount } from '@/api/queries/categorization'
import type { User } from '@/api/types'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
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
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import { notifyError } from '@/lib/form-errors'

const NO_COMEBACK = 'Categorias removidas não voltam se você ligar a opção de novo.'

function transactionsLabel(count: number): string {
  return count === 1 ? '1 lançamento' : `${count} lançamentos`
}

function clearedMessage(cleared: number): string {
  if (cleared === 0) return 'Nenhum lançamento tinha categoria do banco.'
  return cleared === 1 ? '1 lançamento ficou sem categoria.' : `${cleared} lançamentos ficaram sem categoria.`
}

function resultMessage(variables: UpdateProviderCategoriesVariables, cleared: number, wasEnabled: boolean): string {
  if (variables.use_provider_categories) return 'Categorias do banco ativadas.'
  // Já desligada: a chamada foi só a limpeza.
  if (!wasEnabled && variables.clear_provider_categories) return clearedMessage(cleared)
  const turnedOff = 'Categorias do banco desativadas.'
  return variables.clear_provider_categories ? `${turnedOff} ${clearedMessage(cleared)}` : turnedOff
}

/** Texto do diálogo de desligar: concorda em número com a contagem; sem contagem, genérico. */
function turnOffDescription(count: number | undefined): string {
  if (count === undefined) {
    return 'Alguns lançamentos podem estar com a categoria que veio do banco. Quer remover essas categorias também? Esses lançamentos ficam sem categoria.'
  }
  if (count === 1) {
    return '1 lançamento está com a categoria que veio do banco. Quer remover essa categoria também? Esse lançamento fica sem categoria.'
  }
  return `${count} lançamentos estão com a categoria que veio do banco. Quer remover essas categorias também? Esses lançamentos ficam sem categoria.`
}

function clearDescription(count: number): string {
  return count === 1
    ? '1 lançamento fica sem categoria. Categorias dadas por você, por regra ou pelo histórico não mudam.'
    : `${count} lançamentos ficam sem categoria. Categorias dadas por você, por regra ou pelo histórico não mudam.`
}

type TurnOffAction = 'keep' | 'clear'

/**
 * Card "Categorias do banco" em Configurações, logo abaixo do card da Pluggy: liga/desliga o uso da
 * categoria informada pelo banco na sincronização (`use_provider_categories`). Ao desligar, refaz a
 * contagem de lançamentos categorizados pelo banco e, se houver algum (ou se a contagem falhar),
 * pergunta se remove essas categorias também; com a opção já desligada e ainda sobrando lançamentos
 * assim (ex.: de antes da opção existir), oferece a mesma limpeza à parte. A contagem e a limpeza
 * vêm prontas da API.
 */
export function ProviderCategoriesCard({ user }: { user: User }) {
  const update = useUpdateProviderCategories()
  const { data: countData, isError: countFailed, refetch: refetchCount } = useProviderCategorizedCount()
  const [checking, setChecking] = useState(false)
  const [turnOffCount, setTurnOffCount] = useState<number | undefined>(undefined)
  const [confirmingOff, setConfirmingOff] = useState(false)
  const [confirmingClear, setConfirmingClear] = useState(false)
  const [pendingAction, setPendingAction] = useState<TurnOffAction | null>(null)
  const switchRef = useRef<HTMLButtonElement>(null)
  const clearButtonRef = useRef<HTMLButtonElement>(null)
  const keepButtonRef = useRef<HTMLButtonElement>(null)
  const removeButtonRef = useRef<HTMLButtonElement>(null)
  // Depois de limpar, o botão "Remover categorias" do card some (não sobra nada para limpar): o
  // foco volta para o interruptor em vez de para um botão que não existe mais.
  const clearSucceeded = useRef(false)

  const enabled = user.use_provider_categories
  const providerCount = countData?.count
  const busy = update.isPending || checking

  async function save(variables: UpdateProviderCategoriesVariables) {
    const { cleared } = await update.mutateAsync(variables)
    toast.success(resultMessage(variables, cleared, enabled))
  }

  async function saveOrNotify(variables: UpdateProviderCategoriesVariables) {
    try {
      await save(variables)
    } catch (error) {
      notifyError(error)
    }
  }

  async function onCheckedChange(checked: boolean) {
    // O interruptor nunca fica `disabled` (perderia o foco do teclado no meio da chamada): só
    // ignora um clique enquanto a anterior não termina.
    if (busy) return
    if (checked) {
      await saveOrNotify({ use_provider_categories: true })
      return
    }
    // Contagem refeita na hora: a do cache pode ser de antes de uma sincronização recente.
    setChecking(true)
    const fresh = await refetchCount()
    setChecking(false)
    const count = fresh.isError ? undefined : fresh.data?.count
    // Sem nada categorizado pelo banco não há o que perguntar; contagem desconhecida (erro)
    // pergunta mesmo assim, com um texto genérico.
    if (count === 0) {
      await saveOrNotify({ use_provider_categories: false })
      return
    }
    setTurnOffCount(count)
    setConfirmingOff(true)
  }

  async function turnOff(action: TurnOffAction) {
    setPendingAction(action)
    try {
      await save({ use_provider_categories: false, clear_provider_categories: action === 'clear' })
      setPendingAction(null)
      setConfirmingOff(false)
    } catch (error) {
      notifyError(error)
      // O botão clicado ficou desabilitado durante a chamada e perdeu o foco: reabilita já e
      // devolve o foco a ele, com o diálogo ainda aberto.
      flushSync(() => setPendingAction(null))
      ;(action === 'keep' ? keepButtonRef : removeButtonRef).current?.focus()
    }
  }

  async function clearOnly() {
    await save({ use_provider_categories: false, clear_provider_categories: true })
    clearSucceeded.current = true
  }

  return (
    <Card className="rounded-2xl shadow-card">
      <CardHeader>
        <CardTitle>Categorias do banco</CardTitle>
        <CardDescription>Como os lançamentos da sincronização bancária são categorizados.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0 space-y-1">
            <label htmlFor="use-provider-categories" className="text-sm font-medium">
              Usar categorias do banco
            </label>
            <p id="use-provider-categories-hint" className="text-xs text-muted-foreground">
              Ligado, a categoria informada pelo banco entra depois das suas regras e do histórico. Desligado, os
              lançamentos novos da sincronização são categorizados só pelas suas regras e pelo histórico; o resto vai
              para Categorizar.
            </p>
          </div>
          <Switch
            ref={switchRef}
            id="use-provider-categories"
            aria-describedby="use-provider-categories-hint"
            checked={enabled}
            aria-busy={busy}
            onCheckedChange={(checked) => void onCheckedChange(checked)}
            className="mt-0.5"
          />
        </div>

        {!enabled && countFailed && (
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-destructive/10 px-4 py-3 text-sm text-destructive">
            <span className="flex items-center gap-2">
              <TriangleAlert className="h-4 w-4 shrink-0" aria-hidden="true" />
              Não foi possível contar os lançamentos categorizados pelo banco.
            </span>
            <Button variant="outline" size="sm" onClick={() => void refetchCount()}>
              Tentar de novo
            </Button>
          </div>
        )}

        {!enabled && !countFailed && providerCount !== undefined && providerCount > 0 && (
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-muted/50 px-4 py-3">
            <p className="min-w-0 text-sm text-muted-foreground">
              {transactionsLabel(providerCount)} ainda com a categoria do banco.
            </p>
            <Button
              ref={clearButtonRef}
              variant="outline"
              size="sm"
              className="text-destructive"
              disabled={busy}
              onClick={() => {
                clearSucceeded.current = false
                setConfirmingClear(true)
              }}
            >
              Remover categorias
            </Button>
          </div>
        )}
      </CardContent>

      <AlertDialog open={confirmingOff} onOpenChange={(next) => pendingAction === null && setConfirmingOff(next)}>
        <AlertDialogContent
          onCloseAutoFocus={(event) => {
            event.preventDefault()
            switchRef.current?.focus()
          }}
        >
          <AlertDialogHeader>
            <AlertDialogTitle>Desligar categorias do banco?</AlertDialogTitle>
            <AlertDialogDescription>{turnOffDescription(turnOffCount)}</AlertDialogDescription>
            <AlertDialogDescription>{NO_COMEBACK}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={pendingAction !== null}>Cancelar</AlertDialogCancel>
            <Button
              ref={keepButtonRef}
              variant="outline"
              disabled={pendingAction !== null}
              onClick={() => void turnOff('keep')}
            >
              {pendingAction === 'keep' && <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden="true" />}
              Só desligar
            </Button>
            <Button
              ref={removeButtonRef}
              variant="destructive"
              disabled={pendingAction !== null}
              onClick={() => void turnOff('clear')}
            >
              {pendingAction === 'clear' && <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden="true" />}
              Desligar e remover
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <ConfirmDialog
        open={confirmingClear}
        onOpenChange={setConfirmingClear}
        title="Remover categorias do banco?"
        description={`${clearDescription(providerCount ?? 0)} ${NO_COMEBACK}`}
        confirmLabel="Remover categorias"
        destructive
        onConfirm={clearOnly}
        onCloseAutoFocus={(event) => {
          event.preventDefault()
          ;(clearSucceeded.current ? switchRef : clearButtonRef).current?.focus()
        }}
      />
    </Card>
  )
}
