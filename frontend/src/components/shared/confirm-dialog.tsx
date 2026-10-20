import { LoaderCircle } from 'lucide-react'
import { useRef, useState } from 'react'
import { flushSync } from 'react-dom'
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
import { notifyError } from '@/lib/form-errors'

type ConfirmDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description?: string
  confirmLabel?: string
  cancelLabel?: string
  destructive?: boolean
  /** Pode ser assíncrono; o diálogo fica em estado de carregamento até terminar. */
  onConfirm: () => Promise<void> | void
  /**
   * Ao fechar: sem um `AlertDialogTrigger` (diálogo controlado só por `open`), o Radix não tem para
   * onde devolver o foco — quem abre o diálogo por um botão passa aqui para onde ele deve voltar.
   */
  onCloseAutoFocus?: (event: Event) => void
}

export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel = 'Confirmar',
  cancelLabel = 'Cancelar',
  destructive = false,
  onConfirm,
  onCloseAutoFocus,
}: ConfirmDialogProps) {
  const [pending, setPending] = useState(false)
  const confirmRef = useRef<HTMLButtonElement>(null)

  async function handleConfirm() {
    setPending(true)
    try {
      await onConfirm()
      setPending(false)
      onOpenChange(false)
    } catch (error) {
      notifyError(error)
      // O botão ficou desabilitado durante a chamada e perdeu o foco: reabilita já (flushSync) e
      // devolve o foco a ele, para o teclado não cair no <body> com o diálogo ainda aberto.
      flushSync(() => setPending(false))
      confirmRef.current?.focus()
    }
  }

  return (
    <AlertDialog open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
      <AlertDialogContent onCloseAutoFocus={onCloseAutoFocus}>
        <AlertDialogHeader>
          <AlertDialogTitle>{title}</AlertDialogTitle>
          {description && <AlertDialogDescription>{description}</AlertDialogDescription>}
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={pending}>{cancelLabel}</AlertDialogCancel>
          <Button
            ref={confirmRef}
            variant={destructive ? 'destructive' : 'default'}
            disabled={pending}
            onClick={handleConfirm}
          >
            {pending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            {confirmLabel}
          </Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
