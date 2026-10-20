import { Diff } from 'lucide-react'
import {
  type ComponentProps,
  useCallback,
  useEffect,
  useEffectEvent,
  useLayoutEffect,
  useRef,
  useState,
} from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { formatMoneyDigits, MONEY_INPUT_MAX_DIGITS, moneyDigits, parsePastedMoney } from '@/lib/money'
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

const EMPTY: Mask = { digits: '', negative: false }

function maskFromCents(cents: number | null): Mask {
  if (cents === null) return EMPTY
  return { digits: String(Math.abs(cents)), negative: cents < 0 }
}

function centsFromMask({ digits, negative }: Mask): number | null {
  if (digits === '') return null
  const cents = Number(digits)
  return negative && cents !== 0 ? -cents : cents
}

/** Cada "-" no texto inverte o sinal: um número ímpar deles deixa o valor negativo. */
function hasOddMinus(text: string): boolean {
  return (text.match(/-/g)?.length ?? 0) % 2 === 1
}

function maskFromText(text: string): Mask {
  return { digits: moneyDigits(text), negative: hasOddMinus(text) }
}

/** Tira o último dígito; sem dígito nenhum, tira o "-" que sobrou. */
function dropLastDigit(mask: Mask): Mask {
  return mask.digits === '' ? EMPTY : { ...mask, digits: mask.digits.slice(0, -1) }
}

/**
 * Campo de dinheiro com máscara de centavos: os dígitos entram pela direita ("1" → "0,01",
 * "123" → "1,23"), apagar tira o último dígito e o cursor fica sempre no fim. A edição passa
 * por `beforeinput` (o navegador nunca aplica a mudança dele); `onChange` só cobre o que escapa
 * desse evento. Colar usa `parsePastedMoney`, para "1.234,56" ou "R$ 50" não virarem dígito a dígito.
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
  onBlur,
  placeholder,
  ...props
}: MoneyInputProps) {
  const [mask, setMask] = useState<Mask>(() => maskFromCents(value))
  // Cópia síncrona do estado: dois eventos seguidos antes do próximo render já veem a máscara nova.
  const maskRef = useRef<Mask>(mask)
  // Último valor que este campo emitiu: mudanças vindas de fora (reset do formulário) reescrevem o texto;
  // o eco do que o próprio usuário digitou não.
  const lastEmitted = useRef<number | null>(value)
  const inputRef = useRef<HTMLInputElement | null>(null)
  const { readOnly, disabled } = props

  useLayoutEffect(() => {
    if (value !== lastEmitted.current) {
      lastEmitted.current = value
      maskRef.current = maskFromCents(value)
      setMask(maskRef.current)
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
    const normalized = { digits: next.digits, negative: allowNegative && next.negative }
    maskRef.current = normalized
    setMask(normalized)
    const cents = centsFromMask(normalized)
    if (cents !== lastEmitted.current) {
      lastEmitted.current = cents
      onChange(cents)
    }
  }

  const pasteText = (text: string) => {
    const cents = parsePastedMoney(text)
    if (cents !== null) commit({ digits: String(Math.abs(cents)), negative: cents < 0 })
  }

  const handleBeforeInput = useEffectEvent((event: InputEvent) => {
    const input = inputRef.current
    if (!input || input.readOnly || input.disabled || event.isComposing) return
    const { inputType } = event
    const current = maskRef.current
    const text = input.value
    const start = input.selectionStart ?? text.length
    const end = input.selectionEnd ?? text.length
    const collapsed = start === end
    // O que sobra depois de tirar a seleção: tudo selecionado zera; um trecho sai do texto
    // (um "." apagado não muda os dígitos; um "0" do meio sai do número).
    const base = collapsed ? current : start === 0 && end === text.length ? EMPTY : maskFromText(text.slice(0, start) + text.slice(end))

    if (inputType.startsWith('delete')) {
      event.preventDefault()
      if (!collapsed) commit(base)
      else if (inputType === 'deleteContentBackward' || inputType === 'deleteContentForward') commit(dropLastDigit(current))
      // Apagar palavra/linha a partir do fim (Ctrl/Cmd+Backspace) esvazia o campo.
      else commit(EMPTY)
      return
    }

    if (inputType === 'insertFromPaste' || inputType === 'insertFromDrop' || inputType === 'insertFromYank') {
      event.preventDefault()
      pasteText(event.dataTransfer?.getData('text/plain') ?? event.data ?? '')
      return
    }

    if (inputType === 'insertText' || inputType === 'insertReplacementText') {
      event.preventDefault()
      const typed = event.data ?? event.dataTransfer?.getData('text/plain') ?? ''
      const digits = typed.replace(/\D/g, '')
      const flip = allowNegative && hasOddMinus(typed)
      if (digits === '' && !flip) return
      commit({ digits: moneyDigits(base.digits + digits), negative: base.negative !== flip })
      return
    }

    // Quebra de linha, formatação etc. não fazem sentido num valor. Desfazer/refazer seguem para o onChange.
    if (inputType.startsWith('insert') || inputType.startsWith('format')) event.preventDefault()
  })

  useEffect(() => {
    const input = inputRef.current
    if (!input) return
    const listener = (event: Event) => handleBeforeInput(event as InputEvent)
    input.addEventListener('beforeinput', listener)
    return () => input.removeEventListener('beforeinput', listener)
  }, [])

  const setRefs = useCallback(
    (node: HTMLInputElement | null) => {
      inputRef.current = node
      if (typeof ref === 'function') {
        const cleanup = ref(node)
        return () => {
          inputRef.current = null
          if (typeof cleanup === 'function') cleanup()
          else ref(null)
        }
      }
      if (ref) ref.current = node
      return () => {
        inputRef.current = null
        if (ref) ref.current = null
      }
    },
    [ref],
  )

  return (
    <div className="flex gap-2">
      <div className="relative min-w-0 flex-1">
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
          className={cn('pl-10 text-lg font-semibold tabular-nums', className)}
          onKeyDown={(event) => {
            onKeyDown?.(event)
            // Delete com o cursor no fim não apagaria nada no navegador: aqui ele tira o último dígito.
            // Só tecla física (teclado virtual não tem Delete); o preventDefault cancela o beforeinput.
            const input = event.currentTarget
            if (event.defaultPrevented || readOnly || event.key !== 'Delete' || event.ctrlKey || event.metaKey || event.altKey) return
            if (input.selectionStart !== input.selectionEnd || input.selectionEnd !== input.value.length) return
            event.preventDefault()
            commit(dropLastDigit(maskRef.current))
          }}
          onChange={(event) => {
            // Só para navegador/evento que pula o beforeinput (ex.: desfazer, fim de composição de IME).
            if (readOnly || (event.nativeEvent as InputEvent).isComposing) return
            const input = event.currentTarget
            const raw = input.value
            const current = maskRef.current
            const shown = formatMoneyDigits(current.digits, current.negative)
            const negative = allowNegative && hasOddMinus(raw)
            // Último caractere apagado com o cursor no fim: tira o último dígito, inclusive de "0,00".
            const caretAtEnd = input.selectionStart === input.selectionEnd && input.selectionEnd === raw.length
            const droppedLast =
              caretAtEnd && negative === current.negative && raw.length === shown.length - 1 && shown.startsWith(raw)
            commit(droppedLast ? dropLastDigit(current) : { digits: moneyDigits(raw), negative })
          }}
          onPaste={(event) => {
            onPaste?.(event)
            if (event.defaultPrevented) return
            event.preventDefault()
            if (!readOnly) pasteText(event.clipboardData.getData('text'))
          }}
          onSelect={(event) => {
            onSelect?.(event)
            // Cursor solto no meio do número volta para o fim; uma seleção de verdade (ex.: tudo) fica.
            const input = event.currentTarget
            const end = input.value.length
            if (input.selectionStart === input.selectionEnd && input.selectionStart !== end) input.setSelectionRange(end, end)
          }}
          onBlur={(event) => {
            // "-" sem valor (ou "-0,00") não significa nada: some ao sair do campo.
            const current = maskRef.current
            if (current.negative && (current.digits === '' || current.digits === '0')) commit({ ...current, negative: false })
            onBlur?.(event)
          }}
        />
      </div>
      {allowNegative && (
        <Button
          type="button"
          variant="outline"
          size="icon"
          className="shrink-0 text-muted-foreground aria-pressed:bg-accent aria-pressed:text-foreground"
          aria-label="Valor negativo"
          aria-pressed={mask.negative}
          disabled={disabled || readOnly}
          // Não tira o foco do campo: no celular, o teclado numérico continua aberto.
          onMouseDown={(event) => event.preventDefault()}
          onClick={() => {
            const input = inputRef.current
            // Fora do campo, o clique leva o foco para ele: assim o blur (e o "touched" do formulário) acontece depois.
            if (input && input !== document.activeElement) input.focus()
            commit({ ...maskRef.current, negative: !maskRef.current.negative })
          }}
        >
          <Diff aria-hidden="true" />
        </Button>
      )}
    </div>
  )
}
