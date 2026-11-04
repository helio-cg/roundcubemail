# Deploy de produção (HestiaCP)

Este deploy instala as skins e plugins deste repositório no Roundcube que o
HestiaCP já serve. E-mail não faz parte do deploy: nenhum diretório de mensagens,
conta, domínio, Exim, Dovecot ou Postfix é lido ou alterado.

## O que o deploy altera

- `/var/lib/roundcube/skins/{carius,aurora,iguatu,canga}`
- `/var/lib/roundcube/plugins/{calendar,libcalendaring,libkolab,twofactor_gauthenticator,user}`
- `/etc/roundcube/rc-deploy.inc.php` (novo include de configuração)
- um bloco marcado `BEGIN rc-deploy` no fim de `/etc/roundcube/config.inc.php`
- dependências Composer que faltarem em `/var/lib/roundcube/vendor`
- somente o banco `roundcube` (preferências, contatos, calendário), via tabelas
  dos próprios plugins

O deploy não toca em `/home/*/mail`, `/var/mail`, `/var/vmail`, `/etc/dovecot`,
`/etc/exim4` ou `/etc/postfix`. O `config.inc.php` existente também é preservado:
o deploy só acrescenta um include no fim, com backup e restauração automática em
caso de falha.

## Antes de começar

No servidor:

- Roundcube instalado pelo HestiaCP em `/var/lib/roundcube` (versão 1.7.x)
- acesso root por SSH, ou um usuário com `sudo -n`
- `php` CLI, `tar`, `mysqldump` e `rsync`
- `composer` (o HestiaCP normalmente já instala para o root)

Na máquina local:

- `node`/`npx`, usados para compilar os `.less` antes de enviar
- acesso SSH ao servidor

## Configuração

```bash
cp deploy/deploy.env.example deploy/deploy.env
```

Ajuste `DEPLOY_TARGET` e, se necessário, `RC_HEALTH_URL` e `DEPLOY_SUDO`. O arquivo
`deploy/deploy.env` fica fora do Git.

Se o acesso SSH já for root, deixe `DEPLOY_SUDO` vazio:

```bash
DEPLOY_SUDO=
```

## Primeiro deploy

```bash
./deploy/deploy.sh
```

Ou passando o destino direto:

```bash
./deploy/deploy.sh root@mail.exemplo.com
```

O script compila o CSS em `.build/payload`, envia o pacote, faz backup, instala,
roda as migrações dos plugins, corrige permissões e recarrega o PHP-FPM.

## Atualizações do dia a dia

Depois de alterar skins ou plugins localmente:

```bash
git status
./deploy/deploy.sh
```

O deploy é idempotente. Configurações de plugins que já existem no servidor não
são sobrescritas (`config.inc.php` de cada plugin é preservado). Uma configuração
inicial só é criada quando o arquivo ainda não existe.

## Depois de atualizar o Roundcube pelo HestiaCP

Atualizações do HestiaCP usam `v-add-sys-roundcube`, que substitui arquivos do
core e pode rodar `composer update`. Rode o deploy novamente após a atualização:

```bash
./deploy/deploy.sh
```

Isso reaplica o include de configuração, as skins, os plugins e as dependências.

## Instalação e atualização

O mesmo comando serve para a primeira instalação e para atualizações. Ele é
idempotente: reaplica arquivos, preserva configurações existentes e só executa as
migrações de banco que ainda não foram aplicadas.

## Migrações de banco

Depois de copiar os plugins, o deploy verifica o controle de versão do próprio
Roundcube para cada plugin com diretório `SQL` ou `drivers/database/SQL`:

- se a versão do plugin ainda não existe na tabela `system`, roda
  `bin/initdb.sh` e aplica o `*.initial.sql` (primeira instalação);
- se a versão já existe, roda `bin/updatedb.sh` e aplica somente as migrações
  com versão maior que a registrada;
- o arquivo é escolhido conforme o banco (`mysql`, `postgres` ou `sqlite`).

O calendário usa o pacote `calendar-database` (driver de banco), o `libkolab`
usa `libkolab` e o `user` usa `user`; esses nomes batem com as chaves gravadas
em `system` pelos respectivos `*.initial.sql`.

Para adicionar uma tabela/coluna nova em um plugin, crie uma migração versionada,
por exemplo:

```text
plugins/user/SQL/mysql/2026120100.sql
plugins/user/SQL/postgres/2026120100.sql
plugins/user/SQL/sqlite/2026120100.sql
```

No próximo deploy ela é aplicada automaticamente. O nome deve seguir o padrão de
versão usado pelo Roundcube (`AAAAMMDDNN.sql`) e precisa existir para o banco em
uso no servidor.

Para adicionar um plugin novo (não os cinco atuais), coloque-o em `src/plugins`,
inclua o nome em `RC_PLUGINS` no `deploy/deploy.env` e em `$rcDeployPlugins` no
`deploy/config/rc-deploy.inc.php`. O deploy detecta automaticamente diretórios
`SQL` e `drivers/database/SQL` do plugin.

## Backup e rollback

Cada execução cria um diretório em:

```text
/root/roundcube-deploy-backups/<data-hora>/
```

Ele contém as skins/plugins substituídos, `config.inc.php`, o include anterior,
`composer.json`/`composer.lock`, o `vendor` (quando o deploy vai mexer nele) e um
dump do banco `roundcube`.

Se algo falhar no meio do deploy, a restauração é automática. Para restaurar um
backup antigo manualmente:

```bash
/root/roundcube-deploy-backups/20261103-140000/restore.sh
```

Os backups mais antigos são removidos conforme `RC_KEEP_BACKUPS` (padrão: 5).

## CSS de produção

O deploy compila `styles.min.css`, `print.min.css`, `embed.min.css` e o CSS do
`libkolab` antes de enviar. O `devel_mode` fica desligado no servidor, então o
navegador não compila LESS em produção.

Se `npx` não estiver disponível, o deploy falha de propósito para não publicar
CSS antigo. Para publicar mesmo assim:

```bash
ALLOW_STALE_CSS=1 ./deploy/deploy.sh
```

## Skins e escolha do tema

O tema padrão de novos acessos é `carius` (`RC_SKIN`). As quatro skins ficam
disponíveis para os usuários escolherem, junto com `elastic`, que não é alterada.

## 2FA

O plugin `twofactor_gauthenticator` é instalado com `force_enrollment_users`
desligado e `users_allowed_2FA = null`, ou seja, todos podem ativar o 2FA
voluntariamente. Para restringir ou forçar usuários, edite no servidor:

```text
/var/lib/roundcube/plugins/twofactor_gauthenticator/config.inc.php
```

O deploy não sobrescreve esse arquivo depois do primeiro.

## Verificação

Após o deploy, abra o webmail do HestiaCP e confira:

- login e leitura de e-mail (IMAP continua sendo o Dovecot do servidor)
- troca de tema em Configurações
- aba Calendário
- ausência de erros em `/var/log/roundcube/error.log`

Se `RC_HEALTH_URL` estiver definido, o deploy também faz uma requisição HTTP de
verificação e falha se a página não responder.
