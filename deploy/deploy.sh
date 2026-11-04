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

say() { printf '[deploy] %s\n' "$*"; }
fail() { printf '[deploy] erro: %s\n' "$*" >&2; exit 1; }

[ -n "$DEPLOY_TARGET" ] || fail "informe o destino: DEPLOY_TARGET=root@servidor ou ./deploy/deploy.sh root@servidor"

"$ROOT/deploy/build.sh"

{
    printf 'RC_ROOT=%s\n' "${RC_ROOT:-/var/lib/roundcube}"
    printf 'RC_CONFIG_DIR=%s\n' "${RC_CONFIG_DIR:-/etc/roundcube}"
    printf 'RC_OWNER=%s\n' "${RC_OWNER:-hestiamail:www-data}"
    printf 'RC_SKIN=%s\n' "${RC_SKIN:-carius}"
    printf 'RC_SKINS=%s\n' "${RC_SKINS:-carius aurora iguatu canga}"
    printf 'RC_PLUGINS=%s\n' "${RC_PLUGINS:-calendar libcalendaring libkolab twofactor_gauthenticator user}"
    printf 'RC_VERSION_PREFIX=%s\n' "${RC_VERSION_PREFIX:-1.7.}"
    printf 'RC_ALLOW_VERSION_MISMATCH=%s\n' "${RC_ALLOW_VERSION_MISMATCH:-0}"
    printf 'RC_INSTALL_COMPOSER_DEPS=%s\n' "${RC_INSTALL_COMPOSER_DEPS:-1}"
    printf 'RC_COMPOSER_PACKAGES=%s\n' "${RC_COMPOSER_PACKAGES:-sabre/vobject:^4.5 pear/http_request2:^2.7 caxy/php-htmldiff:^0.1 lolli42/finediff:^1.0}"
    printf 'RC_BACKUP_DIR=%s\n' "${RC_BACKUP_DIR:-/root/roundcube-deploy-backups}"
    printf 'RC_KEEP_BACKUPS=%s\n' "${RC_KEEP_BACKUPS:-5}"
    printf 'RC_ROLLBACK_ON_FAILURE=%s\n' "${RC_ROLLBACK_ON_FAILURE:-1}"
    printf 'RC_HEALTH_URL=%s\n' "${RC_HEALTH_URL:-}"
} > "$PAYLOAD/deploy.env"

ssh_cmd=(ssh $DEPLOY_SSH_OPTS "$DEPLOY_TARGET")
remote() { "${ssh_cmd[@]}" "$@"; }
remote_root() {
    remote "${DEPLOY_SUDO:+$DEPLOY_SUDO }$*"
}

say "destino $DEPLOY_TARGET"
remote_root "install -d -m 700 '$REMOTE_STAGE'"
remote_root "rm -rf '$REMOTE_STAGE/payload'"
remote_root "install -d -m 700 '$REMOTE_STAGE/payload'"

say "enviando arquivos"
tar -C "$PAYLOAD" -czf - . | remote "${DEPLOY_SUDO:+$DEPLOY_SUDO }tar -xzf - -C '$REMOTE_STAGE/payload'"

say "executando instalação no servidor"
remote_root "bash '$REMOTE_STAGE/payload/remote-install.sh'"

remote_root "rm -rf '$REMOTE_STAGE/payload'"

say "deploy concluído"
