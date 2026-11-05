#!/usr/bin/env bash
# Restore a per-Hestia-user Roundcube backup from Cloudflare R2.
#
# The Roundcube numeric user_id is resolved by email address on the target,
# then all related rows are remapped (contacts/groups, calendars/events/
# attachments, identities, responses, settings).

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
# shellcheck disable=SC1091
. "$SCRIPT_DIR/rc-backup-lib.sh"

rc_load_env

USER=""
TIMESTAMP=""
ARCHIVE=""
MODE="replace"
DRY_RUN=0
YES=0
NO_VERIFY=0
EMAILS=""
CONFIG="$RC_CONFIG"
DSN="${RC_DB_DSN:-}"

usage() {
    cat <<'TXT'
Uso: rc-restore.sh --user USUARIO [opções]
     rc-restore.sh --file /caminho/backup.tar.gz [opções]

  --user USUARIO        Usuário da Hestia cujo backup será restaurado.
  --timestamp DATA      Timestamp específico (AAAAMMDD-HHMMSS); padrão: o mais recente.
  --file ARQUIVO        Usa um arquivo local em vez de baixar do R2.
  --mode replace|merge  replace apaga os dados atuais do email; merge só insere.
  --emails a@x,b@x      Restaura apenas estes emails do backup.
  --dry-run             Mostra o mapeamento sem gravar nada.
  --yes                 Confirma o modo replace (obrigatório sem --dry-run).
  --no-verify           Não exige o checksum .sha256.
  --config ARQUIVO      config.inc.php do Roundcube.
  --dsn DSN             DSN PDO, para testes.
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
        --mode) MODE="${2:?--mode exige um valor}"; shift ;;
        --mode=*) MODE="${1#*=}" ;;
        --emails) EMAILS="${2:?--emails exige um valor}"; shift ;;
        --emails=*) EMAILS="${1#*=}" ;;
        --dry-run) DRY_RUN=1 ;;
        --yes) YES=1 ;;
        --no-verify) NO_VERIFY=1 ;;
        --config) CONFIG="${2:?--config exige um valor}"; shift ;;
        --config=*) CONFIG="${1#*=}" ;;
        --dsn) DSN="${2:?--dsn exige um valor}"; shift ;;
        --dsn=*) DSN="${1#*=}" ;;
        --help | -h) usage; exit 0 ;;
        *) rc_die "opção desconhecida: $1" ;;
    esac
    shift
done

case "$MODE" in
    replace | merge) ;;
    *) rc_die "--mode deve ser replace ou merge" ;;
esac

if [ "$MODE" = "replace" ] && [ "$DRY_RUN" = 0 ] && [ "$YES" = 0 ]; then
    rc_die "o modo replace apaga os dados atuais do email; use --yes (ou --dry-run para simular)"
fi

if [ -z "$DSN" ] && [ "$(id -u)" != 0 ]; then
    rc_die "execute como root (ou use --dsn para testes locais)"
fi

rc_require_cmd "$PHP_BIN"
rc_require_cmd tar
rc_require_cmd gzip
rc_require_cmd sha256sum

PHP_DATA="$(rc_data_php)"
rc_lock

tmp="$(rc_tmpdir)"
archive_file=""

if [ -n "$ARCHIVE" ]; then
    [ -f "$ARCHIVE" ] || rc_die "arquivo não encontrado: $ARCHIVE"
    archive_file="$ARCHIVE"
    checksum_file="$ARCHIVE.sha256"
else
    [ -n "$USER" ] || rc_die "informe --user ou --file"

    if [ -z "$TIMESTAMP" ]; then
        TIMESTAMP="$(rc_r2_list "$USER" | grep -E '^[0-9]{8}-[0-9]{6}\.tar\.gz$' | sort -r | head -1 | sed 's/\.tar\.gz$//' || true)"
        [ -n "$TIMESTAMP" ] || rc_die "nenhum backup encontrado para $USER"
    fi

    archive_file="$tmp/${TIMESTAMP}.tar.gz"
    checksum_file="$archive_file.sha256"

    rc_say "baixando $USER/$TIMESTAMP do R2"
    rc_r2_get "$USER" "${TIMESTAMP}.tar.gz" "$archive_file"

    if ! rc_r2_get "$USER" "${TIMESTAMP}.tar.gz.sha256" "$checksum_file" 2>/dev/null; then
        checksum_file=""
    fi
fi

if [ "$NO_VERIFY" = 0 ]; then
    if [ -n "$checksum_file" ] && [ -f "$checksum_file" ]; then
        expected_sha="$(awk 'NR==1 {print $1}' "$checksum_file")"
        actual_sha="$(sha256sum "$archive_file" | awk '{print $1}')"

        [ -n "$expected_sha" ] && [ "$expected_sha" = "$actual_sha" ] \
            || rc_die "checksum do arquivo de backup não confere"
        rc_say "checksum OK: $(basename "$archive_file")"
    else
        rc_die "checksum .sha256 ausente; use --no-verify para prosseguir mesmo assim"
    fi
fi

mkdir -p "$tmp/extract"
tar -xzf "$archive_file" -C "$tmp/extract"

[ -f "$tmp/extract/data.ser.gz" ] || rc_die "backup sem data.ser.gz"
[ -f "$tmp/extract/manifest.json" ] || rc_die "backup sem manifest.json"

if [ "$NO_VERIFY" = 0 ]; then
    rc_say "validando integridade do backup"
    "$PHP_BIN" "$PHP_DATA" verify \
        --data="$tmp/extract/data.ser.gz" \
        --manifest="$tmp/extract/manifest.json" > "$tmp/verify.json" \
        || {
            cat "$tmp/verify.json" >&2 || true
            rc_die "backup inválido"
        }
fi

rc_say "restaurando backup ${TIMESTAMP:-$ARCHIVE} (modo $MODE)"

php_args=(restore)

if [ -n "$DSN" ]; then
    php_args+=("--dsn=$DSN")
else
    php_args+=("--config=$CONFIG")
fi

php_args+=(
    "--data=$tmp/extract/data.ser.gz"
    "--manifest=$tmp/extract/manifest.json"
    "--mode=$MODE"
)

[ -n "$EMAILS" ] && php_args+=("--emails=$EMAILS")
[ "$DRY_RUN" = 1 ] && php_args+=("--dry-run")

"$PHP_BIN" "$PHP_DATA" "${php_args[@]}"
