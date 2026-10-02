import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { useUpdateProfile } from '@/api/queries/auth'
import type { User } from '@/api/types'
import { Field } from '@/components/form/field'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'

const schema = z.object({ name: z.string().trim().min(1, 'Informe seu nome.').max(100, 'Use no máximo 100 caracteres.') })
type FormValues = z.infer<typeof schema>

export function ProfileCard({ user }: { user: User }) {
  const update = useUpdateProfile()
  const form = useForm<FormValues>({ resolver: zodResolver(schema), values: { name: user.name } })

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      await update.mutateAsync(values)
      toast.success('Perfil atualizado.')
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, ['name'])) notifyError(error)
    }
  })

  return (
    <Card className="rounded-2xl shadow-card">
      <CardHeader>
        <CardTitle>Perfil</CardTitle>
        <CardDescription>{user.email}</CardDescription>
      </CardHeader>
      <CardContent>
        <form className="space-y-4" onSubmit={onSubmit} noValidate>
          <Field label="Nome" htmlFor="profile-name" error={form.formState.errors.name?.message}>
            <Input id="profile-name" autoComplete="name" {...form.register('name')} />
          </Field>
          <Button type="submit" disabled={update.isPending || !form.formState.isDirty}>
            {update.isPending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            Salvar
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
