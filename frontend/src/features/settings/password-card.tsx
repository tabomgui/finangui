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

function buildSchema(requiresCurrent: boolean) {
  return z
    .object({
      current_password: requiresCurrent ? z.string().min(1, 'Informe a senha atual.') : z.string(),
      password: z.string().min(8, 'A senha precisa ter pelo menos 8 caracteres.'),
      password_confirmation: z.string(),
    })
    .refine((values) => values.password === values.password_confirmation, {
      message: 'As senhas não conferem.',
      path: ['password_confirmation'],
    })
}

type FormValues = z.infer<ReturnType<typeof buildSchema>>

export function PasswordCard({ user }: { user: User }) {
  const requiresCurrent = user.has_password
  const update = useUpdateProfile()
  const form = useForm<FormValues>({
    resolver: zodResolver(buildSchema(requiresCurrent)),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  })

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      await update.mutateAsync({
        password: values.password,
        password_confirmation: values.password_confirmation,
        ...(requiresCurrent ? { current_password: values.current_password } : {}),
      })
      form.reset()
      toast.success(requiresCurrent ? 'Senha alterada.' : 'Senha definida.')
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, ['current_password', 'password', 'password_confirmation'])) {
        notifyError(error)
      }
    }
  })

  const { errors } = form.formState

  return (
    <Card className="rounded-2xl shadow-card">
      <CardHeader>
        <CardTitle>Senha</CardTitle>
        <CardDescription>
          {requiresCurrent ? 'Troque a senha usada para entrar.' : 'Sua conta entra só pelo Google. Defina uma senha se quiser.'}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form className="space-y-4" onSubmit={onSubmit} noValidate>
          {requiresCurrent && (
            <Field label="Senha atual" htmlFor="current_password" error={errors.current_password?.message}>
              <Input id="current_password" type="password" autoComplete="current-password" {...form.register('current_password')} />
            </Field>
          )}
          <Field label="Nova senha" htmlFor="new_password" error={errors.password?.message}>
            <Input id="new_password" type="password" autoComplete="new-password" {...form.register('password')} />
          </Field>
          <Field label="Confirme a nova senha" htmlFor="new_password_confirmation" error={errors.password_confirmation?.message}>
            <Input
              id="new_password_confirmation"
              type="password"
              autoComplete="new-password"
              {...form.register('password_confirmation')}
            />
          </Field>
          <Button type="submit" disabled={update.isPending}>
            {update.isPending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            {requiresCurrent ? 'Alterar senha' : 'Definir senha'}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
