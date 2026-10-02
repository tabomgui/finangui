import { describe, expect, it } from 'vitest'
import { googleErrorMessage } from './google-errors'

describe('googleErrorMessage', () => {
  it.each([
    ['registration_closed', 'Esta conta Google não está cadastrada nesta instância.'],
    ['google_failed', 'Não foi possível entrar com o Google. Tente novamente.'],
    ['google_conflict', 'Este email já está vinculado a outra conta Google.'],
    ['google_link_requires_password', 'Entre com sua senha e vincule o Google em Configurações.'],
  ])('traduz %s', (code, message) => {
    expect(googleErrorMessage(code)).toBe(message)
  })

  it('ignora códigos desconhecidos', () => {
    expect(googleErrorMessage('outra_coisa')).toBeNull()
    expect(googleErrorMessage(null)).toBeNull()
  })
})
