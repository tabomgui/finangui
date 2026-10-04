import type { ImportBatchStatus, ImportFormat, ImportOutcome, ImportPreview, ImportPreviewRow } from '@/api/types'
import { formatDate } from '@/lib/date'

export const OUTCOME_LABELS: Record<ImportOutcome, string> = {
  new: 'Nova',
  duplicate: 'Já importada',
  update: 'Atualiza',
  replace_installment: 'Substitui parcela prevista',
  adopt: 'Casa com lançamento manual',
  swap_pending: 'Confirma pendente',
}

export const FORMAT_OPTIONS: { value: ImportFormat; label: string }[] = [
  { value: 'inter', label: 'Inter (conta)' },
  { value: 'nubank', label: 'Nubank (conta)' },
  { value: 'nubank_card', label: 'Nubank (cartão)' },
  { value: 'c6', label: 'C6 (conta)' },
  { value: 'ofx', label: 'OFX' },
]

export const BATCH_STATUS_LABELS: Record<ImportBatchStatus, string> = {
  pending: 'Pendente',
  completed: 'Importado',
  reverted: 'Revertido',
}

/** Variante do `Badge` do status de um lote; compartilhada por histórico e prévia. */
export const BATCH_STATUS_VARIANT: Record<ImportBatchStatus, 'secondary' | 'default' | 'outline'> = {
  pending: 'secondary',
  completed: 'default',
  reverted: 'outline',
}

/** Singular/plural simples (português não tem irregularidade nos termos usados aqui). */
function pluralize(count: number, singular: string, plural: string): string {
  return count === 1 ? singular : plural
}

type SummaryKey = keyof ImportPreview['summary']

const SUMMARY_ORDER: SummaryKey[] = [
  'new',
  'duplicate',
  'update',
  'replace_installment',
  'adopt',
  'swap_pending',
  'failed',
  'transfers_linked',
  'transfer_suggestions',
]

// `transfers_linked`/`transfer_suggestions` contam transferências do lote (ligadas/sugeridas
// pela detecção automática ao final da importação), não linhas do extrato: por isso a palavra
// já inclui o próprio substantivo ("transferências ligadas", "sugestões de transferência"), em
// vez do padrão "N <adjetivo>" (implicitamente "transações") dos demais itens.
const SUMMARY_WORDS: Record<SummaryKey, [string, string]> = {
  new: ['nova', 'novas'],
  duplicate: ['já importada', 'já importadas'],
  update: ['atualizada', 'atualizadas'],
  replace_installment: ['com parcela prevista substituída', 'com parcelas previstas substituídas'],
  adopt: ['casada com lançamento manual', 'casadas com lançamento manual'],
  swap_pending: ['pendente confirmada', 'pendentes confirmadas'],
  failed: ['inválida', 'inválidas'],
  transfers_linked: ['transferência ligada', 'transferências ligadas'],
  transfer_suggestions: ['sugestão de transferência', 'sugestões de transferência'],
}

type RowMatch = NonNullable<ImportPreviewRow['match']>

/**
 * Texto da linha "casa com" na prévia: lançamento previsto de recorrência
 * tem um aviso fixo (sem repetir descrição/data — já aparecem na própria
 * linha, e o que importa aqui é avisar que não é um lançamento manual comum).
 */
export function matchText(match: RowMatch): string {
  if (match.kind === 'recurrence') {
    return 'Casa com lançamento previsto'
  }

  return `Casa com: ${match.description} em ${formatDate(match.date)}`
}

/** Texto curto do resultado de um lote: só os totais diferentes de zero, concatenados por " · ". */
export function summaryText(summary: ImportPreview['summary']): string {
  return SUMMARY_ORDER.filter((key) => summary[key] > 0)
    .map((key) => {
      const count = summary[key]
      const [singular, plural] = SUMMARY_WORDS[key]
      return `${count} ${pluralize(count, singular, plural)}`
    })
    .join(' · ')
}
