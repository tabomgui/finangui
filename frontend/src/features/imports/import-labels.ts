import type { ImportBatch, ImportBatchStatus, ImportFormat, ImportOutcome, ImportPreview } from '@/api/types'

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

/** Singular/plural simples (português não tem irregularidade nos termos usados aqui). */
function pluralize(count: number, singular: string, plural: string): string {
  return count === 1 ? singular : plural
}

type SummaryKey = keyof ImportPreview['summary']

const SUMMARY_ORDER: SummaryKey[] = ['new', 'duplicate', 'update', 'replace_installment', 'adopt', 'swap_pending', 'failed']

const SUMMARY_WORDS: Record<SummaryKey, [string, string]> = {
  new: ['nova', 'novas'],
  duplicate: ['já importada', 'já importadas'],
  update: ['atualizada', 'atualizadas'],
  replace_installment: ['com parcela prevista substituída', 'com parcelas previstas substituídas'],
  adopt: ['casada com lançamento manual', 'casadas com lançamento manual'],
  swap_pending: ['pendente confirmada', 'pendentes confirmadas'],
  failed: ['inválida', 'inválidas'],
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

/**
 * `ImportBatchResource.stats` (lote concluído/revertido) usa outros nomes de chave que
 * `ImportPreviewResource.summary` (prévia ainda pendente) — ambos vêm prontos do backend, só
 * remapeia para o mesmo formato de texto usado em `summaryText`. `skipped` (linhas puladas na
 * confirmação) não tem equivalente na prévia e não entra no resumo.
 */
export function statsSummary(stats: ImportBatch['stats']): ImportPreview['summary'] {
  return {
    new: stats.inserted ?? 0,
    duplicate: stats.duplicates ?? 0,
    update: stats.updated ?? 0,
    replace_installment: stats.replaced ?? 0,
    adopt: stats.adopted ?? 0,
    swap_pending: stats.swapped ?? 0,
    failed: stats.failed.length,
  }
}
