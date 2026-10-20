import { Diff } from 'lucide-react'
import { type ComponentProps, type Ref, useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { formatMoneyDigits, MONEY_INPUT_MAX_DIGITS, moneyDigits, parseMoneyInput } from '@/lib/money'
import { cn } from '@/lib/utils'

type MoneyInputProps = Omit<ComponentProps<typeof Input>, 'value' | 'onChange' | 'type' | 'defaultValue'> & {
  value: number | null
  onChange: (cents: number | null) => void
  currencySymbol?: string
  /** Quando false (padrão), "-" é ignorado. Quando true, "-" inverte o sinal e aparece um botão de sinal. */
  allowNegative?: boolean
}

/** Estado da máscara: os dígitos digitados (lidos como centavos) e o sinal, separados. */
type Mask = { digits: string; negative: boolean }

function maskFromCents(cents: number | null): Mask {
  if (cents === null) return { digits: '', negative: false }
  return { digits: String(Math.abs(cents)), negative: cents < 0 }
}

function centsFromMask({ digits, negative }: Mask): number | null {
  if (digits === '') return null
  const cents = Number(digits)
  return negative && cents !== 0 ? -cents : cents
}

/** Cada "-" no texto inverte o sinal: um número ímpar deles deixa o valor negativo. */
function hasOddMinus(raw: string): boolean {
  return (raw.match(/-/g)?.length ?? 0) % 2 === 1
}

function assignRef<T>(ref: Ref<T> | undefined, node: T | null) {
  if (typeof ref === 'function') ref(node)
  else if (ref) ref.current = node
}

/**
 * Campo de dinheiro com máscara de centavos: os dígitos entram pela direita ("1" → "0,01",
 * "123" → "1,23"), Backspace/Delete tiram o último dígito e o cursor fica sempre no fim.
 * Colar passa por `parseMoneyInput`, para "1.234,56" ou "R$ 50" não virarem dígito a dígito.
 */
export function MoneyInput({
  value,
  onChange,
  currencySymbol = 'R$',
  className,
  allowNegative = false,
  ref,
  onKeyDown,
  onPaste,
  onSelect,
  placeholder,
  ...props
}: MoneyInputProps) {
  const [mask, setMask] = useState<Mask>(() => maskFromCents(value))
  // Último valor que este campo emitiu: mudanças vindas de fora (reset do formulário) reescrevem o texto;
  // o eco do que o próprio usuário digitou não.
  const lastEmitted = useRef<number | null>(value)
  const inputRef = useRef<HTMLInputElement | null>(null)

  useEffect(() => {
    if (value !== lastEmitted.current) {
      lastEmitted.current = value
      setMask(maskFromCents(value))
    }
  }, [value])

  const display = formatMoneyDigits(mask.digits, mask.negative)

  // Depois de cada mudança, o cursor volta para o fim (onde os dígitos entram).
  useLayoutEffect(() => {
    const input = inputRef.current
    if (input && input === document.activeElement) input.setSelectionRange(display.length, display.length)
  }, [display])

  const commit = (next: Mask) => {
    if (next.digits.length > MONEY_INPUT_MAX_DIGITS) return
    const negative = allowNegative && next.negative
    setMask({ digits: next.digits, negative })
    const cents = centsFromMask({ digits: next.digits, negative })
    if (cents !== lastEmitted.current) {
      lastEmitted.current = cents
      onChange(cents)
    }
  }

  const setRefs = useCallback(
    (node: HTMLInputElement | null) => {
      inputRef.current = node
      assignRef(ref, node)
    },
    [ref],
  )

  const blocked = props.disabled || props.readOnly

  return (
    <div className="relative">
      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm font-medium text-muted-foreground">
        {currencySymbol}
      </span>
      <Input
        {...props}
        ref={setRefs}
        type="text"
        inputMode="numeric"
        autoComplete="off"
        placeholder={placeholder ?? '0,00'}
        value={display}
        className={cn('pl-10 text-lg font-semibold tabular-nums', allowNegative && 'pr-11', className)}
        onKeyDown={(event) => {
          onKeyDown?.(event)
          if (event.defaultPrevented) return
          const { key, currentTarget, ctrlKey, metaKey, altKey } = event
          if ((key !== 'Backspace' && key !== 'Delete') || ctrlKey || metaKey || altKey) return
          // Com texto selecionado, deixa o navegador apagar a seleção (ex.: tudo selecionado limpa o campo).
          if (currentTarget.selectionStart !== currentTarget.selectionEnd) return
          event.preventDefault()
          if (mask.digits !== '') commit({ ...mask, digits: mask.digits.slice(0, -1) })
          else if (mask.negative) commit({ digits: '', negative: false })
        }}
        onChange={(event) => {
          const raw = event.target.value
          const negative = allowNegative && hasOddMinus(raw)
          // Um caractere a menos, mesmo sinal e (fim apagado ou só um separador sumiu): tira o último dígito.
          // Sem isso, "0,00" menos o último zero ainda tem os mesmos dígitos e nunca esvaziaria.
          const removedOne =
            raw.length === display.length - 1 &&
            negative === mask.negative &&
            (display.startsWith(raw) || moneyDigits(raw) === mask.digits)
          commit({ digits: removedOne ? mask.digits.slice(0, -1) : moneyDigits(raw), negative })
        }}
        onPaste={(event) => {
          onPaste?.(event)
          if (event.defaultPrevented) return
          event.preventDefault()
          const text = event.clipboardData.getData('text')
          const parsed = parseMoneyInput(text)
          if (parsed !== null) {
            commit({ digits: String(Math.abs(parsed)), negative: parsed < 0 })
            return
          }
          const digits = moneyDigits(text)
          if (digits !== '') commit({ digits, negative: hasOddMinus(text) })
        }}
        onSelect={(event) => {
          onSelect?.(event)
          // Cursor solto no meio do número volta para o fim; uma seleção de verdade (ex.: tudo) fica.
          const input = event.currentTarget
          const end = input.value.length
          if (input.selectionStart === input.selectionEnd && input.selectionStart !== end) input.setSelectionRange(end, end)
        }}
      />
      {allowNegative && (
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          className="absolute right-0.5 top-1/2 -translate-y-1/2 text-muted-foreground aria-pressed:text-foreground"
          aria-label="Valor negativo"
          aria-pressed={mask.negative}
          disabled={blocked}
          // Não tira o foco do campo: no celular, o teclado numérico continua aberto.
          onMouseDown={(event) => event.preventDefault()}
          onClick={() => commit({ ...mask, negative: !mask.negative })}
        >
          <Diff aria-hidden="true" />
        </Button>
      )}
    </div>
  )
}
