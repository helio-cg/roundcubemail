#!/usr/bin/env bash
# Validate the integrity of Roundcube per-user backups stored in R2.
# Downloads the archive, checks its .sha256, gzip and manifest, then validates
# the internal table relationships.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
# shellcheck disable=SC1091
. "$SCRIPT_DIR/rc-backup-lib.sh"

rc_load_env

USER=""
TIMESTAMP=""
ARCHIVE=""
ALL=0
ENFORCE_RETENTION=0
PHP_BIN="${PHP_BIN:-php}"

usage() {
    cat <<'TXT'
Uso: rc-backup-verify.sh --user USUARIO [--timestamp DATA]
     rc-backup-verify.sh --file /caminho/backup.tar.gz
     rc-backup-verify.sh --all [--enforce-retention]

  --user USUARIO          Usuário da Hestia.
  --timestamp DATA        Backup específico; padrão: o mais recente.
  --file ARQUIVO          Verifica um arquivo local.
  --all                   Verifica o backup mais recente de cada usuário da Hestia.
  --enforce-retention     Aplica a retenção também durante a verificação.
  --config ARQUIVO        config.inc.php do Roundcube (não usado na verificação).
TXT
}

while [ $# -gt 0 ]; do
    case "$1" in
        --user) USER="${2:?--user exige um valor}"; shift ;;
        --user=*) USER="${1#*=}" ;;
        --timestamp) TIMESTAMP="${2:?--timestamp exige um valor}"; shift ;;
        --timestamp=*) TIMESTAMP="${1#*=}" ;;
        --file) ARCHIVE="${2:?--file exige um valor}"; shift ;;
        --file=*) ARCHIVE="${1#*=}" ;;
        --all) ALL=1 ;;
        --enforce-retention) ENFORCE_RETENTION=1 ;;
        --help | -h) usage; exit 0 ;;
        *) rc_die "opção desconhecida: $1" ;;
    esac
    shift
done

rc_require_cmd "$PHP_BIN"
rc_require_cmd tar
rc_require_cmd gzip
rc_require_cmd sha256sum

PHP_DATA="$(rc_data_php)"
rc_lock

verify_file() {
    local archive="$1"
    local label="$2"
    local tmp extract checksum

    tmp="$(rc_tmpdir)"
    extract="$tmp/extract"
    checksum="$archive.sha256"
    mkdir -p "$extract"

    if [ -f "$checksum" ]; then
        expected_sha="$(awk 'NR==1 {print $1}' "$checksum")"
        actual_sha="$(sha256sum "$archive" | awk '{print $1}')"

        [ -n "$expected_sha" ] && [ "$expected_sha" = "$actual_sha" ] \
            || rc_die "checksum inválido: $label"
    else
        rc_warn "$label sem .sha256; verificando apenas gzip/manifesto"
    fi

    tar -xzf "$archive" -C "$extract"
    [ -f "$extract/data.ser.gz" ] || rc_die "$label sem data.ser.gz"
    [ -f "$extract/manifest.json" ] || rc_die "$label sem manifest.json"

    "$PHP_BIN" "$PHP_DATA" verify \
        --data="$extract/data.ser.gz" \
        --manifest="$extract/manifest.json"
}

verify_remote_user() {
    local user="$1"
    local timestamp="${2:-}"
    local strict="${3:-1}"
    local tmp archive

    if [ -z "$timestamp" ]; then
        timestamp="$(rc_r2_list "$user" | grep -E '^[0-9]{8}-[0-9]{6}\.tar\.gz$' | sort -r | head -1 | sed 's/\.tar\.gz$//' || true)"

        if [ -z "$timestamp" ]; then
            if [ "$strict" = 1 ]; then
                rc_die "nenhum backup encontrado para $user"
            fi
            rc_warn "nenhum backup encontrado para $user; ignorando"
            return 0
        fi
    fi

    tmp="$(rc_tmpdir)"
    archive="$tmp/${timestamp}.tar.gz"

    rc_say "baixando $user/$timestamp para verificação"
    rc_r2_get "$user" "${timestamp}.tar.gz" "$archive"

    if ! rc_r2_get "$user" "${timestamp}.tar.gz.sha256" "$archive.sha256" 2>/dev/null; then
        rc_warn "checksum remoto ausente para $user/$timestamp"
    fi

    verify_file "$archive" "$user/$timestamp"

    if [ "$ENFORCE_RETENTION" = 1 ]; then
        rc_prune_user "$user"
    fi
}

if [ -n "$ARCHIVE" ]; then
    [ -f "$ARCHIVE" ] || rc_die "arquivo não encontrado: $ARCHIVE"
    verify_file "$ARCHIVE" "$(basename "$ARCHIVE")"
    exit 0
fi

if [ "$ALL" = 1 ]; then
    mapfile -t users < <(rc_hestia_users)
    [ "${#users[@]}" -gt 0 ] || rc_die "nenhum usuário da Hestia encontrado"

    for user in "${users[@]}"; do
        verify_remote_user "$user" "" 0
    done
    exit 0
fi

[ -n "$USER" ] || rc_die "informe --user, --file ou --all"
verify_remote_user "$USER" "$TIMESTAMP"
