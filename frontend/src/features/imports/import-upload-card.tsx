import { LoaderCircle, Upload } from 'lucide-react'
import { type ChangeEvent, useRef } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useUploadStatement } from '@/api/queries/imports'
import type { ImportFormat } from '@/api/types'
import { Field } from '@/components/form/field'
import { AccountSelect } from '@/components/shared/account-select'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { FORMAT_OPTIONS } from './import-labels'

type UploadFormValues = {
  account_id: number | null
  file: File | null
  format: ImportFormat | 'auto'
}

// Mesmo limite de `StoreImportBatchRequest` no backend (`max:2048` KB). Checar no cliente evita
// uma viagem ao servidor só para voltar com o 413/422 de um arquivo visivelmente grande demais.
const MAX_FILE_SIZE_BYTES = 2 * 1024 * 1024

/** Conta pré-selecionada via `?conta=<id>` (link "Importar extrato" no menu de uma conta). */
function accountFromSearch(params: URLSearchParams): number | null {
  const raw = params.get('conta')
  const id = raw ? Number(raw) : NaN
  return Number.isInteger(id) && id > 0 ? id : null
}

export function ImportUploadCard() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const upload = useUploadStatement()
  const fileInputRef = useRef<HTMLInputElement>(null)

  const form = useForm<UploadFormValues>({
    defaultValues: { account_id: accountFromSearch(searchParams), file: null, format: 'auto' },
  })
  const { errors } = form.formState
  const accountId = useWatch({ control: form.control, name: 'account_id' })
  const file = useWatch({ control: form.control, name: 'file' })
  const format = useWatch({ control: form.control, name: 'format' })

  function handleFileChange(event: ChangeEvent<HTMLInputElement>) {
    const next = event.target.files?.[0] ?? null
    form.setValue('file', next)
    form.clearErrors('file')
  }

  const onSubmit = form.handleSubmit(async (values) => {
    if (!values.account_id) form.setError('account_id', { type: 'required', message: 'Escolha a conta.' })
    if (!values.file) form.setError('file', { type: 'required', message: 'Escolha um arquivo.' })
    if (!values.account_id || !values.file) return

    if (values.file.size > MAX_FILE_SIZE_BYTES) {
      form.setError('file', { type: 'maxSize', message: 'Arquivo grande demais. O limite é 2 MB.' })
      return
    }

    try {
      const preview = await upload.mutateAsync({
        account_id: values.account_id,
        file: values.file,
        format: values.format === 'auto' ? undefined : values.format,
      })
      navigate(`/importar/${preview.batch.id}`)
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, ['account_id', 'file', 'format'])) notifyError(error)
    }
  })

  return (
    <Card className="space-y-4 rounded-2xl p-4 shadow-card">
      <form className="space-y-4" onSubmit={onSubmit} noValidate>
        <Field label="Conta" htmlFor="import-account" error={errors.account_id?.message}>
          {(control) => (
            <AccountSelect
              {...control}
              value={accountId}
              onChange={(nextAccountId) => {
                form.setValue('account_id', nextAccountId)
                form.clearErrors('account_id')
              }}
            />
          )}
        </Field>

        <Field label="Arquivo" htmlFor="import-file" error={errors.file?.message}>
          {(control) => (
            <div className="flex min-w-0 items-center gap-3">
              <input
                ref={fileInputRef}
                id={control.id}
                type="file"
                accept=".csv,.ofx,.txt"
                className="sr-only"
                tabIndex={-1}
                aria-describedby={control['aria-describedby']}
                aria-invalid={control['aria-invalid']}
                onChange={handleFileChange}
              />
              <Button type="button" variant="outline" onClick={() => fileInputRef.current?.click()}>
                <Upload className="h-4 w-4" />
                Escolher arquivo
              </Button>
              <span className="truncate text-sm text-muted-foreground">
                {file ? file.name : 'Nenhum arquivo selecionado'}
              </span>
            </div>
          )}
        </Field>

        <Field label="Banco/formato" htmlFor="import-format" error={errors.format?.message}>
          {(control) => (
            <Select
              value={format}
              onValueChange={(next) => {
                form.setValue('format', next as ImportFormat | 'auto')
                form.clearErrors('format')
              }}
            >
              <SelectTrigger {...control} className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="auto">Detectar automaticamente</SelectItem>
                {FORMAT_OPTIONS.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </Field>

        <p className="text-xs text-muted-foreground">Exporte o extrato no app do banco em CSV ou OFX.</p>

        <Button type="submit" disabled={upload.isPending} className="w-full sm:w-auto">
          {upload.isPending && <LoaderCircle className="h-4 w-4 animate-spin" />}
          Ver prévia
        </Button>
      </form>
    </Card>
  )
}
