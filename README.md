# finangui

Gerenciador financeiro pessoal, self-hosted. Backend Laravel 13 + Postgres; frontend React em `frontend/`.

Telas disponíveis: Início (dashboard do mês, com saldo real num dia escolhido, distribuição de gastos por categoria e pendências de recorrência atrasada), Transações (com filtros e edição em massa), Recorrências (lançamentos que se repetem — aluguel, salário, assinaturas — com ocorrências previstas geradas automaticamente e reconhecidas quando o lançamento real chega), Regras (condições e grupos para categorizar automaticamente, com prévia ao vivo, aplicação retroativa às transações já existentes e sugestão por histórico quando nenhuma regra casa), Cartões (faturas com datas reais e editáveis, pagamento como transferência, parcelamentos e limite disponível), Importar extrato (CSV do Inter, Nubank conta e cartão e C6, ou OFX genérico; prévia mostra novas, duplicadas, adoção de lançamento manual e parcelas antes de confirmar; lote importado pode ser revertido), Orçamento (limite mensal por categoria de despesa, com exceção pontual por mês e gasto/restante/progresso calculados), Metas (por conta vinculada ou por aportes manuais, com progresso, data alvo e ritmo mensal necessário), Relatórios (evolução mensal de receita × despesa e comparação de gastos por categoria entre dois períodos, pela data da compra ou pelo vencimento da fatura), Contas, Categorias, Tags e Configurações.

Notificações (sino no cabeçalho/barra lateral) avisam fatura vencendo, orçamento estourado, lançamento previsto não confirmado e banco pedindo reconexão.

Transferências entre contas próprias são detectadas automaticamente ao final de cada importação de extrato ou sincronização bancária: quando as duas pernas (saída numa conta, entrada noutra, de qualquer origem — inclusive um lançamento manual já existente) formam um par, o sistema liga as duas sozinho quando é inequívoco ou sugere quando é ambíguo; também dá para buscar sob demanda. Sugestões ficam disponíveis para aceitar ou descartar, e também é possível juntar duas transações à mão ou desfazer uma transferência já ligada.

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

`PRIMARY_CURRENCY` (padrão `BRL`) define a moeda dos totais do dashboard. Contas e transações em outras moedas aparecem nas listagens normalmente, mas ficam fora do saldo total e dos agregados de receita/despesa/distribuição de gastos até existir conversão entre moedas (ver roadmap).

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

A integração usa a [Pluggy](https://pluggy.ai) para conectar contas e cartões de bancos reais via Open Finance. As credenciais (`client_id`/`client_secret`) são **por usuário**, não da instância: cada usuário cadastra as suas em Configurações → "Integração bancária (Pluggy)", onde também testa, troca e remove. Elas ficam criptografadas no banco (chave `APP_KEY`) e nunca são devolvidas pela API, só um hint mascarado (ex.: "••••3be8").

1. Crie uma conta em https://dashboard.pluggy.ai e pegue as credenciais do sandbox (ou de produção, depois).
2. Cole `client_id` e `client_secret` no card de Configurações; salvar já testa as credenciais na Pluggy antes de gravar. Se precisar apontar para outro ambiente, `PLUGGY_BASE_URL` no `backend/.env` é opcional (vazio cai para `https://api.pluggy.ai`) — não existe mais `PLUGGY_CLIENT_ID`/`PLUGGY_CLIENT_SECRET` de instância.

Sem credenciais verificadas cadastradas, a integração fica desligada para aquele usuário: o botão "Conectar banco" leva direto ao card de Configurações e os endpoints de conexão respondem 409 (`banking_disabled`). Trocar de `client_id` (outra conta Pluggy) ou remover a credencial só é bloqueado enquanto houver uma conexão bancária que já sincronizou com sucesso sob a conta atual — desconecte-a primeiro; uma conexão ainda não "adotada" por nenhuma conta (ver upgrade abaixo) ou de uma terceira conta nunca bloqueia, e o `client_secret` pode ser trocado livremente. Trocar o `APP_KEY` da instância torna as credenciais já salvas ilegíveis (tratadas como se não existissem) — o usuário precisa recadastrá-las.

No sandbox da Pluggy, use o conector "Pluggy Bank" com usuário `user-ok`, senha `password-ok` e, se pedir MFA, o código `123456`.

### Fazendo upgrade de uma instância com a conta Pluggy antiga (`PLUGGY_CLIENT_*`)

Versões anteriores configuravam uma única conta Pluggy para toda a instância, por variável de ambiente. Depois do upgrade para credenciais por usuário:

- Cada usuário que já tinha bancos conectados deve cadastrar, em Configurações, o mesmo `client_id`/`client_secret` que estava em `PLUGGY_CLIENT_ID`/`PLUGGY_CLIENT_SECRET` — assim as conexões existentes continuam falando com a mesma conta Pluggy que as criou. Salvar já dispara uma sincronização dessas conexões; a primeira que terminar com sucesso "adota" a conexão para a credencial (fica amarrada a ela), sem precisar reconectar nada no banco.
- Qualquer outro usuário na mesma instância (multiusuário) precisa criar/registrar a própria aplicação na Pluggy e reconectar os bancos dele por ali — a conta antiga não é compartilhada automaticamente com outros usuários.
- Depois de trocar de conta Pluggy, um item que ficou de fora (desconectado no app, ou nunca chegou a adotar a credencial nova) pode continuar existindo na conta antiga; delete-o direto no [painel da Pluggy](https://dashboard.pluggy.ai) se não for mais usar.

Trocar o `APP_KEY` da instância (rotação de chave) também afeta as credenciais da Pluggy, criptografadas com ela: liste a chave antiga em `APP_PREVIOUS_KEYS` (suportado nativamente pelo Laravel) para elas continuarem legíveis durante a transição. Sem isso, toda credencial cadastrada antes da troca fica ilegível (tratada como não configurada) e precisa ser recadastrada.

Depois de conectar, cada conexão sincroniza automaticamente a cada 6 horas (contas, saldo, faturas e transações) e também pode ser sincronizada na hora pelo botão "Sincronizar agora". Essa sincronização roda em fila (job) e depende do **worker e do scheduler estarem no ar** — ambos já sobem com `make up` em desenvolvimento. `DB_QUEUE_RETRY_AFTER=660` e o `--timeout=600` do worker (já configurados em `docker-compose.yml`/`docker-compose.prod.yml`) cobrem o pior caso desse job; não reduza um sem o outro.

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
  quando a data chega, às 00:20 o `GenerateRecurrences`, que gera as ocorrências previstas de
  cada recorrência ativa, e às 07:00 o `SendAlerts`, que cria as notificações de fatura
  vencendo, orçamento estourado e lançamentos previstos não confirmados, além de apagar
  notificações lidas com mais de 90 dias; sem o scheduler no ar, parcelas projetadas nunca são
  lançadas, recorrências não geram previstas novas (criar ou editar uma recorrência ainda gera
  na hora, pela própria API) e essas notificações diárias não são criadas (a de banco pedindo
  reconexão continua chegando na hora, fora do scheduler).

## Dados bancários

Este repositório é público. Nunca commite extratos, faturas ou fixtures com dados reais.
