import { cloneElement, type AriaAttributes, type ReactElement } from 'react'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'

type ControlProps = Pick<AriaAttributes, 'aria-describedby' | 'aria-invalid'>

type FieldProps = {
  label: string
  htmlFor: string
  error?: string
  hint?: string
  className?: string
  children: ReactElement<ControlProps>
}

export function Field({ label, htmlFor, error, hint, className, children }: FieldProps) {
  const message = error ?? hint
  const messageId = `${htmlFor}-message`

  const control = message
    ? cloneElement(children, {
        'aria-describedby': messageId,
        'aria-invalid': error ? true : children.props['aria-invalid'],
      })
    : children

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
