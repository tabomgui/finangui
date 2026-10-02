import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { useAuthStatus, useMe, useRegister } from '@/api/queries/auth'
import { Field } from '@/components/form/field'
import { FullPageSpinner } from '@/components/shared/full-page-spinner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { AuthLayout } from './auth-layout'

const schema = z
  .object({
    name: z.string().trim().min(1, 'Informe seu nome.').max(100, 'Use no máximo 100 caracteres.'),
    email: z.email('Informe um email válido.'),
    password: z.string().min(8, 'A senha precisa ter pelo menos 8 caracteres.'),
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'As senhas não conferem.',
    path: ['password_confirmation'],
  })

type FormValues = z.infer<typeof schema>

export function RegisterPage() {
  const { data: user } = useMe()
  const { data: status, isPending } = useAuthStatus()
  const register = useRegister()
  const navigate = useNavigate()

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { name: '', email: '', password: '', password_confirmation: '' },
  })

  if (user) return <Navigate to="/" replace />
  if (isPending) return <FullPageSpinner />

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      await register.mutateAsync(values)
      navigate('/', { replace: true })
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, ['name', 'email', 'password', 'password_confirmation'])) {
        notifyError(error)
      }
    }
  })

  const { errors } = form.formState

  return (
    <AuthLayout>
      <Card className="rounded-2xl shadow-card">
        <CardHeader className="text-center">
          <CardTitle className="text-2xl">Criar conta</CardTitle>
          {!status?.registration_enabled && <CardDescription>O cadastro está fechado nesta instância.</CardDescription>}
        </CardHeader>
        <CardContent className="space-y-4">
          {status?.registration_enabled ? (
            <form className="space-y-4" onSubmit={onSubmit} noValidate>
              <Field label="Nome" htmlFor="name" error={errors.name?.message}>
                <Input id="name" autoComplete="name" {...form.register('name')} />
              </Field>
              <Field label="Email" htmlFor="email" error={errors.email?.message}>
                <Input id="email" type="email" autoComplete="email" {...form.register('email')} />
              </Field>
              <Field label="Senha" htmlFor="password" error={errors.password?.message}>
                <Input id="password" type="password" autoComplete="new-password" {...form.register('password')} />
              </Field>
              <Field label="Confirme a senha" htmlFor="password_confirmation" error={errors.password_confirmation?.message}>
                <Input
                  id="password_confirmation"
                  type="password"
                  autoComplete="new-password"
                  {...form.register('password_confirmation')}
                />
              </Field>
              <Button type="submit" className="w-full" disabled={register.isPending}>
                {register.isPending && <LoaderCircle className="h-4 w-4 animate-spin" />}
                Criar conta
              </Button>
            </form>
          ) : null}
          <p className="text-center text-sm text-muted-foreground">
            Já tem conta?{' '}
            <Link to="/login" className="font-medium text-primary hover:underline">
              Entrar
            </Link>
          </p>
        </CardContent>
      </Card>
    </AuthLayout>
  )
}
