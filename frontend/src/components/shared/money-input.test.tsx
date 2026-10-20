import { act, fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createRef, useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { MoneyInput } from './money-input'

function setup(props: { value?: number | null; allowNegative?: boolean } = {}) {
  const onChange = vi.fn()
  const user = userEvent.setup()
  const utils = render(
    <MoneyInput aria-label="Valor" value={props.value ?? null} onChange={onChange} allowNegative={props.allowNegative} />,
  )
  return { ...utils, user, onChange, input: screen.getByLabelText('Valor') as HTMLInputElement }
}

/** Campo controlado de verdade (o valor emitido volta como `value`), como num formulário. */
function ControlledMoneyInput({ initial = null, allowNegative }: { initial?: number | null; allowNegative?: boolean }) {
  const [value, setValue] = useState<number | null>(initial)
  return (
    <>
      <MoneyInput aria-label="Valor" value={value} onChange={setValue} allowNegative={allowNegative} />
      <output aria-label="Emitido">{value === null ? 'null' : String(value)}</output>
      <button type="button" onClick={() => setValue(initial)}>
        Restaurar
      </button>
      <button type="button" onClick={() => setValue(98765)}>
        Trocar
      </button>
    </>
  )
}

describe('MoneyInput', () => {
  it.each([
    ['1', '0,01', 1],
    ['12', '0,12', 12],
    ['123', '1,23', 123],
    ['123456', '1.234,56', 123456],
  ])('digitar %s mostra %s e informa %i centavos', async (typed, shown, cents) => {
    const { user, input, onChange } = setup()

    await user.type(input, typed)

    expect(input).toHaveValue(shown)
    expect(onChange).toHaveBeenLastCalledWith(cents)
  })

  it('ignora zeros à esquerda e mostra "0,00" para um zero', async () => {
    const { user, input, onChange } = setup()

    await user.type(input, '0')
    expect(input).toHaveValue('0,00')
    expect(onChange).toHaveBeenLastCalledWith(0)

    await user.type(input, '05')
    expect(input).toHaveValue('0,05')
    expect(onChange).toHaveBeenLastCalledWith(5)
  })

  it('ignora letras', async () => {
    const { user, input, onChange } = setup()

    await user.type(input, '1a2,b')

    expect(input).toHaveValue('0,12')
    expect(onChange).toHaveBeenLastCalledWith(12)
  })

  it('Backspace tira o último dígito; apagar tudo informa null e deixa o campo vazio', async () => {
    const { user, input, onChange } = setup()

    await user.type(input, '123456')
    await user.keyboard('{Backspace}')
    expect(input).toHaveValue('123,45')
    expect(onChange).toHaveBeenLastCalledWith(12345)

    await user.keyboard('{Backspace}{Backspace}{Backspace}{Backspace}{Backspace}')
    expect(input).toHaveValue('')
    expect(input).toHaveAttribute('placeholder', '0,00')
    expect(onChange).toHaveBeenLastCalledWith(null)
  })

  it('Backspace a partir de "0,00" esvazia o campo', async () => {
    const { user, input, onChange } = setup()

    await user.type(input, '0{Backspace}')

    expect(input).toHaveValue('')
    expect(onChange).toHaveBeenLastCalledWith(null)
  })

  it('sem beforeinput, onChange cobre: apagar o fim tira o último dígito, inclusive de "0,00"', () => {
    const { input, onChange } = setup({ value: 100 })

    fireEvent.change(input, { target: { value: '1,0' } })
    expect(input).toHaveValue('0,10')
    expect(onChange).toHaveBeenLastCalledWith(10)

    fireEvent.change(input, { target: { value: '0,1' } })
    fireEvent.change(input, { target: { value: '0,0' } })
    expect(input).toHaveValue('')
    expect(onChange).toHaveBeenLastCalledWith(null)
  })

  it('sem beforeinput, apagar só um separador não muda o valor', () => {
    const { input, onChange } = setup({ value: 123456 })

    fireEvent.change(input, { target: { value: '1234,56' } })

    expect(input).toHaveValue('1.234,56')
    expect(onChange).not.toHaveBeenCalled()
  })

  it('onChange é ignorado durante composição de IME', () => {
    const { input, onChange } = setup({ value: 100 })

    fireEvent.input(input, { target: { value: '1,005' }, isComposing: true })

    expect(onChange).not.toHaveBeenCalled()
  })

  it('Delete também tira o último dígito, mesmo com o cursor no fim', async () => {
    const { user, input, onChange } = setup()

    await user.type(input, '123{Delete}')

    expect(input).toHaveValue('0,12')
    expect(onChange).toHaveBeenLastCalledWith(12)
  })

  it('selecionar tudo e digitar substitui o valor', async () => {
    const { user, input, onChange } = setup({ value: 123456 })
    expect(input).toHaveValue('1.234,56')

    await user.tripleClick(input)
    await user.keyboard('7')

    expect(input).toHaveValue('0,07')
    expect(onChange).toHaveBeenLastCalledWith(7)
  })

  it('selecionar tudo e apagar limpa o campo', async () => {
    const { user, input, onChange } = setup({ value: 123456 })

    await user.tripleClick(input)
    await user.keyboard('{Backspace}')

    expect(input).toHaveValue('')
    expect(onChange).toHaveBeenLastCalledWith(null)
  })

  it('cursor solto no meio do número volta para o fim', () => {
    const { input } = setup({ value: 123456 })
    input.focus()

    input.setSelectionRange(2, 2)
    fireEvent.select(input)

    expect(input.selectionStart).toBe(8)
    expect(input.selectionEnd).toBe(8)
  })

  it('uma seleção de verdade fica como está', () => {
    const { input } = setup({ value: 123456 })
    input.focus()

    input.setSelectionRange(0, 5)
    fireEvent.select(input)

    expect(input.selectionStart).toBe(0)
    expect(input.selectionEnd).toBe(5)
  })

  it.each([
    ['1.234,56', '1.234,56', 123456],
    ['1234,5', '1.234,50', 123450],
    ['1234.56', '1.234,56', 123456],
    ['R$ 50', '50,00', 5000],
    ['R$ 1.069,36', '1.069,36', 106936],
    ['0', '0,00', 0],
  ])('colar "%s" mostra %s', async (pasted, shown, cents) => {
    const { user, input, onChange } = setup()

    await user.click(input)
    await user.paste(pasted)

    expect(input).toHaveValue(shown)
    expect(onChange).toHaveBeenLastCalledWith(cents)
  })

  it('colar substitui o valor que já estava no campo', async () => {
    const { user, input, onChange } = setup({ value: 999 })

    await user.click(input)
    await user.paste('12,30')

    expect(input).toHaveValue('12,30')
    expect(onChange).toHaveBeenLastCalledWith(1230)
  })

  it.each([
    ['R$ 50 - mercado', '50,00', 5000],
    ['2026-10-20 R$ 50', '50,00', 5000],
    ['Total: 12x 34', '12,00', 1200],
  ])('colar "%s" lê o primeiro valor, nunca todos os dígitos juntos', async (pasted, shown, cents) => {
    const { user, input, onChange } = setup()

    await user.click(input)
    await user.paste(pasted)

    expect(input).toHaveValue(shown)
    expect(onChange).toHaveBeenLastCalledWith(cents)
  })

  it('colar texto sem dígito não muda nada', async () => {
    const { user, input, onChange } = setup({ value: 500 })

    await user.click(input)
    await user.paste('abc')

    expect(input).toHaveValue('5,00')
    expect(onChange).not.toHaveBeenCalled()
  })

  it('sem allowNegative, "-" é ignorado ao digitar e ao colar', async () => {
    const { user, input, onChange } = setup()

    await user.type(input, '-12-3')
    expect(input).toHaveValue('1,23')
    expect(onChange).toHaveBeenLastCalledWith(123)

    await user.tripleClick(input)
    await user.paste('-10,00')
    expect(input).toHaveValue('10,00')
    expect(onChange).toHaveBeenLastCalledWith(1000)
    expect(screen.queryByRole('button', { name: 'Valor negativo' })).not.toBeInTheDocument()
  })

  it('sem allowNegative, "-" num campo vazio não informa nada', async () => {
    const { user, input, onChange } = setup()

    await user.type(input, '-')

    expect(input).toHaveValue('')
    expect(onChange).not.toHaveBeenCalled()
  })

  it('allowNegative: "-" em campo vazio mostra "-" e informa null até vir um dígito', async () => {
    const { user, input, onChange } = setup({ allowNegative: true })

    await user.type(input, '-')
    expect(input).toHaveValue('-')
    expect(onChange).not.toHaveBeenCalled()

    await user.type(input, '123456')
    expect(input).toHaveValue('-1.234,56')
    expect(onChange).toHaveBeenLastCalledWith(-123456)
  })

  it('allowNegative: "-" em qualquer ponto inverte o sinal', async () => {
    const { user, input, onChange } = setup({ allowNegative: true })

    await user.type(input, '123-')
    expect(input).toHaveValue('-1,23')
    expect(onChange).toHaveBeenLastCalledWith(-123)

    await user.type(input, '4-')
    expect(input).toHaveValue('12,34')
    expect(onChange).toHaveBeenLastCalledWith(1234)
  })

  it('allowNegative: Backspace mantém o sinal e, no fim, apaga o "-"', async () => {
    const { user, input, onChange } = setup({ allowNegative: true })

    await user.type(input, '-12{Backspace}')
    expect(input).toHaveValue('-0,01')
    expect(onChange).toHaveBeenLastCalledWith(-1)

    await user.keyboard('{Backspace}')
    expect(input).toHaveValue('-')
    expect(onChange).toHaveBeenLastCalledWith(null)

    await user.keyboard('{Backspace}')
    expect(input).toHaveValue('')
  })

  it('allowNegative: colar valor negativo mantém o sinal', async () => {
    const { user, input, onChange } = setup({ allowNegative: true })

    await user.click(input)
    await user.paste('-R$ 1.234,56')

    expect(input).toHaveValue('-1.234,56')
    expect(onChange).toHaveBeenLastCalledWith(-123456)
  })

  it('allowNegative: o botão de sinal inverte o valor sem tirar o foco do campo', async () => {
    const { user, input, onChange } = setup({ allowNegative: true })
    const toggle = screen.getByRole('button', { name: 'Valor negativo' })
    expect(toggle).toHaveAttribute('aria-pressed', 'false')

    await user.type(input, '1500')
    await user.click(toggle)
    expect(input).toHaveValue('-15,00')
    expect(onChange).toHaveBeenLastCalledWith(-1500)
    expect(toggle).toHaveAttribute('aria-pressed', 'true')
    expect(input).toHaveFocus()

    await user.click(toggle)
    expect(input).toHaveValue('15,00')
    expect(onChange).toHaveBeenLastCalledWith(1500)
  })

  it('para no teto de dígitos', async () => {
    const { user, input, onChange } = setup()

    await user.type(input, '12345678901234567')

    expect(input).toHaveValue('12.345.678.901,23')
    expect(onChange).toHaveBeenLastCalledWith(1234567890123)
  })

  it('colar valor acima do teto não muda nada', async () => {
    const { user, input, onChange } = setup({ value: 100 })

    await user.click(input)
    await user.paste('1.000.000.000.000,00')

    expect(input).toHaveValue('1,00')
    expect(onChange).not.toHaveBeenCalled()
  })

  it('mostra o valor recebido, inclusive negativo', () => {
    const { rerender } = render(<MoneyInput aria-label="Valor" value={4590} onChange={() => {}} />)
    expect(screen.getByLabelText('Valor')).toHaveValue('45,90')

    rerender(<MoneyInput aria-label="Valor" value={-4590} onChange={() => {}} allowNegative />)
    expect(screen.getByLabelText('Valor')).toHaveValue('-45,90')
  })

  it('valor novo vindo de fora reescreve o campo; digitar não reseta', async () => {
    const user = userEvent.setup()
    render(<ControlledMoneyInput initial={1000} />)
    const input = screen.getByLabelText('Valor')

    await user.type(input, '5')
    expect(input).toHaveValue('100,05')
    expect(screen.getByLabelText('Emitido')).toHaveTextContent('10005')

    await user.click(screen.getByRole('button', { name: 'Restaurar' }))
    expect(input).toHaveValue('10,00')

    await user.click(screen.getByRole('button', { name: 'Trocar' }))
    expect(input).toHaveValue('987,65')
  })

  it('repassa rótulo e atributos aria para o campo', () => {
    render(
      <>
        <label htmlFor="valor">Valor do lançamento</label>
        <MoneyInput id="valor" aria-describedby="ajuda" aria-invalid value={null} onChange={() => {}} />
        <p id="ajuda">Ajuda</p>
      </>,
    )
    const input = screen.getByLabelText('Valor do lançamento')

    expect(input).toHaveAttribute('aria-describedby', 'ajuda')
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(input).toHaveAttribute('inputmode', 'numeric')
  })

  describe('beforeinput', () => {
    /** Dispara o evento como o navegador; devolve true se o componente cancelou a mudança nativa. */
    function beforeInput(input: HTMLInputElement, inputType: string, data: string | null = null, isComposing = false) {
      const event = new InputEvent('beforeinput', { inputType, data, isComposing, bubbles: true, cancelable: true })
      act(() => {
        input.dispatchEvent(event)
      })
      return event.defaultPrevented
    }

    function focusAtEnd(input: HTMLInputElement) {
      input.focus()
      input.setSelectionRange(input.value.length, input.value.length)
    }

    it('deleteContentBackward tira só o último dígito e cancela o apagar nativo', () => {
      const { input, onChange } = setup({ value: 123456 })
      focusAtEnd(input)

      expect(beforeInput(input, 'deleteContentBackward')).toBe(true)

      expect(input).toHaveValue('123,45')
      expect(onChange).toHaveBeenCalledTimes(1)
      expect(onChange).toHaveBeenLastCalledWith(12345)
    })

    it('deleteContentForward também tira o último dígito', () => {
      const { input, onChange } = setup({ value: 123 })
      focusAtEnd(input)

      expect(beforeInput(input, 'deleteContentForward')).toBe(true)

      expect(input).toHaveValue('0,12')
      expect(onChange).toHaveBeenLastCalledWith(12)
    })

    it('apagar palavra a partir do fim esvazia o campo', () => {
      const { input, onChange } = setup({ value: 123456 })
      focusAtEnd(input)

      beforeInput(input, 'deleteWordBackward')

      expect(input).toHaveValue('')
      expect(onChange).toHaveBeenLastCalledWith(null)
    })

    it('insertText acrescenta o dígito à direita', () => {
      const { input, onChange } = setup({ value: 123 })
      focusAtEnd(input)

      expect(beforeInput(input, 'insertText', '4')).toBe(true)

      expect(input).toHaveValue('12,34')
      expect(onChange).toHaveBeenLastCalledWith(1234)
    })

    it('insertText com tudo selecionado substitui o valor', () => {
      const { input, onChange } = setup({ value: 123456 })
      input.focus()
      input.setSelectionRange(0, input.value.length)

      beforeInput(input, 'insertText', '9')

      expect(input).toHaveValue('0,09')
      expect(onChange).toHaveBeenLastCalledWith(9)
    })

    it('insertText com letra é cancelado sem mudar nada', () => {
      const { input, onChange } = setup({ value: 123 })
      focusAtEnd(input)

      expect(beforeInput(input, 'insertText', 'a')).toBe(true)

      expect(input).toHaveValue('1,23')
      expect(onChange).not.toHaveBeenCalled()
    })

    it('insertText "-" inverte o sinal só com allowNegative', () => {
      const negative = setup({ value: 123, allowNegative: true })
      focusAtEnd(negative.input)
      beforeInput(negative.input, 'insertText', '-')
      expect(negative.input).toHaveValue('-1,23')
      expect(negative.onChange).toHaveBeenLastCalledWith(-123)
      negative.unmount()

      const positive = setup({ value: 123 })
      focusAtEnd(positive.input)
      expect(beforeInput(positive.input, 'insertText', '-')).toBe(true)
      expect(positive.input).toHaveValue('1,23')
      expect(positive.onChange).not.toHaveBeenCalled()
    })

    it('insertFromPaste usa a mesma leitura de colar', () => {
      const { input, onChange } = setup({ value: 999 })
      focusAtEnd(input)

      expect(beforeInput(input, 'insertFromPaste', 'R$ 50 - mercado')).toBe(true)

      expect(input).toHaveValue('50,00')
      expect(onChange).toHaveBeenLastCalledWith(5000)
    })

    it('apagar um "." selecionado não muda o valor', () => {
      const { input, onChange } = setup({ value: 123456 })
      input.focus()
      input.setSelectionRange(1, 2)

      expect(beforeInput(input, 'deleteContentBackward')).toBe(true)

      expect(input).toHaveValue('1.234,56')
      expect(onChange).not.toHaveBeenCalled()
    })

    it('apagar um "0" selecionado no meio tira só esse dígito', () => {
      const { input, onChange } = setup({ value: 103456 })
      input.focus()
      input.setSelectionRange(2, 3)

      beforeInput(input, 'deleteContentBackward')

      expect(input).toHaveValue('134,56')
      expect(onChange).toHaveBeenLastCalledWith(13456)
    })

    it('durante composição de IME, deixa o evento seguir', () => {
      const { input, onChange } = setup({ value: 123 })
      focusAtEnd(input)

      expect(beforeInput(input, 'insertCompositionText', '4', true)).toBe(false)
      expect(onChange).not.toHaveBeenCalled()
    })
  })

  describe('readOnly', () => {
    it('não aceita digitar, apagar nem colar, e trava o botão de sinal', async () => {
      const onChange = vi.fn()
      const user = userEvent.setup()
      render(<MoneyInput aria-label="Valor" value={1234} onChange={onChange} readOnly allowNegative />)
      const input = screen.getByLabelText('Valor')

      await user.type(input, '5{Backspace}{Delete}-')
      await user.paste('R$ 50')
      fireEvent.change(input, { target: { value: '9' } })

      expect(input).toHaveValue('12,34')
      expect(onChange).not.toHaveBeenCalled()
      expect(screen.getByRole('button', { name: 'Valor negativo' })).toBeDisabled()
    })
  })

  describe('botão de sinal', () => {
    it('fica desativado com o campo desativado', () => {
      render(<MoneyInput aria-label="Valor" value={100} onChange={() => {}} disabled allowNegative />)

      expect(screen.getByRole('button', { name: 'Valor negativo' })).toBeDisabled()
    })

    it('clicado com o campo fora de foco, leva o foco para o campo; sair dele chama onBlur', async () => {
      const onBlur = vi.fn()
      const onChange = vi.fn()
      const user = userEvent.setup()
      render(
        <>
          <MoneyInput aria-label="Valor" value={1500} onChange={onChange} onBlur={onBlur} allowNegative />
          <button type="button">Outro</button>
        </>,
      )
      const input = screen.getByLabelText('Valor')

      await user.click(screen.getByRole('button', { name: 'Valor negativo' }))
      expect(input).toHaveFocus()
      expect(input).toHaveValue('-15,00')
      expect(onChange).toHaveBeenLastCalledWith(-1500)

      await user.click(screen.getByRole('button', { name: 'Outro' }))
      expect(onBlur).toHaveBeenCalledTimes(1)
    })
  })

  it('ao sair do campo, "-" sem valor some', async () => {
    const { user, input, onChange } = setup({ allowNegative: true })

    await user.type(input, '-')
    expect(input).toHaveValue('-')
    await user.tab()

    expect(input).toHaveValue('')
    expect(onChange).not.toHaveBeenCalled()
  })

  it('ao sair do campo, "-0,00" vira "0,00"', async () => {
    const { user, input, onChange } = setup({ allowNegative: true })

    await user.type(input, '-0')
    expect(input).toHaveValue('-0,00')
    await user.tab()

    expect(input).toHaveValue('0,00')
    expect(onChange).toHaveBeenLastCalledWith(0)
  })

  it('repassa onBlur e ref (objeto ou callback com limpeza)', async () => {
    const onBlur = vi.fn()
    const objectRef = createRef<HTMLInputElement>()
    const cleanup = vi.fn()
    const callbackRef = vi.fn(() => cleanup)
    const user = userEvent.setup()
    const { unmount } = render(
      <>
        <MoneyInput aria-label="Primeiro" value={null} onChange={() => {}} onBlur={onBlur} ref={objectRef} />
        <MoneyInput aria-label="Segundo" value={null} onChange={() => {}} ref={callbackRef} />
      </>,
    )

    expect(objectRef.current).toBe(screen.getByLabelText('Primeiro'))
    expect(callbackRef).toHaveBeenCalledWith(screen.getByLabelText('Segundo'))

    await user.click(screen.getByLabelText('Primeiro'))
    await user.tab()
    expect(onBlur).toHaveBeenCalledTimes(1)

    unmount()
    expect(objectRef.current).toBeNull()
    expect(cleanup).toHaveBeenCalledTimes(1)
  })
})
