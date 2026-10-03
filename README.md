# finangui

Gerenciador financeiro pessoal, self-hosted. Backend Laravel 13 + Postgres; frontend React em `frontend/`.

Telas disponíveis: Início (dashboard do mês), Transações (com filtros e edição em massa), Regras (condições e grupos para categorizar automaticamente, com prévia ao vivo, aplicação retroativa às transações já existentes e sugestão por histórico quando nenhuma regra casa), Cartões (faturas com datas reais e editáveis, pagamento como transferência, parcelamentos e limite disponível), Importar extrato (CSV do Inter, Nubank conta e cartão e C6, ou OFX genérico; prévia mostra novas, duplicadas, adoção de lançamento manual e parcelas antes de confirmar; lote importado pode ser revertido), Contas, Categorias, Tags e Configurações.

## Desenvolvimento

Requisitos: Docker e Make.

```bash
cp backend/.env.example backend/.env
make up                       # sobe db (:5432), backend (:8001), frontend (:5174), worker e scheduler
make art c="key:generate"
make fresh                    # migra do zero e cria dev@finangui.test / password
make test                     # Pest (banco finangui_test)
make lint                     # Pint + Larastan
make openapi                  # gera backend/storage/app/openapi.json
make types                    # regera frontend/src/api/schema.d.ts a partir do OpenAPI (rode depois de mudar a API)
make front-check              # lint + typecheck + testes do frontend
```

Portas do host: backend em `:8001` (configurável por `BACKEND_PORT`), banco em `:5432`, frontend em `:5174` (configurável por `FRONTEND_PORT`).

O frontend também roda em container (`make up` já sobe o serviço `frontend`), mas tipos e editor (TypeScript, oxlint) precisam das dependências instaladas no host: `cd frontend && npm install`. Se `frontend/package.json` mudar, reconstrua a imagem do serviço: `docker compose build frontend && docker compose up -d -V frontend`.

Mudança em `docker/php/**` (ex.: `uploads.ini`, `Dockerfile`) também não aparece com só um restart: `docker compose build backend && docker compose up -d -V backend`.

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

## Conectar bancos (Pluggy)

A integração usa a [Pluggy](https://pluggy.ai) para conectar contas e cartões de bancos reais via Open Finance.

1. Crie uma conta em https://dashboard.pluggy.ai e pegue as credenciais do sandbox (ou de produção, depois).
2. Preencha no `backend/.env`: `PLUGGY_CLIENT_ID`, `PLUGGY_CLIENT_SECRET` e, se precisar apontar para outro ambiente, `PLUGGY_BASE_URL` (vazio cai para `https://api.pluggy.ai`).

Sem `PLUGGY_CLIENT_ID`/`PLUGGY_CLIENT_SECRET`, a integração fica desligada: o botão "Conectar banco" não aparece e os endpoints de conexão respondem 409 (`banking_disabled`).

No sandbox da Pluggy, use o conector "Pluggy Bank" com usuário `user-ok`, senha `password-ok` e, se pedir MFA, o código `123456`.

Depois de conectar, cada conexão sincroniza automaticamente a cada 6 horas (contas, saldo, faturas e transações) e também pode ser sincronizada na hora pelo botão "Sincronizar". Essa sincronização roda em fila (job) e depende do **worker e do scheduler estarem no ar** — ambos já sobem com `make up` em desenvolvimento. `DB_QUEUE_RETRY_AFTER=660` e o `--timeout=600` do worker (já configurados em `docker-compose.yml`/`docker-compose.prod.yml`) cobrem o pior caso desse job; não reduza um sem o outro.

Em produção, o nginx do serviço `web` já libera `frame-src https://connect.pluggy.ai` na Content-Security-Policy, necessário para o widget da Pluggy abrir o iframe de login do banco.

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

## Produção

`docker-compose.prod.yml` sobe o backend, o worker, o scheduler, o banco e o serviço `web`
(nginx, servindo o SPA já buildado e fazendo proxy de `/api`, `/sanctum` e `/up` para o
backend) atrás do mesmo domínio.

**Same origin é obrigatório**: SPA e API precisam ficar sob o mesmo domínio em produção
(`https://seu-dominio` servindo tanto o frontend quanto `/api`). O projeto antigo
(finangui-js) teve problemas recorrentes de HTTPS/cookie em produção justamente por não
garantir isso; `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS` e `GOOGLE_REDIRECT_URI` abaixo só
funcionam corretamente nesse cenário.

```bash
cp backend/.env.production.example backend/.env
```

Preencha no `backend/.env`: `APP_KEY` (gere com
`docker run --rm php:8.4-cli-alpine php -r 'echo "base64:".base64_encode(random_bytes(32));'`),
`DB_PASSWORD`, o domínio (`APP_URL`, `FRONTEND_URL`, `SESSION_DOMAIN`,
`SANCTUM_STATEFUL_DOMAINS`) e as credenciais do Google. O compose falha rápido se `APP_KEY`
ou `DB_PASSWORD` estiverem vazios — sem eles o container nem sobe.

```bash
docker compose -f docker-compose.prod.yml --env-file backend/.env up -d --build
```

O serviço `web` publica em `${FINANGUI_BIND:-127.0.0.1}:${FINANGUI_PORT:-8080}`: por padrão só
em localhost, então o proxy que termina TLS (Caddy, Cloudflare Tunnel) precisa rodar no mesmo
host; para expor em outra interface, defina `FINANGUI_BIND`. O nginx também adiciona os
headers de segurança e resolve o endereço do backend a cada request, para sobreviver a um
redeploy/recreate do container `backend`.

`migrate --force` roda automaticamente a cada start do `backend` — não rode esse comando à
mão. Antes de um deploy, é recomendável um backup:

```bash
docker compose -f docker-compose.prod.yml --env-file backend/.env exec db pg_dump -U finangui finangui > backup.sql
```

Nunca rode `db:seed` em produção — o `DatabaseSeeder` cria o usuário de desenvolvimento
(`dev@finangui.test` / `password`). Ele já se protege sozinho (`app()->isProduction()` faz o
`run()` virar no-op), mas não dependa só disso: não rode o comando.

Primeiro usuário (cadastro público fica sempre desligado):

```bash
docker compose -f docker-compose.prod.yml --env-file backend/.env exec backend php artisan user:create voce@exemplo.com --name=Você
```

Pontos de atenção específicos de produção:

- **Proxy confiável**: produção roda atrás de um proxy que termina TLS (Caddy, Cloudflare
  Tunnel). `TRUSTED_PROXIES` (ver `backend/.env.production.example` e
  `backend/config/trustedproxy.php`) precisa cobrir o IP/rede desse proxy, senão
  `request()->isSecure()` e as URLs geradas ficam `http` mesmo atrás de HTTPS.
- **Google OAuth**: o Redirect URI cadastrado no Google Cloud Console precisa ser exatamente
  `https://seu-dominio/api/auth/google/callback` (mesma origem do SPA).
- **`SESSION_SAME_SITE=lax`**: precisa continuar `lax` também em produção. `strict` quebra o
  callback do login com Google (o cookie de sessão não volta na navegação de retorno do
  `accounts.google.com`, e o `state` se perde).
- **Worker**: em produção o worker roda `queue:work` (processo único, reinicia só em deploy),
  nunca `queue:listen` (que existe só pro Compose de dev, pra refletir mudança de código sem
  reiniciar o container). O `--timeout=600` cobre o maior job (`ApplyRuleRetroactively`, até 10
  minutos), sempre abaixo do `retry_after` da fila (`DB_QUEUE_RETRY_AFTER=660`), senão outro
  worker pega o mesmo job de novo antes do primeiro terminar.
- **Scheduler**: `schedule:work`, igual ao Compose de dev — não precisa de cron do sistema.
  Ele roda diariamente às 00:10 o `PostDueInstallments`, que vira parcela projetada em lançada
  quando a data chega; sem o scheduler no ar, parcelas projetadas nunca são lançadas.

## Dados bancários

Este repositório é público. Nunca commite extratos, faturas ou fixtures com dados reais.
