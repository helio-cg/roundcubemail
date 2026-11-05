#!/usr/bin/env bash
# Per-Hestia-user backup of Roundcube data (contacts, calendars, identities,
# responses, preferences, plugin settings) to Cloudflare R2.
#
# Usage:
#   rc-backup.sh --all
#   rc-backup.sh --user alice
#   rc-backup.sh --user alice --emails alice@example.com   # test/one account

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
# shellcheck disable=SC1091
. "$SCRIPT_DIR/rc-backup-lib.sh"

rc_load_env

ALL=0
USERS=()
EMAILS_OVERRIDE=""
HESTIA_OVERRIDE=""
UPLOAD=1
PRUNE=1
OUTPUT_DIR="${RC_OUTPUT_DIR:-}"
CONFIG="$RC_CONFIG"
DSN="${RC_DB_DSN:-}"

usage() {
    cat <<'TXT'
Uso: rc-backup.sh [opções]

  --all                     Faz backup de todos os usuários da Hestia.
  --user USUARIO            Faz backup de um usuário da Hestia (pode repetir).
  --hestia-user USUARIO     Nome usado no teste com --emails.
  --emails a@x,b@x          Lista fixa de emails (teste/recuperação pontual).
  --config ARQUIVO          config.inc.php do Roundcube (padrão: /etc/roundcube/config.inc.php).
  --dsn DSN                 DSN PDO, para testes (mysql://... ou sqlite://...).
  --no-upload               Gera o arquivo localmente, sem enviar ao R2.
  --output-dir DIR          Diretório local quando --no-upload é usado.
  --no-prune                Não aplica retenção após o envio.
  --help                    Mostra esta ajuda.
TXT
}

while [ $# -gt 0 ]; do
    case "$1" in
        --all) ALL=1 ;;
        --user) USERS+=("${2:?--user exige um valor}"); shift ;;
        --user=*) USERS+=("${1#*=}") ;;
        --hestia-user) HESTIA_OVERRIDE="${2:?--hestia-user exige um valor}"; shift ;;
        --hestia-user=*) HESTIA_OVERRIDE="${1#*=}" ;;
        --emails) EMAILS_OVERRIDE="${2:?--emails exige um valor}"; shift ;;
        --emails=*) EMAILS_OVERRIDE="${1#*=}" ;;
        --config) CONFIG="${2:?--config exige um valor}"; shift ;;
        --config=*) CONFIG="${1#*=}" ;;
        --dsn) DSN="${2:?--dsn exige um valor}"; shift ;;
        --dsn=*) DSN="${1#*=}" ;;
        --no-upload) UPLOAD=0 ;;
        --output-dir) OUTPUT_DIR="${2:?--output-dir exige um valor}"; shift ;;
        --output-dir=*) OUTPUT_DIR="${1#*=}" ;;
        --no-prune) PRUNE=0 ;;
        --help | -h) usage; exit 0 ;;
        *) rc_die "opção desconhecida: $1" ;;
    esac
    shift
done

if [ -z "$DSN" ] && [ "$(id -u)" != 0 ]; then
    rc_die "execute como root (ou use --dsn para testes locais)"
fi

rc_require_cmd "$PHP_BIN"
rc_require_cmd tar
rc_require_cmd gzip
rc_require_cmd sha256sum

PHP_DATA="$(rc_data_php)"
rc_lock

RC_PHP_BASE=()
if [ -n "$DSN" ]; then
    RC_PHP_BASE+=("--dsn=$DSN")
else
    RC_PHP_BASE+=("--config=$CONFIG")
fi

backup_user() {
    local user="$1"
    local emails="$2"
    local stamp archive tmp name
    local -a php_args

    [ -n "$user" ] || return 0
    [ -n "$emails" ] || {
        rc_warn "usuário $user sem contas de email; ignorando"
        return 0
    }

    stamp="$(rc_timestamp)"
    tmp="$(rc_tmpdir)"
    archive="${stamp}.tar.gz"

    php_args=(export "${RC_PHP_BASE[@]}")
    php_args+=(
        "--hestia-user=$user"
        "--emails=$emails"
        "--output=$tmp/data.ser.gz"
        "--manifest=$tmp/manifest.json"
    )

    rc_say "exportando dados Roundcube de $user ($emails)"

    if ! "$PHP_BIN" "$PHP_DATA" "${php_args[@]}" > "$tmp/export.json" 2> "$tmp/export.err"; then
        cat "$tmp/export.err" >&2 || true
        rc_die "falha ao exportar dados de $user"
    fi

    if ! "$PHP_BIN" -r '
        $manifest = json_decode((string) file_get_contents($argv[1]), true);
        exit(is_array($manifest) && !empty($manifest["emails"]) ? 0 : 1);
    ' "$tmp/manifest.json"; then
        rc_warn "nenhuma conta Roundcube encontrada para $user; backup não gerado"
        return 0
    fi

    tar -C "$tmp" -czf "$tmp/$archive" data.ser.gz manifest.json
    (
        cd "$tmp"
        sha256sum "$archive" > "$archive.sha256"
    )

    if [ "$UPLOAD" = 0 ]; then
        OUTPUT_DIR="${OUTPUT_DIR:-$PWD/rc-backups}"
        mkdir -p "$OUTPUT_DIR/$user"
        chmod 700 "$OUTPUT_DIR/$user"
        cp -a "$tmp/$archive" "$tmp/$archive.sha256" "$OUTPUT_DIR/$user/"
        chmod 600 "$OUTPUT_DIR/$user/$archive" "$OUTPUT_DIR/$user/$archive.sha256"
        rc_say "backup local: $OUTPUT_DIR/$user/$archive"
        return 0
    fi

    rc_say "enviando $user/$archive para o R2"
    rc_r2_put "$tmp/$archive" "$user" "$archive"
    rc_r2_put "$tmp/$archive.sha256" "$user" "$archive.sha256"

    if [ "$RC_VERIFY_AFTER_UPLOAD" = 1 ]; then
        rc_verify_remote_copy "$user" "$tmp/$archive"
    fi

    if [ "$PRUNE" = 1 ]; then
        rc_prune_user "$user"
    fi

    rc_say "backup concluído: $user/$archive"
}

if [ -n "$EMAILS_OVERRIDE" ]; then
    USER="${HESTIA_OVERRIDE:-${USERS[0]:-}}"
    [ -n "$USER" ] || rc_die "use --hestia-user ou --user junto com --emails"
    backup_user "$USER" "$EMAILS_OVERRIDE"
    exit 0
fi

if [ "${#USERS[@]}" -eq 0 ] && [ "$ALL" = 0 ]; then
    rc_die "informe --all ou --user USUARIO"
fi

if [ "$ALL" = 1 ]; then
    mapfile -t USERS < <(rc_hestia_users)
fi

if [ "${#USERS[@]}" -eq 0 ]; then
    rc_die "nenhum usuário da Hestia encontrado"
fi

mapfile -t RC_ROUNDCUBE_EMAILS < <(
    "$PHP_BIN" "$PHP_DATA" list "${RC_PHP_BASE[@]}" 2>/dev/null |
        awk 'NF {print tolower($1)}' | sort -u
)

for user in "${USERS[@]}"; do
    declare -A emails_seen=()
    mapfile -t primary_emails < <(rc_hestia_emails_for_user "$user" | sort -u)

    for email in "${primary_emails[@]}"; do
        [ -n "$email" ] && emails_seen["$email"]=1
    done

    mapfile -t user_domains < <(rc_hestia_domains_for_user "$user" | sort -u)

    if [ "${#user_domains[@]}" -gt 0 ]; then
        for email in "${RC_ROUNDCUBE_EMAILS[@]}"; do
            [ -n "$email" ] || continue
            domain="${email#*@}"

            for user_domain in "${user_domains[@]}"; do
                if [ "$domain" = "$user_domain" ]; then
                    emails_seen["$email"]=1
                    break
                fi
            done
        done
    fi

    if [ "${#emails_seen[@]}" -eq 0 ]; then
        rc_warn "usuário $user sem contas de email; ignorando"
        continue
    fi

    EMAILS="$(printf '%s\n' "${!emails_seen[@]}" | sort | paste -sd, -)"
    backup_user "$user" "$EMAILS"
done
