import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
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

  it('sem keydown (teclado virtual), apagar o último caractere tira o último dígito', () => {
    const { input, onChange } = setup({ value: 100 })

    fireEvent.change(input, { target: { value: '1,0' } })
    expect(input).toHaveValue('0,10')
    expect(onChange).toHaveBeenLastCalledWith(10)

    fireEvent.change(input, { target: { value: '0,1' } })
    fireEvent.change(input, { target: { value: '0,0' } })
    expect(input).toHaveValue('')
    expect(onChange).toHaveBeenLastCalledWith(null)
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

  it('mantém o cursor no fim depois de cada dígito', async () => {
    const { user, input } = setup()

    await user.type(input, '12345')

    expect(input.selectionStart).toBe(input.value.length)
    expect(input.selectionEnd).toBe(input.value.length)
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

  it('colar texto que não é valor cai para os dígitos dele', async () => {
    const { user, input, onChange } = setup()

    await user.click(input)
    await user.paste('Total: 12x 34')

    expect(input).toHaveValue('12,34')
    expect(onChange).toHaveBeenLastCalledWith(1234)
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
})
