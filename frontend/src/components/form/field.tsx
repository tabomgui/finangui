import { cloneElement, type AriaAttributes, type ReactElement, type ReactNode } from 'react'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'

type ElementControlProps = Pick<AriaAttributes, 'aria-describedby' | 'aria-invalid'>

export type FieldControlProps = {
  id: string
  'aria-describedby'?: string
  'aria-invalid'?: true
}

type FieldProps = {
  /** Normalmente um texto; aceita nó (ex.: um sufixo `sr-only` que desambigua campos repetidos numa lista). */
  label: ReactNode
  htmlFor: string
  error?: string
  hint?: string
  className?: string
  /**
   * Um elemento (recebe aria-describedby/aria-invalid por clone) ou uma função que recebe
   * id e atributos para aplicar no controle certo — necessário em controles compostos,
   * como o gatilho de um Select ou de um combobox.
   */
  children: ReactElement<ElementControlProps> | ((control: FieldControlProps) => ReactNode)
}

export function Field({ label, htmlFor, error, hint, className, children }: FieldProps) {
  const message = error ?? hint
  const messageId = `${htmlFor}-message`

  let control: ReactNode
  if (typeof children === 'function') {
    control = children({
      id: htmlFor,
      ...(message ? { 'aria-describedby': messageId } : {}),
      ...(error ? { 'aria-invalid': true as const } : {}),
    })
  } else {
    control = message
      ? cloneElement(children, {
          'aria-describedby': messageId,
          'aria-invalid': error ? true : children.props['aria-invalid'],
        })
      : children
  }

  return (
    <div className={cn('space-y-2', className)}>
      <Label htmlFor={htmlFor}>{label}</Label>
      {control}
      {error ? (
        <p id={messageId} role="alert" className="text-sm text-destructive">
          {error}
        </p>
      ) : hint ? (
        <p id={messageId} className="text-xs text-muted-foreground">
          {hint}
        </p>
      ) : null}
    </div>
  )
}
