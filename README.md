# Roundcube - ambiente de desenvolvimento de tema

Ambiente local do Roundcube 1.7.5 para criar e testar skins (temas), com servidor
IMAP/SMTP de teste (GreenMail) e banco SQLite. Nao depende de servidor de e-mail real.

Para publicar as skins e plugins em um servidor com HestiaCP, sem tocar nos e-mails
existentes, veja [deploy/README.md](deploy/README.md).

## Requisitos

- Podman
- podman-compose
- lerd (para servir em `https://roundcube.test`)

## Subir o ambiente

```bash
cd /home/helio/GitHub/roundcubemail
podman-compose up -d
```

Acesse: https://roundcube.test

Acesso direto, sem o lerd: http://localhost:8000

Se preferir Docker, o mesmo arquivo funciona com:

```bash
docker compose up -d
```

O site esta registrado no lerd como **proxy-only** (`.lerd.yaml`): o lerd apenas gera o
vhost e aponta o nginx para a porta 8000. O container continua sendo gerenciado pelo
`podman-compose`, entao suba-o antes de abrir o dominio. Se `roundcube.test` nao resolver,
rode `lerd dns:check`.

Em uma copia nova do projeto, registre o site com:

```bash
lerd link roundcube
```

## Conta de teste

- Usuario: `demo@example.net`
- Senha: `demo`

A INBOX ja vem com 3 mensagens de exemplo (definidas em `docker/mail-demo/`), que sao
recarregadas a cada start do container de e-mail. O GreenMail aceita qualquer par
usuario/senha; a conta e criada automaticamente no primeiro login.

Para enviar mais um e-mail de teste:

```bash
printf 'To: demo@example.net\nFrom: dev@example.net\nSubject: Teste\n\nOla\n' > /tmp/teste.eml
curl smtp://localhost:3025 --mail-from dev@example.net --mail-rcpt demo@example.net -T /tmp/teste.eml
```

## Parar, reiniciar e ver logs

```bash
podman-compose ps
podman-compose stop
podman-compose restart
podman-compose down          # mantem os volumes (banco SQLite e temp)
podman-compose down -v       # apaga tambem banco, sessoes e cache
podman logs -f roundcube-dev-app
podman logs -f roundcube-dev-mail
```

## Onde fica o tema

- Tema atual: `src/skins/carius`
- Estilos: `src/skins/carius/styles/*.less` (o arquivo principal e `_carius.less`)
- Templates: `src/skins/carius/templates/*.html`
- Nome/metadados do tema: `src/skins/carius/meta.json`

O **Carius** usa a base do Elastic 1.7.5 e reorganiza a navegacao no topo:
E-mail, Calendario, Contatos e Configuracoes ficam na barra superior, enquanto a
barra lateral permanece com as pastas. Na tela de e-mail o cabecalho da lista vira
uma barra de acoes que atravessa a lista e o painel de leitura, como no layout de
referencia. A paleta indigo, a fonte Inter, os icones, o menu da conta, o contador de
nao lidas e o modo escuro estao em `_carius.less` e `ui-carius.js`.

As skins dos plugins (calendar e libkolab) tambem foram copiadas para `carius`.

O **Aurora** (`src/skins/aurora`) e a skin nova, criada diretamente sobre o skin
oficial **Elastic 1.7.5**: a navegacao fica numa trilha vertical de icones a esquerda,
com a barra de pastas e o botao verde "Criar email" ao lado, lista de mensagens com
remetente e previa em duas linhas e painel de leitura amplo. A paleta azul-céu, os
cartoes arredondados, a fonte Inter e o modo escuro estao em
`src/skins/aurora/styles/_variables.less` e `_styles.less`; nenhum template do
Elastic foi reescrito, entao o comportamento e a acessibilidade sao os originais.

As skins dos plugins (calendar e libkolab) tambem foram copiadas para `aurora`, e o
plugin `user` ganhou a paleta Aurora.

O `devel_mode` esta ligado em `config/roundcube.dev.inc.php`: o LESS e compilado no
navegador, entao basta salvar os arquivos e recarregar a pagina.

Temas disponiveis: **Carius** (padrao), **Aurora**, **Iguatu**, **Canga** e o
**Elastic** original. Para trocar depois de autenticado, use:

- `https://roundcube.test/?_task=mail&skin=carius`
- `https://roundcube.test/?_task=mail&skin=aurora`
- `https://roundcube.test/?_task=mail&skin=iguatu`
- `https://roundcube.test/?_task=mail&skin=canga`
- `https://roundcube.test/?skin=elastic`

A troca por `?skin=` fica salva nas preferencias do usuario. Para mudar o padrao de
novos logins, altere `ROUNDCUBE_SKIN` no `.env` e rode
`podman-compose up -d --force-recreate`.

Para criar outro tema:

```bash
cp -a src/skins/iguatu src/skins/meu-outro-tema
```

Depois ajuste `meta.json`, adicione um volume para a nova pasta no `docker-compose.yml`
e troque `ROUNDCUBE_SKIN` no `.env` para escolher o tema padrao.

## Calendario

- Plugin **Kolab Calendar 3.6.1** com `libcalendaring` e `libkolab`, driver `database`
  no SQLite do Roundcube.
- A tarefa **Calendario** aparece no menu e fica em `https://roundcube.test/?_task=calendar`.
- As skins `elastic` dos plugins foram copiadas para `skins/iguatu` dentro de
  `src/plugins/calendar` e `src/plugins/libkolab`.
- Os ajustes visuais do calendario (claro, escuro e mobile) ficam em
  `src/skins/iguatu/styles/_iguatu.less`, no bloco `body.task-calendar`.
- O diretorio `vendor/` local (ignorado pelo git) contem as dependencias do plugin e e
  copiado para o container pelo `docker/entrypoint-tasks/post-setup/10-calendar.sh`.

O `pear/http_request2` foi fixado em **2.7.0** (corrige `CVE-2025-43717`) usando o
alias do Composer `2.7.0 as 2.5.1`, que satisfaz a restricao `~2.5.0` do `libkolab`.
O `composer audit` nao reporta advisories e o calendario continua funcionando. Para uma
solucao definitiva em producao, o ideal e um fork/patch do `kolab/libkolab` trocando a
restricao para `^2.7` e removendo o alias.

## Configuracao

- `config/roundcube.dev.inc.php` e montado em `/var/roundcube/config` e incluido por
  cima da configuracao gerada. Use esse arquivo para opcoes extras de desenvolvimento.
- O banco SQLite fica no volume `roundcubemail_rc_db`, em `/var/roundcube/db`.

## Portas

| Servico | Porta |
| --- | --- |
| Roundcube (HTTP) | 8000 |
| IMAP | 3143 |
| SMTP | 3025 |
| POP3 | 3110 |

As portas e o tema podem ser alterados no `.env`.

## Imagem propria (opcional)

O `Dockerfile` copia o tema de `src/skins/` para dentro de uma imagem Roundcube
autocontida:

```bash
podman build -t meu-roundcube:dev .
```

## Recomecar do zero

```bash
podman-compose down -v
podman-compose up -d
```
