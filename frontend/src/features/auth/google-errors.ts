const GOOGLE_ERRORS: Record<string, string> = {
  registration_closed: 'Esta conta Google não está cadastrada nesta instância.',
  google_failed: 'Não foi possível entrar com o Google. Tente novamente.',
  google_conflict: 'Este email já está vinculado a outra conta Google.',
  google_link_requires_password: 'Entre com sua senha e vincule o Google em Configurações.',
}

export function googleErrorMessage(code: string | null): string | null {
  return code ? (GOOGLE_ERRORS[code] ?? null) : null
}
