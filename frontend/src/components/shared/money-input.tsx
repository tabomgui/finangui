import { type ComponentProps, useEffect, useRef, useState } from 'react'
import { Input } from '@/components/ui/input'
import { centsToInputString, parseMoneyInput } from '@/lib/money'
import { cn } from '@/lib/utils'

type MoneyInputProps = Omit<ComponentProps<typeof Input>, 'value' | 'onChange' | 'type' | 'defaultValue'> & {
  value: number | null
  onChange: (cents: number | null) => void
  currencySymbol?: string
}

export function MoneyInput({ value, onChange, currencySymbol = 'R$', className, onBlur, ...props }: MoneyInputProps) {
  const [text, setText] = useState(() => (value === null ? '' : centsToInputString(value)))
  // Último valor que este campo emitiu: mudanças vindas de fora (reset do formulário) reescrevem o texto;
  // o eco do que o próprio usuário digitou não.
  const lastEmitted = useRef<number | null>(value)

  useEffect(() => {
    if (value !== lastEmitted.current) {
      lastEmitted.current = value
      setText(value === null ? '' : centsToInputString(value))
    }
  }, [value])

  return (
    <div className="relative">
      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm font-medium text-muted-foreground">
        {currencySymbol}
      </span>
      <Input
        {...props}
        type="text"
        inputMode="decimal"
        autoComplete="off"
        placeholder={props.placeholder ?? '0,00'}
        value={text}
        className={cn('pl-10 text-lg font-semibold tabular-nums', className)}
        onChange={(event) => {
          const cents = parseMoneyInput(event.target.value)
          setText(event.target.value)
          lastEmitted.current = cents
          onChange(cents)
        }}
        onBlur={(event) => {
          const cents = parseMoneyInput(text)
          if (cents !== null) setText(centsToInputString(cents))
          onBlur?.(event)
        }}
      />
    </div>
  )
}
