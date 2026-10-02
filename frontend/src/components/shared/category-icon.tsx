import { Tag } from 'lucide-react'
import { CATEGORY_ICONS } from '@/lib/category-icons'
import { cn } from '@/lib/utils'

const HEX_COLOR = /^#[0-9a-fA-F]{6}$/

type CategoryIconProps = {
  /** Nome do ícone lucide em kebab-case, como salvo na categoria ("heart-pulse"). */
  icon: string | null | undefined
  color: string | null | undefined
  size?: 'sm' | 'md'
  className?: string
}

export function CategoryIcon({ icon, color, size = 'md', className }: CategoryIconProps) {
  const Icon = (icon && CATEGORY_ICONS[icon]) || Tag
  const hex = color && HEX_COLOR.test(color) ? color : null

  return (
    <span
      aria-hidden
      className={cn(
        'flex shrink-0 items-center justify-center rounded-full',
        size === 'sm' ? 'h-8 w-8' : 'h-10 w-10',
        !hex && 'bg-muted text-muted-foreground',
        className,
      )}
      style={hex ? { backgroundColor: `${hex}20`, color: hex } : undefined}
    >
      <Icon className={size === 'sm' ? 'h-4 w-4' : 'h-5 w-5'} />
    </span>
  )
}
