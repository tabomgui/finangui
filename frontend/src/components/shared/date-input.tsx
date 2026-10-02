import type { ComponentProps } from 'react'
import { Input } from '@/components/ui/input'

type DateInputProps = Omit<ComponentProps<typeof Input>, 'type' | 'value' | 'onChange'> & {
  /** Data sem hora, "YYYY-MM-DD". */
  value: string
  onChange: (value: string) => void
}

export function DateInput({ value, onChange, ...props }: DateInputProps) {
  return <Input {...props} type="date" value={value} onChange={(event) => onChange(event.target.value)} />
}
