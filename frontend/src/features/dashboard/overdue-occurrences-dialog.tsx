import { Check, Hourglass, X } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { useOverdueOccurrences, useSkipOccurrence } from '@/api/queries/recurrences'
import type { Transaction } from '@/api/types'
import { EmptyState } from '@/components/shared/empty-state'
import { MoneyText } from '@/components/shared/money-text'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/date'
import { notifyError } from '@/lib/form-errors'
import { ConfirmOccurrenceDialog } from './confirm-occurrence-dialog'

type OverdueOccurrencesDialogProps = { open: boolean; onOpenChange: (open: boolean) => void }

/**
 * Lista das previstas de recorrência já atrasadas ("não aconteceu?"), com as ações "Aconteceu"
 * (abre `ConfirmOccurrenceDialog`) e "Não aconteceu" (pula direto, sem confirmação extra: a
 * recorrência continua gerando as próximas).
 */
export function OverdueOccurrencesDialog({ open, onOpenChange }: OverdueOccurrencesDialogProps) {
  const { data, isPending } = useOverdueOccurrences()
  const skip = useSkipOccurrence()
  const [confirming, setConfirming] = useState<Transaction | null>(null)
  // `skip` é um único hook de mutação compartilhado por todas as linhas: sem isto, `skip.isPending`
  // desabilitaria os botões de toda a lista enquanto qualquer uma pula, não só a linha em voo.
  const skippingId = skip.isPending ? skip.variables : undefined

  async function handleSkip(transaction: Transaction) {
    try {
      await skip.mutateAsync(transaction.id)
      toast.success(`"${transaction.description}" marcado como não aconteceu.`)
    } catch (error) {
      notifyError(error)
    }
  }

  const occurrences = data ?? []

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Lançamentos previstos não confirmados</DialogTitle>
            <DialogDescription>Já passaram da data prevista. Confirme se aconteceram ou pule.</DialogDescription>
          </DialogHeader>
          <div className="max-h-[60vh] space-y-2 overflow-y-auto">
            {isPending ? (
              [0, 1, 2].map((i) => <Skeleton key={i} className="h-24 w-full rounded-xl" />)
            ) : occurrences.length === 0 ? (
              <EmptyState icon={Hourglass} title="Nenhum lançamento previsto pendente." />
            ) : (
              occurrences.map((transaction) => {
                const pending = skippingId === transaction.id
                return (
                  <div key={transaction.id} className="space-y-2 rounded-xl border p-3">
                    <div className="flex items-center justify-between gap-3">
                      <p className="truncate font-medium">{transaction.description}</p>
                      <MoneyText cents={transaction.amount} direction={transaction.direction} className="font-semibold" />
                    </div>
                    <p className="text-xs text-muted-foreground">
                      {transaction.account?.name} · Previsto para {formatDate(transaction.date)}
                    </p>
                    <div className="flex justify-end gap-2">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={pending}
                        aria-label={`Não aconteceu: ${transaction.description}`}
                        onClick={() => handleSkip(transaction)}
                      >
                        <X className="h-4 w-4" />
                        Não aconteceu
                      </Button>
                      <Button
                        type="button"
                        size="sm"
                        disabled={pending}
                        aria-label={`Aconteceu: ${transaction.description}`}
                        onClick={() => setConfirming(transaction)}
                      >
                        <Check className="h-4 w-4" />
                        Aconteceu
                      </Button>
                    </div>
                  </div>
                )
              })
            )}
          </div>
        </DialogContent>
      </Dialog>
      <ConfirmOccurrenceDialog transaction={confirming} onOpenChange={(next) => !next && setConfirming(null)} />
    </>
  )
}
