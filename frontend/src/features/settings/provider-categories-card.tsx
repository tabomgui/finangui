import { LoaderCircle } from 'lucide-react'
import { useState } from 'react'
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

function transactionsLabel(count: number): string {
  return count === 1 ? '1 lançamento' : `${count} lançamentos`
}

function clearedMessage(cleared: number): string {
  if (cleared === 0) return 'Nenhum lançamento tinha categoria do banco.'
  return cleared === 1 ? '1 lançamento voltou para Categorizar.' : `${cleared} lançamentos voltaram para Categorizar.`
}

function resultMessage(variables: UpdateProviderCategoriesVariables, cleared: number, wasEnabled: boolean): string {
  if (variables.use_provider_categories) return 'Categorias do banco ativadas.'
  // Já desligada: a chamada foi só a limpeza.
  if (!wasEnabled && variables.clear_provider_categories) return clearedMessage(cleared)
  const turnedOff = 'Categorias do banco desativadas.'
  return variables.clear_provider_categories ? `${turnedOff} ${clearedMessage(cleared)}` : turnedOff
}

type PendingAction = 'keep' | 'clear' | null

/**
 * Card "Categorias do banco" em Configurações, logo abaixo do card da Pluggy: liga/desliga o uso da
 * categoria informada pelo banco na sincronização (`use_provider_categories`). Ao desligar com
 * lançamentos já categorizados pelo banco, pergunta se remove essas categorias também; com a opção
 * já desligada e ainda sobrando lançamentos assim (ex.: de antes da opção existir), oferece a mesma
 * limpeza à parte. A contagem e a limpeza vêm prontas da API.
 */
export function ProviderCategoriesCard({ user }: { user: User }) {
  const update = useUpdateProviderCategories()
  const { data: countData } = useProviderCategorizedCount()
  const [confirmingOff, setConfirmingOff] = useState(false)
  const [confirmingClear, setConfirmingClear] = useState(false)
  const [pendingAction, setPendingAction] = useState<PendingAction>(null)

  const enabled = user.use_provider_categories
  const providerCount = countData?.count

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

  function onCheckedChange(checked: boolean) {
    if (checked) {
      void saveOrNotify({ use_provider_categories: true })
      return
    }
    // Sem nada categorizado pelo banco não há o que perguntar; contagem ainda desconhecida
    // (carregando ou com erro) pergunta mesmo assim, com um texto genérico.
    if (providerCount === 0) {
      void saveOrNotify({ use_provider_categories: false })
      return
    }
    setConfirmingOff(true)
  }

  async function turnOff(action: Exclude<PendingAction, null>) {
    setPendingAction(action)
    try {
      await save({ use_provider_categories: false, clear_provider_categories: action === 'clear' })
      setConfirmingOff(false)
    } catch (error) {
      notifyError(error)
    } finally {
      setPendingAction(null)
    }
  }

  const offDescription =
    providerCount !== undefined
      ? `${transactionsLabel(providerCount)} ${providerCount === 1 ? 'está' : 'estão'} com a categoria que veio do banco. Quer remover essas categorias também? Esses lançamentos voltam para Categorizar.`
      : 'Alguns lançamentos podem estar com a categoria que veio do banco. Quer remover essas categorias também? Esses lançamentos voltam para Categorizar.'

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
              Quando desligado, os lançamentos novos da sincronização são categorizados só pelas suas regras e pelo
              histórico; o resto vai para Categorizar.
            </p>
          </div>
          <Switch
            id="use-provider-categories"
            aria-describedby="use-provider-categories-hint"
            checked={enabled}
            disabled={update.isPending}
            onCheckedChange={onCheckedChange}
            className="mt-0.5"
          />
        </div>

        {!enabled && providerCount !== undefined && providerCount > 0 && (
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-muted/50 px-4 py-3">
            <p className="min-w-0 text-sm text-muted-foreground">
              {transactionsLabel(providerCount)} ainda com a categoria do banco.
            </p>
            <Button variant="outline" size="sm" disabled={update.isPending} onClick={() => setConfirmingClear(true)}>
              Remover categorias
            </Button>
          </div>
        )}
      </CardContent>

      <AlertDialog open={confirmingOff} onOpenChange={(next) => pendingAction === null && setConfirmingOff(next)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Desligar categorias do banco?</AlertDialogTitle>
            <AlertDialogDescription>{offDescription}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={pendingAction !== null}>Cancelar</AlertDialogCancel>
            <Button variant="outline" disabled={pendingAction !== null} onClick={() => void turnOff('keep')}>
              {pendingAction === 'keep' && <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden="true" />}
              Manter categorias
            </Button>
            <Button variant="destructive" disabled={pendingAction !== null} onClick={() => void turnOff('clear')}>
              {pendingAction === 'clear' && <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden="true" />}
              Remover categorias
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <ConfirmDialog
        open={confirmingClear}
        onOpenChange={setConfirmingClear}
        title="Remover categorias do banco?"
        description={`${transactionsLabel(providerCount ?? 0)} ${providerCount === 1 ? 'fica' : 'ficam'} sem categoria e ${providerCount === 1 ? 'volta' : 'voltam'} para Categorizar. Categorias dadas por você, por regra ou pelo histórico não mudam.`}
        confirmLabel="Remover categorias"
        destructive
        onConfirm={() => save({ use_provider_categories: false, clear_provider_categories: true })}
      />
    </Card>
  )
}
