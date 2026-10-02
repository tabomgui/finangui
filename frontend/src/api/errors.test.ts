import { describe, expect, it } from 'vitest'
import { ApiError, toApiError } from './errors'

describe('toApiError', () => {
  it('extrai erros de campo de respostas 422', () => {
    const error = toApiError(422, { message: 'Dados inválidos.', errors: { email: ['O campo email é obrigatório.'] } })

    expect(error).toBeInstanceOf(ApiError)
    expect(error.status).toBe(422)
    expect(error.message).toBe('Dados inválidos.')
    expect(error.fieldErrors.email).toEqual(['O campo email é obrigatório.'])
  })

  it('preserva o código de erros de domínio', () => {
    const error = toApiError(409, { code: 'account_has_transactions', message: 'A conta tem transações.' })

    expect(error.code).toBe('account_has_transactions')
    expect(error.message).toBe('A conta tem transações.')
  })

  it.each([
    [0, 'Sem conexão com o servidor.'],
    [419, 'Sua sessão expirou. Recarregue a página.'],
    [429, 'Muitas tentativas. Aguarde um minuto e tente de novo.'],
    [500, 'Erro inesperado no servidor. Tente novamente.'],
  ])('usa mensagem padrão para status %i sem corpo', (status, message) => {
    expect(toApiError(status, undefined).message).toBe(message)
  })

  it('ignora corpo em formato inesperado', () => {
    expect(toApiError(500, 'Internal Server Error').message).toBe('Erro inesperado no servidor. Tente novamente.')
  })
})
