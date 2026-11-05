#!/usr/bin/env bash
# Shared helpers for the Roundcube per-Hestia-user backup tools.

set -Eeuo pipefail

RC_TOOL_DIR="${RC_TOOL_DIR:-/usr/local/lib/roundcube-backup}"
RC_BACKUP_ENV="${RC_BACKUP_ENV:-/etc/roundcube/rc-backup.env}"
PHP_BIN="${PHP_BIN:-php}"
HESTIA_BIN="${HESTIA_BIN:-/usr/local/hestia/bin}"
RC_CONFIG="${RC_CONFIG:-/etc/roundcube/config.inc.php}"
RC_LOCK_FILE="${RC_LOCK_FILE:-/run/rc-roundcube-backup.lock}"
RC_LOG_FILE="${RC_LOG_FILE:-/var/log/rc-roundcube-backup.log}"
RC_RETENTION_COUNT="${RC_RETENTION_COUNT:-14}"
RC_RETENTION_DAYS="${RC_RETENTION_DAYS:-90}"
RC_VERIFY_AFTER_UPLOAD="${RC_VERIFY_AFTER_UPLOAD:-1}"
RC_R2_BACKEND="${RC_R2_BACKEND:-auto}"
RC_R2_REMOTE="${RC_R2_REMOTE:-r2}"
RC_R2_BUCKET="${RC_R2_BUCKET:-}"
RC_R2_PREFIX="${RC_R2_PREFIX:-roundcube-backups}"
RC_R2_LOCAL_DIR="${RC_R2_LOCAL_DIR:-}"
RC_R2_ENDPOINT="${RC_R2_ENDPOINT:-}"

rc_log() {
    local level="$1"
    shift
    local message="$*"
    local line
    line="$(date -u +%Y-%m-%dT%H:%M:%SZ) [${level}] ${message}"

    if [ -n "$RC_LOG_FILE" ] && { [ -w "$RC_LOG_FILE" ] || { [ ! -e "$RC_LOG_FILE" ] && [ -w "$(dirname "$RC_LOG_FILE")" ]; }; }; then
        printf '%s\n' "$line" >> "$RC_LOG_FILE"
    fi

    if [ "$level" = "ERROR" ]; then
        printf '%s\n' "$line" >&2
    else
        printf '%s\n' "$line"
    fi
}

rc_say() { rc_log INFO "$*"; }
rc_warn() { rc_log WARN "$*"; }
rc_die() { rc_log ERROR "$*"; exit 1; }

RC_TMP_DIRS=()

rc_tmpdir() {
    local dir
    dir="$(mktemp -d "${RC_TMP_ROOT:-/tmp}/rc-roundcube-backup.XXXXXX")"
    RC_TMP_DIRS+=("$dir")
    printf '%s\n' "$dir"
}

rc_cleanup() {
    local dir
    for dir in "${RC_TMP_DIRS[@]:-}"; do
        [ -n "$dir" ] && rm -rf "$dir"
    done
    return 0
}

trap rc_cleanup EXIT

rc_load_env() {
    local env_file="${RC_BACKUP_ENV:-}"

    if [ -n "${RC_BACKUP_ENV_FILE:-}" ]; then
        env_file="$RC_BACKUP_ENV_FILE"
    fi

    if [ -n "$env_file" ] && [ -f "$env_file" ]; then
        set -a
        # shellcheck disable=SC1090
        . "$env_file"
        set +a
    fi

    RC_TOOL_DIR="${RC_TOOL_DIR:-/usr/local/lib/roundcube-backup}"
    PHP_BIN="${PHP_BIN:-php}"
    HESTIA_BIN="${HESTIA_BIN:-/usr/local/hestia/bin}"
    RC_CONFIG="${RC_CONFIG:-/etc/roundcube/config.inc.php}"
    RC_LOCK_FILE="${RC_LOCK_FILE:-/run/rc-roundcube-backup.lock}"
    RC_LOG_FILE="${RC_LOG_FILE:-/var/log/rc-roundcube-backup.log}"
    RC_RETENTION_COUNT="${RC_RETENTION_COUNT:-14}"
    RC_RETENTION_DAYS="${RC_RETENTION_DAYS:-90}"
    RC_VERIFY_AFTER_UPLOAD="${RC_VERIFY_AFTER_UPLOAD:-1}"
    RC_R2_BACKEND="${RC_R2_BACKEND:-auto}"
    RC_R2_REMOTE="${RC_R2_REMOTE:-r2}"
    RC_R2_PREFIX="${RC_R2_PREFIX:-roundcube-backups}"
}

rc_require_cmd() {
    command -v "$1" >/dev/null 2>&1 || rc_die "comando não encontrado: $1"
}

rc_require_root() {
    [ "$(id -u)" = 0 ] || rc_die "execute como root"
}

rc_data_php() {
    local candidate="${RC_DATA_PHP:-}"

    if [ -n "$candidate" ]; then
        printf '%s\n' "$candidate"
        return 0
    fi

    for candidate in "$RC_TOOL_DIR/rc-data.php" "$(dirname "${BASH_SOURCE[0]}")/rc-data.php"; do
        if [ -f "$candidate" ]; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done

    rc_die "rc-data.php não encontrado (defina RC_DATA_PHP)"
}

rc_lock() {
    mkdir -p "$(dirname "$RC_LOCK_FILE")"
    exec 9>"$RC_LOCK_FILE"
    flock -n 9 || rc_die "outro backup/restauração já está em execução ($RC_LOCK_FILE)"
}

rc_timestamp() {
    date -u +%Y%m%d-%H%M%S
}

rc_timestamp_epoch() {
    local ts="$1"
    local formatted="${ts:0:4}-${ts:4:2}-${ts:6:2} ${ts:9:2}:${ts:11:2}:${ts:13:2}"

    date -u -d "$formatted" +%s 2>/dev/null || printf '0\n'
}

rc_hestia_users() {
    if [ -x "$HESTIA_BIN/v-list-users" ]; then
        "$HESTIA_BIN/v-list-users" plain 2>/dev/null | awk 'NF && toupper($1) != "USER" {print $1}' | sort -u
        return 0
    fi

    if [ -d /usr/local/hestia/data/users ]; then
        find /usr/local/hestia/data/users -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -u
        return 0
    fi

    return 1
}

rc_hestia_emails_for_user() {
    local user="$1"
    local domain account line base

    if [ -x "$HESTIA_BIN/v-list-mail-domains" ] && [ -x "$HESTIA_BIN/v-list-mail-accounts" ]; then
        while IFS= read -r domain; do
            domain="${domain,,}"
            [ -z "$domain" ] && continue

            while IFS= read -r line; do
                account="${line%%[[:space:]]*}"
                account="${account,,}"

                case "$account" in
                    '' | account | user | domain | username) continue ;;
                esac

                if [[ "$account" == *@* ]]; then
                    printf '%s\n' "$account"
                else
                    printf '%s@%s\n' "$account" "$domain"
                fi
            done < <("$HESTIA_BIN/v-list-mail-accounts" "$user" "$domain" plain 2>/dev/null || true)
        done < <("$HESTIA_BIN/v-list-mail-domains" "$user" plain 2>/dev/null | awk 'NF && toupper($1) != "DOMAIN" {print $1}')
        return 0
    fi

    # Fallback for a test environment without the Hestia CLI.
    base="/home/$user/mail"
    [ -d "$base" ] || return 0

    while IFS= read -r domain; do
        while IFS= read -r line; do
            account="$(basename "$line")"
            case "$account" in
                cur | new | tmp) continue ;;
            esac
            printf '%s@%s\n' "${account,,}" "$(basename "$domain")"
        done < <(find "$domain" -mindepth 1 -maxdepth 1 -type d 2>/dev/null)
    done < <(find "$base" -mindepth 1 -maxdepth 1 -type d 2>/dev/null)
}

rc_hestia_domains_for_user() {
    local user="$1"

    if [ -x "$HESTIA_BIN/v-list-mail-domains" ]; then
        "$HESTIA_BIN/v-list-mail-domains" "$user" plain 2>/dev/null | awk 'NF && toupper($1) != "DOMAIN" {print tolower($1)}'
        return 0
    fi

    if [ -d "/home/$user/mail" ]; then
        find "/home/$user/mail" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | tr '[:upper:]' '[:lower:]'
    fi
}

rc_build_email_map() {
    local user email

    declare -gA RC_EMAIL_TO_USER=()

    while IFS= read -r user; do
        while IFS= read -r email; do
            [ -n "$email" ] && RC_EMAIL_TO_USER["$email"]="$user"
        done < <(rc_hestia_emails_for_user "$user")
    done < <(rc_hestia_users)
}

rc_user_for_email() {
    local email="${1,,}"

    if [ "${#RC_EMAIL_TO_USER[@]}" -eq 0 ]; then
        rc_build_email_map
    fi

    printf '%s\n' "${RC_EMAIL_TO_USER[$email]:-}"
}

rc_backend() {
    local backend="$RC_R2_BACKEND"

    if [ "$backend" = "auto" ]; then
        if command -v rclone >/dev/null 2>&1; then
            backend=rclone
        elif command -v aws >/dev/null 2>&1; then
            backend=aws
        elif [ -n "$RC_R2_LOCAL_DIR" ]; then
            backend=local
        else
            rc_die "instale rclone ou awscli, ou defina RC_R2_LOCAL_DIR/RC_R2_BACKEND=local"
        fi
    fi

    if [ "$backend" = "rclone" ]; then
        rc_require_cmd rclone
        [ -n "$RC_R2_BUCKET" ] || rc_die "RC_R2_BUCKET não definido"
    elif [ "$backend" = "aws" ]; then
        rc_require_cmd aws
        [ -n "$RC_R2_BUCKET" ] || rc_die "RC_R2_BUCKET não definido"
    elif [ "$backend" = "local" ]; then
        [ -n "$RC_R2_LOCAL_DIR" ] || rc_die "RC_R2_LOCAL_DIR não definido"
        mkdir -p "$RC_R2_LOCAL_DIR"
    else
        rc_die "RC_R2_BACKEND inválido: $backend"
    fi

    printf '%s\n' "$backend"
}

rc_r2_user_path() {
    local user="$1"
    local backend
    backend="$(rc_backend)"

    case "$backend" in
        rclone) printf '%s:%s/%s/%s\n' "$RC_R2_REMOTE" "$RC_R2_BUCKET" "$RC_R2_PREFIX" "$user" ;;
        aws) printf 's3://%s/%s/%s\n' "$RC_R2_BUCKET" "$RC_R2_PREFIX" "$user" ;;
        local) printf '%s/%s\n' "$RC_R2_LOCAL_DIR" "$user" ;;
    esac
}

rc_r2_put() {
    local src="$1" user="$2" name="$3"
    local backend
    backend="$(rc_backend)"
    local dest
    dest="$(rc_r2_user_path "$user")"

    case "$backend" in
        rclone)
            rclone copyto "$src" "$dest/$name" --s3-no-check-bucket
            ;;
        aws)
            local args=(s3 cp "$src" "$dest/$name" --only-show-errors)
            [ -n "$RC_R2_ENDPOINT" ] && args+=(--endpoint-url "$RC_R2_ENDPOINT")
            aws "${args[@]}"
            ;;
        local)
            mkdir -p "$dest"
            cp -a "$src" "$dest/$name"
            ;;
    esac
}

rc_r2_get() {
    local user="$1" name="$2" dest="$3"
    local backend
    backend="$(rc_backend)"
    local path
    path="$(rc_r2_user_path "$user")/$name"

    mkdir -p "$(dirname "$dest")"

    case "$backend" in
        rclone)
            rclone copyto "$path" "$dest" --s3-no-check-bucket
            ;;
        aws)
            local args=(s3 cp "$path" "$dest" --only-show-errors)
            [ -n "$RC_R2_ENDPOINT" ] && args+=(--endpoint-url "$RC_R2_ENDPOINT")
            aws "${args[@]}"
            ;;
        local)
            cp -a "$path" "$dest"
            ;;
    esac
}

rc_r2_list() {
    local user="$1"
    local backend
    backend="$(rc_backend)"
    local path
    path="$(rc_r2_user_path "$user")"

    case "$backend" in
        rclone)
            rclone lsf --files-only "$path/" 2>/dev/null || true
            ;;
        aws)
            local args=(s3 ls "$path/" --only-show-errors)
            [ -n "$RC_R2_ENDPOINT" ] && args+=(--endpoint-url "$RC_R2_ENDPOINT")
            aws "${args[@]}" 2>/dev/null | awk 'NF {print $NF}' || true
            ;;
        local)
            [ -d "$path" ] || return 0
            find "$path" -mindepth 1 -maxdepth 1 -type f -printf '%f\n' | sort
            ;;
    esac
}

rc_r2_delete() {
    local user="$1" name="$2"
    local backend
    backend="$(rc_backend)"
    local path
    path="$(rc_r2_user_path "$user")/$name"

    case "$backend" in
        rclone)
            rclone deletefile "$path" --s3-no-check-bucket 2>/dev/null || true
            ;;
        aws)
            local args=(s3 rm "$path" --only-show-errors)
            [ -n "$RC_R2_ENDPOINT" ] && args+=(--endpoint-url "$RC_R2_ENDPOINT")
            aws "${args[@]}" 2>/dev/null || true
            ;;
        local)
            rm -f "$path"
            ;;
    esac
}

rc_r2_cat() {
    local user="$1" name="$2"
    local backend
    backend="$(rc_backend)"
    local path
    path="$(rc_r2_user_path "$user")/$name"

    case "$backend" in
        rclone)
            rclone cat "$path" --s3-no-check-bucket 2>/dev/null || true
            ;;
        aws)
            local args=(s3 cp "$path" - --only-show-errors)
            [ -n "$RC_R2_ENDPOINT" ] && args+=(--endpoint-url "$RC_R2_ENDPOINT")
            aws "${args[@]}" 2>/dev/null || true
            ;;
        local)
            [ -f "$path" ] && cat "$path" || true
            ;;
    esac
}

rc_r2_size() {
    local user="$1" name="$2"
    local backend
    backend="$(rc_backend)"
    local path
    path="$(rc_r2_user_path "$user")/$name"

    case "$backend" in
        rclone)
            rclone lsl "$path" --s3-no-check-bucket 2>/dev/null | awk 'NR==1 {print $1}'
            ;;
        aws)
            local args=(s3api head-object --bucket "$RC_R2_BUCKET" --key "$RC_R2_PREFIX/$user/$name" --query ContentLength --output text)
            [ -n "$RC_R2_ENDPOINT" ] && args+=(--endpoint-url "$RC_R2_ENDPOINT")
            aws "${args[@]}" 2>/dev/null || true
            ;;
        local)
            [ -f "$path" ] && stat -c '%s' "$path" || true
            ;;
    esac
}

rc_verify_remote_copy() {
    local user="$1" archive="$2"
    local name
    name="$(basename "$archive")"
    local local_sha remote_sha local_size remote_size

    local_sha="$(sha256sum "$archive" | awk '{print $1}')"
    local_size="$(stat -c '%s' "$archive")"
    remote_sha="$(rc_r2_cat "$user" "$name.sha256" | awk 'NR==1 {print $1}')"
    remote_size="$(rc_r2_size "$user" "$name")"

    [ -n "$remote_sha" ] || rc_die "checksum remoto ausente para $name"
    [ "$remote_sha" = "$local_sha" ] || rc_die "checksum remoto diverge para $name"
    [ -n "$remote_size" ] || rc_die "tamanho remoto ausente para $name"
    [ "$remote_size" = "$local_size" ] || rc_die "tamanho remoto diverge para $name"

    rc_say "verificação remota OK: $user/$name"
}

rc_prune_user() {
    local user="$1"
    local files file name ts epoch now n
    local count="$RC_RETENTION_COUNT"
    local days="$RC_RETENTION_DAYS"

    case "$count" in '' | *[!0-9]*) count=14 ;; esac
    case "$days" in '' | *[!0-9]*) days=90 ;; esac
    [ "$count" -ge 1 ] || count=1

    now="$(date -u +%s)"
    n=0

    while IFS= read -r file; do
        name="$file"
        case "$name" in
            *.tar.gz) ;;
            *) continue ;;
        esac

        n=$((n + 1))
        ts="${name%.tar.gz}"
        epoch="$(rc_timestamp_epoch "$ts")"

        if [ "$n" -gt "$count" ] || { [ "$days" -gt 0 ] && [ "$epoch" -gt 0 ] && [ $((now - epoch)) -gt $((days * 86400)) ]; }; then
            rc_say "retenção: removendo $user/$name"
            rc_r2_delete "$user" "$name"
            rc_r2_delete "$user" "$name.sha256"
        fi
    done < <(rc_r2_list "$user" | grep -E '^[0-9]{8}-[0-9]{6}\.tar\.gz$' | sort -r)
}
