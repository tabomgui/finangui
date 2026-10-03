import { useSortable } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { EllipsisVertical, GripVertical, Pencil, Trash2 } from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import type { Rule } from '@/api/types'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Switch } from '@/components/ui/switch'
import { cn } from '@/lib/utils'
import { describeRule, type RuleLookups } from './rule-labels'

type SortableRuleRowProps = {
  rule: Rule
  lookups: RuleLookups
  onToggleActive: (rule: Rule, isActive: boolean) => void
  onDelete: (rule: Rule) => void
}

export function SortableRuleRow({ rule, lookups, onToggleActive, onDelete }: SortableRuleRowProps) {
  const navigate = useNavigate()
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: rule.id })

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
  }

  return (
    <li
      ref={setNodeRef}
      style={style}
      className={cn('flex items-center gap-3 px-3 py-3', isDragging && 'relative z-10 bg-card')}
    >
      <button
        type="button"
        className="cursor-grab touch-none text-muted-foreground outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 active:cursor-grabbing"
        aria-label={`Reordenar ${rule.name}`}
        {...attributes}
        {...listeners}
      >
        <GripVertical className="h-4 w-4" />
      </button>
      <div className="min-w-0 flex-1">
        <Link to={`/regras/${rule.id}`} className="block truncate font-medium hover:underline">
          {rule.name}
        </Link>
        <p className="truncate text-xs text-muted-foreground">{describeRule(rule, lookups)}</p>
      </div>
      <Switch
        aria-label={`Ativar ${rule.name}`}
        checked={rule.is_active}
        onCheckedChange={(checked) => onToggleActive(rule, checked)}
      />
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" aria-label={`Ações da regra ${rule.name}`}>
            <EllipsisVertical className="h-4 w-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onSelect={() => navigate(`/regras/${rule.id}`)}>
            <Pencil className="h-4 w-4" />
            Editar
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem className="text-destructive" onSelect={() => onDelete(rule)}>
            <Trash2 className="h-4 w-4" />
            Excluir
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </li>
  )
}
