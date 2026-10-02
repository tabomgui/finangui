import { Wallet } from 'lucide-react'
import { cn } from '@/lib/utils'

export function Logo({ inverted = false, className }: { inverted?: boolean; className?: string }) {
  return (
    <span className={cn('flex items-center gap-2 font-semibold', className)}>
      <span
        className={cn(
          'flex h-9 w-9 items-center justify-center rounded-xl',
          inverted ? 'bg-white/20 text-white' : 'bg-primary text-primary-foreground',
        )}
      >
        <Wallet className="h-5 w-5" />
      </span>
      <span className={cn('text-lg tracking-tight', inverted && 'text-white')}>finangui</span>
    </span>
  )
}
