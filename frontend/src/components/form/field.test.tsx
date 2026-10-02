import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Input } from '@/components/ui/input'
import { Field } from './field'

describe('Field', () => {
  it('liga a mensagem de erro ao controle via aria-describedby e marca aria-invalid', () => {
    render(
      <Field label="E-mail" htmlFor="email" error="E-mail inválido">
        <Input id="email" />
      </Field>,
    )

    const input = screen.getByLabelText('E-mail')
    const message = screen.getByText('E-mail inválido')

    expect(message).toHaveAttribute('id', 'email-message')
    expect(input).toHaveAttribute('aria-describedby', 'email-message')
    expect(input).toHaveAttribute('aria-invalid', 'true')
  })

  it('liga a dica ao controle e preserva o aria-invalid do próprio controle quando não há erro', () => {
    render(
      <Field label="Senha" htmlFor="password" hint="Mínimo de 8 caracteres">
        <Input id="password" aria-invalid="true" />
      </Field>,
    )

    const input = screen.getByLabelText('Senha')
    const message = screen.getByText('Mínimo de 8 caracteres')

    expect(message).toHaveAttribute('id', 'password-message')
    expect(input).toHaveAttribute('aria-describedby', 'password-message')
    expect(input).toHaveAttribute('aria-invalid', 'true')
  })

  it('não define aria-describedby quando não há erro nem dica', () => {
    render(
      <Field label="Nome" htmlFor="name">
        <Input id="name" />
      </Field>,
    )

    expect(screen.getByLabelText('Nome')).not.toHaveAttribute('aria-describedby')
  })
})
