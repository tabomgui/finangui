export class ApiError extends Error {
  readonly status: number
  readonly code: string | null
  readonly fieldErrors: Record<string, string[]>

  constructor(status: number, message: string, code: string | null = null, fieldErrors: Record<string, string[]> = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.fieldErrors = fieldErrors
  }
}

const DEFAULT_MESSAGES: Record<number, string> = {
  0: 'Sem conexão com o servidor.',
  401: 'Sua sessão terminou. Entre novamente.',
  403: 'Você não tem permissão para isso.',
  404: 'Não encontrado.',
  413: 'Arquivo grande demais.',
  419: 'Sua sessão expirou. Recarregue a página.',
  429: 'Muitas tentativas. Aguarde um minuto e tente de novo.',
}

function defaultMessage(status: number): string {
  return DEFAULT_MESSAGES[status] ?? 'Erro inesperado no servidor. Tente novamente.'
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export function toApiError(status: number, body: unknown): ApiError {
  if (!isRecord(body)) return new ApiError(status, defaultMessage(status))

  const code = typeof body.code === 'string' ? body.code : null
  // Mensagens do Laravel (403/404/429/...) vêm em inglês; só confiamos no corpo quando é um
  // erro nosso com `code` (domínio, registration_closed, session_required) ou validação 422
  // (traduzida pelo backend). Fora isso, usamos o texto padrão em português para o status.
  const trustsBodyMessage = code !== null || status === 422
  const message = trustsBodyMessage && typeof body.message === 'string' && body.message !== '' ? body.message : defaultMessage(status)
  const fieldErrors: Record<string, string[]> = {}

  if (isRecord(body.errors)) {
    for (const [field, messages] of Object.entries(body.errors)) {
      if (Array.isArray(messages)) fieldErrors[field] = messages.filter((m): m is string => typeof m === 'string')
    }
  }

  return new ApiError(status, message, code, fieldErrors)
}
