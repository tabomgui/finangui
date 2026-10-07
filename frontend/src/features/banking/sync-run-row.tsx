import type { BankSyncRun } from '@/api/types'
import { cn } from '@/lib/utils'
import { addedAndUpdatedLabel, formatRunDateTime, formatRunDuration, STATUS_META, TRIGGER_LABELS, warningOrErrorText } from './sync-run-labels'

type SyncRunRowProps = {
  run: BankSyncRun
  onSelect: (run: BankSyncRun) => void
}

export function SyncRunRow({ run, onSelect }: SyncRunRowProps) {
  const status = STATUS_META[run.status]
  const StatusIcon = status.icon
  const duration = formatRunDuration(run.started_at, run.finished_at)
  const message = warningOrErrorText(run)

  return (
    <li>
      <button
        type="button"
        onClick={() => onSelect(run)}
        className="flex w-full flex-col gap-1 px-3 py-3 text-left hover:bg-muted/50"
      >
        <div className="flex items-center justify-between gap-2">
          <span className="min-w-0 truncate text-sm font-medium">{formatRunDateTime(run.started_at)}</span>
          <span className={cn('flex shrink-0 items-center gap-1 text-xs font-medium', status.className)}>
            <StatusIcon className={cn('h-4 w-4', run.status === 'running' && 'animate-spin')} />
            {status.label}
          </span>
        </div>
        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
          <span>{TRIGGER_LABELS[run.trigger]}</span>
          <span aria-hidden>·</span>
          <span>{addedAndUpdatedLabel(run)}</span>
          {duration && (
            <>
              <span aria-hidden>·</span>
              <span>{duration}</span>
            </>
          )}
        </div>
        {message && <p className={cn('truncate text-xs', run.status === 'error' ? 'text-destructive' : 'text-amber-600 dark:text-amber-400')}>{message}</p>}
      </button>
    </li>
  )
}
