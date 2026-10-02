import type { CardStatement } from '@/api/types'
import { Badge } from '@/components/ui/badge'
import { STATUS_LABELS } from './statement-labels'

export function StatementStatusBadge({ statement }: { statement: Pick<CardStatement, 'status' | 'is_overdue'> }) {
  if (statement.is_overdue) return <Badge variant="destructive">Vencida</Badge>
  return <Badge variant={statement.status === 'open' ? 'outline' : 'secondary'}>{STATUS_LABELS[statement.status]}</Badge>
}
