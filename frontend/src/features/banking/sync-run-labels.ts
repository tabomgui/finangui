import { format, parseISO } from 'date-fns'
import { CircleCheck, CircleX, LoaderCircle, TriangleAlert, type LucideIcon } from 'lucide-react'
import type { BankSyncRun, SyncRunStatus, SyncTrigger } from '@/api/types'

export const TRIGGER_LABELS: Record<SyncTrigger, string> = {
  scheduled: 'Agendada',
  manual: 'Manual',
  connect: 'Conexão',
  credentials: 'Credenciais',
}

type StatusMeta = { label: string; icon: LucideIcon; className: string }

export const STATUS_META: Record<SyncRunStatus, StatusMeta> = {
  running: { label: 'Em andamento', icon: LoaderCircle, className: 'text-sky-600 dark:text-sky-400' },
  success: { label: 'Sucesso', icon: CircleCheck, className: 'text-emerald-600 dark:text-emerald-400' },
  partial: { label: 'Com avisos', icon: TriangleAlert, className: 'text-amber-600 dark:text-amber-400' },
  error: { label: 'Erro', icon: CircleX, className: 'text-destructive' },
}

/** "05/10/2026 às 14:32" — `started_at`/`finished_at`/`provider_updated_at` vêm com hora (ISO
 * 8601), diferente das datas sem hora que `lib/date.ts` trata (ver `formatDate` lá). */
export function formatRunDateTime(value: string): string {
  return format(parseISO(value), "dd/MM/yyyy 'às' HH:mm")
}

/** `null` enquanto a run está `running` (sem `finished_at` ainda). */
export function formatRunDuration(startedAt: string, finishedAt?: string): string | null {
  if (!finishedAt) return null

  const totalSeconds = Math.max(0, Math.round((parseISO(finishedAt).getTime() - parseISO(startedAt).getTime()) / 1000))
  if (totalSeconds < 60) return `${totalSeconds}s`

  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60
  if (minutes < 60) return seconds > 0 ? `${minutes}min ${seconds}s` : `${minutes}min`

  const hours = Math.floor(minutes / 60)
  const remainingMinutes = minutes % 60
  return remainingMinutes > 0 ? `${hours}h ${remainingMinutes}min` : `${hours}h`
}

/** "12 adicionados" ou "12 adicionados · 3 atualizados" (só quando `updated_count` > 0). */
export function addedAndUpdatedLabel(run: Pick<BankSyncRun, 'added_count' | 'updated_count'>): string {
  const added = `${run.added_count} ${run.added_count === 1 ? 'adicionado' : 'adicionados'}`
  if (run.updated_count <= 0) return added
  return `${added} · ${run.updated_count} ${run.updated_count === 1 ? 'atualizado' : 'atualizados'}`
}

/** Texto de aviso/erro da linha: o erro voltado ao usuário quando a run falhou, senão os avisos
 * (`partial`) juntados numa frase só; `null` quando não há nada a mostrar. */
export function warningOrErrorText(run: Pick<BankSyncRun, 'status' | 'error' | 'warnings'>): string | null {
  if (run.status === 'error') return run.error ?? null
  if (run.warnings.length > 0) return run.warnings.join(' ')
  return null
}
