#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${DEPLOY_ENV_FILE:-$ROOT/deploy/deploy.env}"
PAYLOAD="$ROOT/.build/payload"

if [ -f "$ENV_FILE" ]; then
    set -a
    # shellcheck disable=SC1090
    . "$ENV_FILE"
    set +a
fi

DEPLOY_TARGET="${1:-${DEPLOY_TARGET:-}}"
REMOTE_STAGE="${REMOTE_STAGE:-${RC_ROOT:-/var/lib/roundcube}/.rc-deploy}"
DEPLOY_SUDO="${DEPLOY_SUDO-sudo -n}"
DEPLOY_SSH_OPTS="${DEPLOY_SSH_OPTS:--o ConnectTimeout=15}"
RC_BACKUP_ENV_FILE="${RC_BACKUP_ENV_FILE:-/etc/roundcube/rc-backup.env}"
RC_BACKUP_TOOL_DIR="${RC_BACKUP_TOOL_DIR:-/usr/local/lib/roundcube-backup}"
RC_CONFIGURE_R2="${RC_CONFIGURE_R2:-auto}"
RC_R2_SKIP_SETUP="${RC_R2_SKIP_SETUP:-0}"
RC_INSTALL_RCLONE="${RC_INSTALL_RCLONE:-ask}"

say() { printf '[deploy] %s\n' "$*"; }
fail() { printf '[deploy] erro: %s\n' "$*" >&2; exit 1; }

[ -n "$DEPLOY_TARGET" ] || fail "informe o destino: DEPLOY_TARGET=root@servidor ou ./deploy/deploy.sh root@servidor"

"$ROOT/deploy/build.sh"

{
    printf 'RC_ROOT=%s\n' "${RC_ROOT:-/var/lib/roundcube}"
    printf 'RC_CONFIG_DIR=%s\n' "${RC_CONFIG_DIR:-/etc/roundcube}"
    printf 'RC_OWNER=%s\n' "${RC_OWNER:-hestiamail:www-data}"
    printf 'RC_SKIN=%s\n' "${RC_SKIN:-carius}"
    printf 'RC_SKINS=%q\n' "${RC_SKINS:-carius iguatu canga}"
    printf 'RC_PLUGINS=%q\n' "${RC_PLUGINS:-calendar libcalendaring libkolab twofactor_gauthenticator user}"
    printf 'RC_VERSION_PREFIX=%s\n' "${RC_VERSION_PREFIX:-1.7.}"
    printf 'RC_ALLOW_VERSION_MISMATCH=%s\n' "${RC_ALLOW_VERSION_MISMATCH:-0}"
    printf 'RC_INSTALL_COMPOSER_DEPS=%s\n' "${RC_INSTALL_COMPOSER_DEPS:-1}"
    printf 'RC_COMPOSER_PACKAGES=%q\n' "${RC_COMPOSER_PACKAGES:-sabre/vobject:^4.5 pear/http_request2:^2.7 caxy/php-htmldiff:^0.1 lolli42/finediff:^1.0}"
    printf 'RC_BACKUP_DIR=%s\n' "${RC_BACKUP_DIR:-/root/roundcube-deploy-backups}"
    printf 'RC_KEEP_BACKUPS=%s\n' "${RC_KEEP_BACKUPS:-5}"
    printf 'RC_ROLLBACK_ON_FAILURE=%s\n' "${RC_ROLLBACK_ON_FAILURE:-1}"
    printf 'RC_HEALTH_URL=%s\n' "${RC_HEALTH_URL:-}"
    printf 'RC_SPAMHAUS_UNBOUND=%s\n' "${RC_SPAMHAUS_UNBOUND:-0}"
    printf 'RC_SPAMHAUS_DNSBL=%s\n' "${RC_SPAMHAUS_DNSBL:-zen.spamhaus.org}"
    printf 'RC_UNBOUND_ADDR=%s\n' "${RC_UNBOUND_ADDR:-127.0.0.1}"
    printf 'RC_UNBOUND_PORT=%s\n' "${RC_UNBOUND_PORT:-53}"
    printf 'RC_SPAMHAUS_KEEP_BACKUPS=%s\n' "${RC_SPAMHAUS_KEEP_BACKUPS:-5}"
    printf 'RC_LOCAL_TLS=%s\n' "${RC_LOCAL_TLS:-0}"
    printf 'RC_INSTALL_BACKUP_TOOLS=%s\n' "${RC_INSTALL_BACKUP_TOOLS:-1}"
    printf 'RC_BACKUP_ENV_FILE=%q\n' "$RC_BACKUP_ENV_FILE"
    printf 'RC_BACKUP_TOOL_DIR=%q\n' "$RC_BACKUP_TOOL_DIR"
} > "$PAYLOAD/deploy.env"

ssh_cmd=(ssh $DEPLOY_SSH_OPTS "$DEPLOY_TARGET")
remote() { "${ssh_cmd[@]}" "$@"; }
remote_root() {
    remote "${DEPLOY_SUDO:+$DEPLOY_SUDO }$*"
}

R2_ANSWER=""

r2_ask() {
    local prompt="$1" default="${2:-}" secret="${3:-0}"

    if ! { : < /dev/tty; } 2>/dev/null; then
        fail "R2 não configurado e sem terminal interativo; defina RC_R2_ACCOUNT_ID/RC_R2_ACCESS_KEY_ID/RC_R2_SECRET_ACCESS_KEY/RC_R2_BUCKET ou use RC_R2_SKIP_SETUP=1"
    fi

    if [ "$secret" = 1 ]; then
        if ! read -r -s -p "$prompt" R2_ANSWER < /dev/tty 2>/dev/null; then
            fail "R2 não configurado e sem terminal interativo; defina as variáveis RC_R2_* ou use RC_R2_SKIP_SETUP=1"
        fi
        printf '\n'
    elif [ -n "$default" ]; then
        if ! read -r -p "$prompt [$default]: " R2_ANSWER < /dev/tty 2>/dev/null; then
            fail "R2 não configurado e sem terminal interativo; defina as variáveis RC_R2_* ou use RC_R2_SKIP_SETUP=1"
        fi
        R2_ANSWER="${R2_ANSWER:-$default}"
    else
        if ! read -r -p "$prompt: " R2_ANSWER < /dev/tty 2>/dev/null; then
            fail "R2 não configurado e sem terminal interativo; defina as variáveis RC_R2_* ou use RC_R2_SKIP_SETUP=1"
        fi
    fi
}

r2_ask_yes_no() {
    local prompt="$1" default="${2:-s}"

    if ! { : < /dev/tty; } 2>/dev/null; then
        fail "R2 não configurado e sem terminal interativo; defina as variáveis RC_R2_* ou use RC_R2_SKIP_SETUP=1"
    fi

    if ! read -r -p "$prompt " R2_ANSWER < /dev/tty 2>/dev/null; then
        fail "R2 não configurado e sem terminal interativo; defina as variáveis RC_R2_* ou use RC_R2_SKIP_SETUP=1"
    fi
    R2_ANSWER="${R2_ANSWER:-$default}"

    case "${R2_ANSWER,,}" in
        s | sim | y | yes) return 0 ;;
        *) return 1 ;;
    esac
}

r2_env_has_keys() {
    remote_root "test -f '$RC_BACKUP_ENV_FILE' \
        && grep -Eq '^[[:space:]]*(export[[:space:]]+)?RCLONE_CONFIG_[A-Za-z0-9_]+_ACCESS_KEY_ID=' '$RC_BACKUP_ENV_FILE' \
        && grep -Eq '^[[:space:]]*(export[[:space:]]+)?RCLONE_CONFIG_[A-Za-z0-9_]+_SECRET_ACCESS_KEY=' '$RC_BACKUP_ENV_FILE'"
}

r2_env_has_backend() {
    remote_root "test -f '$RC_BACKUP_ENV_FILE' && grep -Eq '^[[:space:]]*(export[[:space:]]+)?RC_R2_BACKEND=(aws|local)[[:space:]]*$' '$RC_BACKUP_ENV_FILE'"
}

r2_remote_exists() {
    local remote="$1"
    remote_root "command -v rclone >/dev/null 2>&1 && rclone listremotes 2>/dev/null | grep -qx '${remote}:'"
}

r2_ensure_rclone() {
    if remote_root "command -v rclone >/dev/null 2>&1"; then
        return 0
    fi

    case "$RC_INSTALL_RCLONE" in
        0)
            fail "rclone não encontrado no servidor; instale-o ou use RC_INSTALL_RCLONE=1"
            ;;
        1)
            ;;
        ask | '')
            r2_ask_yes_no "rclone não encontrado no servidor. Instalar automaticamente? [S/n]" s \
                || fail "rclone não encontrado; instale-o manualmente e rode o deploy novamente"
            ;;
        *)
            fail "RC_INSTALL_RCLONE inválido: $RC_INSTALL_RCLONE"
            ;;
    esac

    if ! remote_root "command -v curl >/dev/null 2>&1"; then
        fail "curl não encontrado no servidor; instale o rclone manualmente e rode o deploy novamente"
    fi

    say "instalando rclone no servidor"
    remote_root "curl -fsSL https://rclone.org/install.sh | bash" \
        || fail "falha ao instalar o rclone"
}

r2_test_remote() {
    local remote_env="$1"

    remote_root "bash -s" <<REMOTE
set -Eeuo pipefail
set -a
. '$remote_env'
set +a

rclone lsd "\$RC_R2_REMOTE:\$RC_R2_BUCKET" >/dev/null 2>&1 || exit 10

testfile="\$RC_R2_PREFIX/.rc-write-test-\$\$"
printf 'ok\n' | rclone rcat "\$RC_R2_REMOTE:\$RC_R2_BUCKET/\$testfile" >/dev/null 2>&1 || exit 11
rclone deletefile "\$RC_R2_REMOTE:\$RC_R2_BUCKET/\$testfile" >/dev/null 2>&1 || exit 12
REMOTE
}

r2_create_bucket() {
    local remote_env="$1"

    remote_root "bash -s" <<REMOTE
set -Eeuo pipefail
set -a
. '$remote_env'
set +a
rclone mkdir "\$RC_R2_REMOTE:\$RC_R2_BUCKET"
REMOTE
}

r2_cleanup_tmp() {
    local tmp_env="${1:-}" remote_env="${2:-}"

    if [ -n "$tmp_env" ]; then
        rm -f "$tmp_env"
    fi

    if [ -n "$remote_env" ]; then
        remote_root "rm -f '$remote_env'" >/dev/null 2>&1 || true
    fi

    return 0
}

configure_r2() {
    [ "${RC_INSTALL_BACKUP_TOOLS:-1}" = 1 ] || return 0

    case "$RC_CONFIGURE_R2" in
        0)
            say "configuração do R2 ignorada (RC_CONFIGURE_R2=0)"
            return 0
            ;;
        auto | '') ;;
        1) ;;
        *)
            fail "RC_CONFIGURE_R2 inválido: $RC_CONFIGURE_R2"
            ;;
    esac

    if [ "$RC_CONFIGURE_R2" != 1 ] && { r2_env_has_keys || r2_env_has_backend; }; then
        say "R2 já configurado em $RC_BACKUP_ENV_FILE; mantendo"
        return 0
    fi

    if [ "$RC_R2_SKIP_SETUP" = 1 ]; then
        say "configuração do R2 ignorada (RC_R2_SKIP_SETUP=1)"
        return 0
    fi

    local remote="${RC_R2_REMOTE:-r2}"
    local account="${RC_R2_ACCOUNT_ID:-}"
    local endpoint="${RC_R2_ENDPOINT:-}"
    local access="${RC_R2_ACCESS_KEY_ID:-}"
    local secret="${RC_R2_SECRET_ACCESS_KEY:-}"
    local bucket="${RC_R2_BUCKET:-}"
    local prefix="${RC_R2_PREFIX:-roundcube-backups}"
    local reuse_rclone=0 tmp_env="" remote_env="" status=0

    [[ "$remote" =~ ^[A-Za-z][A-Za-z0-9_]*$ ]] \
        || fail "RC_R2_REMOTE deve conter apenas letras, números e _"

    say "R2 ainda não configurado; preparando o rclone"

    r2_ensure_rclone

    if [ -z "$access" ] && [ -z "$secret" ] && r2_remote_exists "$remote"; then
        reuse_rclone=1
        say "remote rclone '$remote' já existe; usando as chaves existentes"
    fi

    if [ "$reuse_rclone" = 0 ]; then
        if [ -z "$endpoint" ] && [ -z "$account" ]; then
            r2_ask "Account ID do R2 (ou URL completa do endpoint)"
            account="$R2_ANSWER"
        fi

        if [ -z "$access" ]; then
            r2_ask "Access Key ID do R2"
            access="$R2_ANSWER"
        fi

        if [ -z "$secret" ]; then
            r2_ask "Secret Access Key do R2" "" 1
            secret="$R2_ANSWER"
        fi
    fi

    if [ -z "$bucket" ]; then
        r2_ask "Bucket do R2"
        bucket="$R2_ANSWER"
    fi

    [ -n "$bucket" ] || fail "bucket do R2 não informado"

    if [ "$reuse_rclone" = 0 ]; then
        [ -n "$access" ] || fail "Access Key ID do R2 não informado"
        [ -n "$secret" ] || fail "Secret Access Key do R2 não informado"

        if [ -z "$endpoint" ]; then
            [ -n "$account" ] || fail "Account ID/endpoint do R2 não informado"
            endpoint="https://${account}.r2.cloudflarestorage.com"
        fi

        case "$endpoint" in
            http://* | https://*) ;;
            *) endpoint="https://${endpoint}" ;;
        esac
    fi

    prefix="${prefix#/}"
    prefix="${prefix%/}"

    tmp_env="$(mktemp)"
    chmod 600 "$tmp_env"
    remote_env="/tmp/rc-backup.env.$$"

    {
        printf '# Gerado pelo deploy em %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        printf 'RC_R2_BACKEND=rclone\n'
        printf 'RC_R2_REMOTE=%s\n' "$(printf '%q' "$remote")"
        printf 'RC_R2_BUCKET=%s\n' "$(printf '%q' "$bucket")"
        printf 'RC_R2_PREFIX=%s\n' "$(printf '%q' "$prefix")"
        printf 'RC_RETENTION_COUNT=%s\n' "${RC_RETENTION_COUNT:-14}"
        printf 'RC_RETENTION_DAYS=%s\n' "${RC_RETENTION_DAYS:-90}"
        printf 'RC_VERIFY_AFTER_UPLOAD=%s\n' "${RC_VERIFY_AFTER_UPLOAD:-1}"
        printf 'RC_CONFIG=%s\n' "$(printf '%q' "${RC_CONFIG_DIR:-/etc/roundcube}/config.inc.php")"

        if [ "$reuse_rclone" = 0 ]; then
            printf 'RCLONE_CONFIG_%s_TYPE=s3\n' "${remote^^}"
            printf 'RCLONE_CONFIG_%s_PROVIDER=Cloudflare\n' "${remote^^}"
            printf 'RCLONE_CONFIG_%s_ACCESS_KEY_ID=%s\n' "${remote^^}" "$(printf '%q' "$access")"
            printf 'RCLONE_CONFIG_%s_SECRET_ACCESS_KEY=%s\n' "${remote^^}" "$(printf '%q' "$secret")"
            printf 'RCLONE_CONFIG_%s_ENDPOINT=%s\n' "${remote^^}" "$(printf '%q' "$endpoint")"
            printf 'RCLONE_CONFIG_%s_REGION=auto\n' "${remote^^}"
        fi
    } > "$tmp_env"

    if ! remote_root "umask 077; cat > '$remote_env'" < "$tmp_env"; then
        r2_cleanup_tmp "$tmp_env"
        fail "não foi possível enviar a configuração do R2"
    fi

    if r2_test_remote "$remote_env"; then
        status=0
    else
        status=$?
    fi

    if [ "$status" = 10 ]; then
        if r2_ask_yes_no "Bucket '$bucket' não existe ou não está acessível. Criar agora? [S/n]" s; then
            if r2_create_bucket "$remote_env"; then
                if r2_test_remote "$remote_env"; then
                    status=0
                else
                    status=$?
                fi
            else
                status=10
            fi
        else
            r2_cleanup_tmp "$tmp_env" "$remote_env"
            fail "bucket '$bucket' não existe; crie-o ou informe outro bucket"
        fi
    fi

    if [ "$status" != 0 ]; then
        r2_cleanup_tmp "$tmp_env" "$remote_env"
        fail "não foi possível validar o acesso/escrita no R2 (código $status)"
    fi

    if ! remote_root "install -d -m 755 '$(dirname "$RC_BACKUP_ENV_FILE")'"; then
        r2_cleanup_tmp "$tmp_env" "$remote_env"
        fail "não foi possível criar o diretório de configuração do backup"
    fi

    if ! remote_root "install -m 600 '$remote_env' '$RC_BACKUP_ENV_FILE'"; then
        r2_cleanup_tmp "$tmp_env" "$remote_env"
        fail "não foi possível gravar $RC_BACKUP_ENV_FILE"
    fi

    remote_root "rm -f '$remote_env'" >/dev/null 2>&1 || true
    rm -f "$tmp_env"

    say "R2 configurado em $RC_BACKUP_ENV_FILE (bucket $bucket, prefix ${prefix:-<raiz>})"
}

say "destino $DEPLOY_TARGET"

configure_r2

remote_root "install -d -m 700 '$REMOTE_STAGE'"
remote_root "rm -rf '$REMOTE_STAGE/payload'"
remote_root "install -d -m 700 '$REMOTE_STAGE/payload'"

say "enviando arquivos"
tar -C "$PAYLOAD" -czf - . | remote "${DEPLOY_SUDO:+$DEPLOY_SUDO }tar -xzf - -C '$REMOTE_STAGE/payload'"

say "executando instalação no servidor"
remote_root "bash '$REMOTE_STAGE/payload/remote-install.sh'"

remote_root "rm -rf '$REMOTE_STAGE/payload'"

say "deploy concluído"
