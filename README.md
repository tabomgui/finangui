# finangui

Gerenciador financeiro pessoal, self-hosted. Backend Laravel 13 + Postgres; frontend React (em `frontend/`, ainda não implementado).

Design: `docs/superpowers/specs/2026-10-01-finangui-design.md` · Roadmap: `docs/ROADMAP.md`

## Desenvolvimento

Requisitos: Docker e Make.

```bash
cp backend/.env.example backend/.env
make up                       # sobe db (:5432), backend (:8001), worker e scheduler
make art c="key:generate"
make fresh                    # migra do zero e cria dev@finangui.test / password
make test                     # Pest (banco finangui_test)
make lint                     # Pint + Larastan
make openapi                  # gera backend/storage/app/openapi.json
```

Portas do host: backend em `:8001` (configurável por `BACKEND_PORT`), banco em `:5432`, frontend (quando existir) em `:5174`.

Documentação interativa da API (Scramble) em http://localhost:8001/docs/api — só disponível em ambiente local (`APP_ENV=local`); em outros ambientes a rota fica bloqueada.

### Banco de teste

O banco `finangui_test`, usado por `make test`, é criado por `docker/postgres/init.sql` na primeira vez que o volume `db-data` é inicializado. Se esse volume já existia antes do `init.sql` ganhar esse banco (ambiente antigo), ele não roda de novo automaticamente. Nesse caso:

```bash
make down && docker compose down -v   # recria o volume do zero (destrói dados de dev)
make up
```

Ou, para não perder dados de dev, crie o banco manualmente: `docker compose exec db createdb -U finangui finangui_test`.

## Primeiro usuário

O cadastro público vem desligado (`REGISTRATION_ENABLED=false`).

```bash
make art c="user:create voce@exemplo.com --name=Você"
```

O comando pede a senha duas vezes (confirmação) e guarda o email sempre em minúsculas.

## Moeda primária

`PRIMARY_CURRENCY` (padrão `BRL`) define a moeda dos totais do dashboard. Contas e transações em outras moedas aparecem nas listagens normalmente, mas ficam fora do saldo total e dos agregados de receita/despesa/maiores categorias até existir conversão entre moedas (ver roadmap).

## Login com Google

1. No Google Cloud Console, crie um OAuth Client (Web).
2. Redirect URI autorizada: `http://localhost:5174/api/auth/google/callback` (ajuste o domínio em produção).
3. Preencha `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` e `GOOGLE_REDIRECT_URI` no `backend/.env`.

Sem essas variáveis, o botão do Google não aparece.

Regras de vínculo da conta:

- O login busca primeiro por `google_id`; se achar, entra direto.
- Sem `google_id` cadastrado, cai para o email — só quando o Google confirma `email_verified`.
- Nunca vincula automaticamente a uma conta de senha cujo email nunca foi confirmado (cadastro aberto por senha): isso abriria sequestro de conta. Nesse caso o usuário loga com senha e vincula o Google depois em Configurações.

`SESSION_SAME_SITE` precisa continuar `lax`: `strict` quebra o callback do Google (o `state` da sessão se perde).

## Importar categorias do finangui-js

No servidor antigo (MySQL):

```bash
mysql -u root -p finangui --batch \
  -e "SELECT id, name, type, color, parent_id FROM categories WHERE user_id = 1" > categories.tsv
```

Copie o arquivo para `backend/storage/app/categories.tsv` e rode:

```bash
make art c="legacy:import-categories storage/app/categories.tsv voce@exemplo.com"
```

## Dados bancários

Este repositório é público. Nunca commite extratos, faturas ou fixtures com dados reais.
