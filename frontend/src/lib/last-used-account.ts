const STORAGE_KEY = 'finangui:lastUsedAccountId'

/** Conta usada no último lançamento/transferência criado pelo usuário, guardada no localStorage
 * (sobrevive a recarregar a página; não precisa sincronizar entre dispositivos). É a base do
 * padrão de "nova transação"/"pagar fatura": sem isso, o padrão caía sempre em `accounts[0]`, ou
 * seja, a primeira conta em ordem alfabética devolvida pelo backend — uma conta nova com nome que
 * vem antes no alfabeto (ex.: "Carteira") virava o padrão silenciosamente a partir daí. */
export function getLastUsedAccountId(): number | null {
  const raw = window.localStorage.getItem(STORAGE_KEY)
  if (raw === null) return null
  const id = Number(raw)
  return Number.isInteger(id) && id > 0 ? id : null
}

export function rememberLastUsedAccountId(id: number): void {
  window.localStorage.setItem(STORAGE_KEY, String(id))
}

/** Conta padrão para um formulário novo, a partir de uma lista já filtrada pelo chamador (ex.:
 * só contas ativas; para "pagar fatura", também sem cartão). Critério: a última conta usada, se
 * ainda estiver entre as permitidas; sem histórico (ou a última usada não está mais na lista —
 * foi arquivada, por exemplo), a primeira conta que não é cartão de crédito, nunca uma posição
 * fixa que dependa só da ordem alfabética devolvida pelo backend. */
export function pickDefaultAccountId(accounts: { id: number; type: string }[] | undefined): number | null {
  if (!accounts || accounts.length === 0) return null
  const lastUsed = getLastUsedAccountId()
  if (lastUsed !== null && accounts.some((account) => account.id === lastUsed)) return lastUsed
  return (accounts.find((account) => account.type !== 'credit_card') ?? accounts[0]).id
}
