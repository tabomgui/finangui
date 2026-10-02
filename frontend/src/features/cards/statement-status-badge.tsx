import type { CardStatement } from '@/api/types'
import { Badge } from '@/components/ui/badge'
import { isOverdue, STATUS_LABELS } from './statement-labels'

export function StatementStatusBadge({ statement }: { statement: Pick<CardStatement, 'status' | 'days_until_due'> }) {
  if (isOverdue(statement)) return <Badge variant="destructive">Vencida</Badge>
  return <Badge variant={statement.status === 'open' ? 'outline' : 'secondary'}>{STATUS_LABELS[statement.status]}</Badge>
}
