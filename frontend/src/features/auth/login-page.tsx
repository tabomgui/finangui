import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { Link, Navigate, useLocation, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { z } from 'zod'
import { useAuthStatus, useLogin, useMe } from '@/api/queries/auth'
import { Field } from '@/components/form/field'
import { FullPageSpinner } from '@/components/shared/full-page-spinner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Separator } from '@/components/ui/separator'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { AuthLayout } from './auth-layout'
import { googleErrorMessage } from './google-errors'

const schema = z.object({
  email: z.email('Informe um email válido.'),
  password: z.string().min(1, 'Informe a senha.'),
})

type FormValues = z.infer<typeof schema>

export function LoginPage() {
  const { data: user, isPending: userPending } = useMe()
  const { data: status } = useAuthStatus()
  const login = useLogin()
  const location = useLocation()
  const [params, setParams] = useSearchParams()

  const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { email: '', password: '' } })

  useEffect(() => {
    const message = googleErrorMessage(params.get('error'))
    if (!message) return
    toast.error(message)
    params.delete('error')
    setParams(params, { replace: true })
  }, [params, setParams])

  const from = (location.state as { from?: string } | null)?.from ?? '/'

  // Esperar `useMe` resolver antes de decidir entre o formulário e o redirecionamento evita
  // mostrar o formulário por um instante para quem já está logado (flash).
  if (userPending) return <FullPageSpinner />
  if (user) return <Navigate to={from} replace />

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      // `useLogin.onSuccess` grava o usuário em `meKey`; o re-render resultante faz este
      // componente cair no `if (user)` acima e navegar para `from`, sem chamada imperativa aqui.
      await login.mutateAsync(values)
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, ['email', 'password'])) notifyError(error)
    }
  })

  const { errors } = form.formState

  return (
    <AuthLayout>
      <Card className="rounded-2xl shadow-card">
        <CardHeader className="text-center">
          <h1 className="text-2xl leading-none font-semibold">Entrar</h1>
          <CardDescription>Acesse sua conta para continuar.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <form className="space-y-4" onSubmit={onSubmit} noValidate>
            <Field label="Email" htmlFor="email" error={errors.email?.message}>
              <Input id="email" type="email" autoComplete="email" {...form.register('email')} />
            </Field>
            <Field label="Senha" htmlFor="password" error={errors.password?.message}>
              <Input id="password" type="password" autoComplete="current-password" {...form.register('password')} />
            </Field>
            <Button type="submit" className="w-full" disabled={login.isPending}>
              {login.isPending && <LoaderCircle className="h-4 w-4 animate-spin" />}
              Entrar
            </Button>
          </form>

          {status?.google_login_enabled && (
            <>
              <div className="flex items-center gap-3 text-xs text-muted-foreground">
                <Separator className="flex-1" />
                ou
                <Separator className="flex-1" />
              </div>
              <Button variant="outline" className="w-full" asChild>
                <a href="/api/auth/google/redirect">Entrar com Google</a>
              </Button>
            </>
          )}

          {status?.registration_enabled && (
            <p className="text-center text-sm text-muted-foreground">
              Não tem conta?{' '}
              <Link to="/cadastro" className="font-medium text-primary hover:underline">
                Criar conta
              </Link>
            </p>
          )}
        </CardContent>
      </Card>
    </AuthLayout>
  )
}
