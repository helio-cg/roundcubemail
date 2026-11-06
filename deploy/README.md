# Deploy de produção (HestiaCP)

Este deploy instala as skins e plugins deste repositório no Roundcube que o
HestiaCP já serve. E-mail não faz parte do deploy: nenhum diretório de mensagens,
conta ou domínio é lido ou alterado. A única exceção é a etapa de
Spamhaus/unbound (`RC_SPAMHAUS_UNBOUND`, ligada no `deploy.env.example`), que
mexe em `/etc/resolv.conf` e no DNSBL do Exim.

## O que o deploy altera

- `/var/lib/roundcube/skins/{canga,carius,iguatu}`
- `/var/lib/roundcube/plugins/{calendar,libcalendaring,libkolab,twofactor_gauthenticator,user}`
- `/etc/roundcube/rc-deploy.inc.php` (novo include de configuração)
- um bloco marcado `BEGIN rc-deploy` no fim de `/etc/roundcube/config.inc.php`
- dependências Composer que faltarem em `/var/lib/roundcube/vendor`
- somente o banco `roundcube` (preferências, contatos, calendário), via tabelas
  dos próprios plugins
- ferramentas de backup/restauração em `/usr/local/lib/roundcube-backup` e os
  comandos `rc-backup`, `rc-restore` e `rc-backup-verify` (configuração em
  `/etc/roundcube/rc-backup.env`)

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

## Spamhaus com resolvedor local (opcional)

Quando o servidor consulta o `zen.spamhaus.org` por um resolvedor público, o
Spamhaus responde `127.255.255.x` para **todas** as consultas. O Exim interpreta
isso como "IP listado" e rejeita o e-mail externo inteiro. Para usar o Spamhaus
com segurança é preciso um resolvedor recursivo próprio.

Com `RC_SPAMHAUS_UNBOUND=1` no `deploy/deploy.env`, o deploy:

1. instala o `unbound` (se faltar) e o configura em `127.0.0.1:53`;
2. valida que o Spamhaus responde pelo unbound (nunca `127.255.255.x`);
3. só então aponta `/etc/resolv.conf` para `127.0.0.1`;
4. reativa o DNSBL na Hestia e reinicia o Exim.

O script (`deploy/enable-spamhaus-unbound.sh`) é idempotente e autônomo: faz
backup em `/root/spamhaus-unbound-backups/<data-hora>/`, recusa-se a aplicar se
a saída direta para a porta 53 estiver bloqueada (caso em que o caminho é o DQS
com chave) e **reverte tudo sozinho** se qualquer verificação falhar. Se o
`zen.spamhaus.org` já estiver ativo e o resolvedor atual estiver bloqueado, ele
**remove o DNSBL imediatamente** para não rejeitar e-mail, e só o reativa depois
que o unbound responder. Ele também pode ser copiado e executado direto no
servidor, como root:

```bash
./enable-spamhaus-unbound.sh
```

Para conferir depois:

```bash
dig +short 2.0.0.127.zen.spamhaus.org @127.0.0.1   # esperado: 127.0.0.2
dig +short 2.0.0.127.zen.spamhaus.org              # usa /etc/resolv.conf
```

Rollback manual:

```bash
/root/spamhaus-unbound-backups/<data-hora>/rollback.sh
```

Se `/etc/resolv.conf` for regravado por cloud-init/resolvconf ou por um
`systemd-resolved` ativo, aponte-o de volta para `127.0.0.1` após o reboot (ou
desabilite o gerenciador).

## TLS no acesso local (opcional)

Com `RC_LOCAL_TLS=1`, o deploy acrescenta ao overlay:

```php
$config['imap_host'] = 'ssl://localhost:993';
$config['smtp_host'] = 'tls://localhost:587';
```

e relaxa `verify_peer`/`verify_peer_name` **apenas no loopback**, porque o
certificado do Dovecot/Exim é emitido para o nome público (`mail.<dominio>`),
não para `localhost`. Assim o Roundcube fala TLS com Dovecot e Exim sem sair
para a rede.

Requisitos: Dovecot ouvindo em 993 e Exim em 587 com STARTTLS (padrão no
Hestia). Se o login falhar após ativar, volte `RC_LOCAL_TLS=0` e rode o deploy
de novo. O bloco é gerado no build do payload, não fica no arquivo do
repositório, e é removido no próximo deploy com `RC_LOCAL_TLS=0`.

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

O tema padrão de novos acessos é `canga` (`RC_SKIN`). As três skins ficam
disponíveis para os usuários escolherem, junto com `elastic`, que não é alterada.

## 2FA e plugin user

O plugin `user` só liga `user_enable_skin_colors`; não há mais nada a ajustar.

O `twofactor_gauthenticator` é semeado com:

- `force_enrollment_users = false` (2FA voluntário);
- `users_allowed_2FA = null` (todos os usuários — necessário em multi-domínio);
- `whitelist = []` (sem bypass por IP);
- `twofactor_pref_encrypt = true` (segredos TOTP cifrados);
- `twofactor_lockout_delays` / `twofactor_lockout` (proteção contra força bruta).

O deploy não copia o `config.inc.php` que vem dentro do plugin: ele semeia
`deploy/config/plugins/twofactor_gauthenticator.php`. Para restringir ou forçar
usuários, edite no servidor:

```text
/var/lib/roundcube/plugins/twofactor_gauthenticator/config.inc.php
```

O deploy não sobrescreve esse arquivo depois do primeiro. Ele avisa se
`users_allowed_2FA` estiver restrito (por exemplo, aos domínios de exemplo) e se
o `des_key` estiver vazio ou no valor padrão.

Cuidados:

- `twofactor_pref_encrypt = true` usa o `des_key` do Roundcube. Mantenha o
  `des_key` estável: trocá-lo torna ilegíveis os segredos 2FA já cadastrados.
- `twofactor_trusted_proxies` fica vazio. Se o webmail estiver atrás de
  Cloudflare e você usar a whitelist por IP, inclua as faixas do Cloudflare;
  caso contrário a whitelist vê o IP do proxy.

## Login multi-domínio (URL única)

O webmail é um só para todos os domínios: o usuário entra em um endereço único
(ex.: `https://webmail.stmip.net`) e digita o **e-mail completo**
(`fulano@telhafm.com`, `outro@outrodominio.com`). Não é preciso criar
`webmail.<dominio>` para cada domínio.

Para isso funcionar, o deploy grava em `/etc/roundcube/rc-deploy.inc.php`:

```php
$config['username_domain'] = '';
```

Isso anula qualquer domínio fixo que o painel tenha configurado. Se
`username_domain` ficasse preenchido, o Roundcube completaria o login com um
domínio só e as contas dos demais domínios não autenticariam. Não altere esse
valor em servidores com mais de um domínio.

## Verificação

Após o deploy, abra o webmail do HestiaCP e confira:

- login e leitura de e-mail (IMAP continua sendo o Dovecot do servidor)
- troca de tema em Configurações
- aba Calendário
- ausência de erros em `/var/log/roundcube/error.log`

Se `RC_HEALTH_URL` estiver definido, o deploy também faz uma requisição HTTP de
verificação e falha se a página não responder.

## Backup dos dados Roundcube por usuário da Hestia (R2)

O backup normal da Hestia cobre `/home/<usuário>` (maildirs, sites, configs),
mas não o banco do Roundcube. Este conjunto de scripts faz backup **por usuário
da Hestia** dos dados que não estão nos maildirs:

- contatos, grupos de contatos e endereços coletados;
- calendários, eventos e anexos;
- identidades, respostas pré-configuradas e buscas salvas;
- preferências do Roundcube (inclui o segredo 2FA cifrado) e `user_settings`;
- dicionário, filestore e demais tabelas com `user_id`.

O backup não lê nem altera maildirs, contas ou domínios. Ele consulta a Hestia
(`v-list-users`, `v-list-mail-domains`, `v-list-mail-accounts`) para agrupar as
contas de e-mail de cada usuário e grava um arquivo por usuário no R2. Além das
contas listadas pela Hestia, entram usuários do Roundcube cujo domínio pertence
ao mesmo usuário (cobre aliases/catch-all já usados no webmail).

### O que é instalado

O deploy instala as ferramentas em `/usr/local/lib/roundcube-backup` e cria os
comandos:

- `rc-backup` — exporta e envia o backup de um usuário ou de todos;
- `rc-restore` — baixa, valida e restaura um backup;
- `rc-backup-verify` — valida checksum/gzip/manifesto e as relações internas.

A configuração fica em `/etc/roundcube/rc-backup.env`. No primeiro deploy, se
ainda não houver chaves do R2, o deploy pergunta os dados, testa leitura/escrita
no bucket e só então grava o arquivo com modo `600`.

### Primeiro deploy: configuração automática do R2

O `./deploy/deploy.sh` verifica se `/etc/roundcube/rc-backup.env` já tem chaves
do R2 (`RCLONE_CONFIG_*_ACCESS_KEY_ID`/`SECRET_ACCESS_KEY`). Se já tiver, segue
com o deploy normal, sem perguntar nada.

Se ainda não tiver, o primeiro deploy pergunta no terminal:

- Account ID do R2 (ou a URL completa do endpoint);
- Access Key ID e Secret Access Key;
- bucket e prefixo;
- se o `rclone` não estiver instalado, pergunta se pode instalar;
- se o bucket não existir, pergunta se pode criá-lo.

Antes de gravar a configuração final, o deploy faz um teste real de leitura e
escrita no bucket. Só então gera `/etc/roundcube/rc-backup.env` (modo `600`) com
as credenciais em variáveis `RCLONE_CONFIG_*`, sem depender de `rclone.conf`.

Para automação (sem terminal), defina as variáveis no `deploy.env` ou no
ambiente antes de rodar:

```bash
RC_R2_ACCOUNT_ID=...
RC_R2_ACCESS_KEY_ID=...
RC_R2_SECRET_ACCESS_KEY=...
RC_R2_BUCKET=roundcube-backups
RC_R2_PREFIX=roundcube-backups
./deploy/deploy.sh root@servidor
```

Controles úteis:

- `RC_CONFIGURE_R2=1` força reconfigurar mesmo que as chaves já existam;
- `RC_CONFIGURE_R2=0` não mexe no R2;
- `RC_R2_SKIP_SETUP=1` pula a configuração neste deploy (o backup não funciona
  até ser configurado);
- `RC_INSTALL_RCLONE=1` instala o rclone sem perguntar; `0` falha se ele faltar.

Se já existir um remote rclone com o nome configurado (padrão `r2:`), o deploy
reaproveita as chaves desse remote e pede apenas bucket e prefixo.

### Configurar o R2 manualmente


O backend recomendado é o `rclone`. Instale-o no servidor e crie o remote:

```bash
curl https://rclone.org/install.sh | bash
rclone config create r2 s3 provider=Cloudflare \
  access_key_id=SEU_ACCESS_KEY secret_access_key=SEU_SECRET \
  endpoint=https://SEU_ACCOUNT_ID.r2.cloudflarestorage.com region=auto
rclone lsd r2:
```

Crie o bucket privado e ajuste `/etc/roundcube/rc-backup.env`:

```ini
RC_R2_BACKEND=rclone
RC_R2_REMOTE=r2
RC_R2_BUCKET=roundcube-backups
RC_R2_PREFIX=roundcube-backups
RC_RETENTION_COUNT=14
RC_RETENTION_DAYS=90
RC_VERIFY_AFTER_UPLOAD=1
```

Alternativa sem `rclone.conf`, usando variáveis de ambiente (também funciona
quando o arquivo é lido pelo cron):

```ini
RCLONE_CONFIG_R2_TYPE=s3
RCLONE_CONFIG_R2_PROVIDER=Cloudflare
RCLONE_CONFIG_R2_ACCESS_KEY_ID=...
RCLONE_CONFIG_R2_SECRET_ACCESS_KEY=...
RCLONE_CONFIG_R2_ENDPOINT=https://SEU_ACCOUNT_ID.r2.cloudflarestorage.com
RCLONE_CONFIG_R2_REGION=auto
```

Mantenha o bucket privado e `/etc/roundcube/rc-backup.env` com modo `600`. O
manifesto inclui o `des_key` do Roundcube porque ele é necessário para decifrar
preferências e o segredo 2FA restaurados.

### Testar antes do cron

```bash
# Um usuário da Hestia (usa o mapeamento da Hestia)
rc-backup --user alice

# Teste sem R2 e sem Hestia (ambiente local)
RC_BACKEND=local RC_R2_LOCAL_DIR=/tmp/r2 \
  rc-backup --hestia-user alice --emails alice@example.com --no-upload \
  --output-dir /tmp/rc-backups

# Conferir o último backup de um usuário
rc-backup-verify --user alice

# Simular a restauração (não grava nada)
rc-restore --user alice --dry-run
```

### Restauração em um servidor novo

Depois de restaurar o usuário na Hestia e o banco do Roundcube, os IDs numéricos
de `users.user_id` podem ser diferentes. O `rc-restore` localiza (ou cria) o
usuário pelo **email** e usa o novo `user_id` para reinserir todos os dados
relacionados, remapeando contatos/grupos, calendários/eventos/anexos,
identidades, respostas etc.

```bash
# Último backup do usuário
rc-restore --user alice --mode replace --yes

# Um backup específico
rc-restore --user alice --timestamp 20261105-031500 --mode replace --yes

# Arquivo local
rc-restore --file /root/backups/alice-20261105.tar.gz --mode replace --yes
```

O modo `replace` apaga os dados atuais dos emails presentes no backup antes de
reinserir. Use `--dry-run` para ver o mapeamento antigo → novo sem gravar. O modo
`merge` apenas insere e pode duplicar registros se o destino já tiver dados.

Se o `des_key` do backup for diferente do Roundcube atual, a restauração avisa:
preferências cifradas e o 2FA podem não abrir. O ideal é manter o mesmo
`des_key` ou copiá-lo do backup para `/etc/roundcube/config.inc.php` de forma
consciente.

### Retenção e verificação

Cada execução do `rc-backup` aplica a retenção por usuário:

- `RC_RETENTION_COUNT` mantém os N backups mais recentes (padrão 14);
- `RC_RETENTION_DAYS` remove backups mais antigos que N dias (padrão 90).

A validação feita logo após o envio compara checksum e tamanho remotos. Para uma
verificação completa (download, sha256, gzip, manifesto e relações internas):

```bash
rc-backup-verify --user alice
rc-backup-verify --all --enforce-retention
```

### Cron sugerido

```cron
# /etc/cron.d/roundcube-backup
15 3 * * * root /usr/local/sbin/rc-backup --all >> /var/log/rc-roundcube-backup.cron.log 2>&1
30 4 * * 1 root /usr/local/sbin/rc-backup-verify --all >> /var/log/rc-roundcube-backup.cron.log 2>&1
```

O `rc-backup` usa `flock`: uma execução não atropela a outra. O log operacional
fica em `RC_LOG_FILE` (padrão `/var/log/rc-roundcube-backup.log`).
