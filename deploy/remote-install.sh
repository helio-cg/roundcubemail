#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PAYLOAD="$SCRIPT_DIR"

if [ -f "$SCRIPT_DIR/deploy.env" ]; then
    set -a
    # shellcheck disable=SC1091
    . "$SCRIPT_DIR/deploy.env"
    set +a
fi

RC_ROOT="${RC_ROOT:-/var/lib/roundcube}"
RC_CONFIG_DIR="${RC_CONFIG_DIR:-/etc/roundcube}"
RC_OWNER="${RC_OWNER:-hestiamail:www-data}"
RC_SKIN="${RC_SKIN:-carius}"
RC_SKINS="${RC_SKINS:-carius iguatu canga}"
RC_PLUGINS="${RC_PLUGINS:-calendar libcalendaring libkolab twofactor_gauthenticator user}"
RC_VERSION_PREFIX="${RC_VERSION_PREFIX:-1.7.}"
RC_ALLOW_VERSION_MISMATCH="${RC_ALLOW_VERSION_MISMATCH:-0}"
RC_INSTALL_COMPOSER_DEPS="${RC_INSTALL_COMPOSER_DEPS:-1}"
RC_COMPOSER_PACKAGES="${RC_COMPOSER_PACKAGES:-sabre/vobject:^4.5 pear/http_request2:^2.7 caxy/php-htmldiff:^0.1 lolli42/finediff:^1.0}"
RC_BACKUP_DIR="${RC_BACKUP_DIR:-/root/roundcube-deploy-backups}"
RC_KEEP_BACKUPS="${RC_KEEP_BACKUPS:-5}"
RC_ROLLBACK_ON_FAILURE="${RC_ROLLBACK_ON_FAILURE:-1}"
RC_HEALTH_URL="${RC_HEALTH_URL:-}"
RC_SKIP_DB_BACKUP="${RC_SKIP_DB_BACKUP:-0}"
RC_SPAMHAUS_UNBOUND="${RC_SPAMHAUS_UNBOUND:-0}"
RC_SPAMHAUS_DNSBL="${RC_SPAMHAUS_DNSBL:-zen.spamhaus.org}"
PHP_BIN="${PHP_BIN:-php}"

case "$RC_KEEP_BACKUPS" in
    '' | *[!0-9]* | 0) RC_KEEP_BACKUPS=1 ;;
esac

CONFIG_FILE="$RC_CONFIG_DIR/config.inc.php"
OVERLAY_FILE="$RC_CONFIG_DIR/rc-deploy.inc.php"
BK=""
INSTALL_STARTED=0
HAD_OVERLAY=0
DB_USER=""
DB_PASS=""
DB_HOST=""
DB_NAME=""

say() { printf '[remote] %s\n' "$*"; }
warn() { printf '[remote] aviso: %s\n' "$*" >&2; }

restore_backup() {
    [ -n "$BK" ] && [ -x "$BK/restore.sh" ] || return 0
    "$BK/restore.sh"
}

rollback_now() {
    [ "$INSTALL_STARTED" = 1 ] || return 0
    [ "$RC_ROLLBACK_ON_FAILURE" = 1 ] || return 0
    [ -n "$BK" ] || return 0
    warn "falha durante a instalação; restaurando backup $BK"
    restore_backup || warn "a restauração automática falhou; use $BK/restore.sh"
    INSTALL_STARTED=0
}

die() { printf '[remote] erro: %s\n' "$*" >&2; rollback_now; exit 1; }

on_error() {
    local rc=$?
    trap - ERR
    rollback_now
    exit "$rc"
}
trap on_error ERR

[ "$(id -u)" = 0 ] || die "execute como root"

[ -d "$RC_ROOT" ] || die "$RC_ROOT não existe"
[ -f "$RC_ROOT/program/include/iniset.php" ] || die "Roundcube não encontrado em $RC_ROOT"
[ -f "$CONFIG_FILE" ] || die "configuração não encontrada em $CONFIG_FILE"
[ -d "$RC_ROOT/skins" ] || die "$RC_ROOT/skins não existe"
[ -d "$RC_ROOT/plugins" ] || die "$RC_ROOT/plugins não existe"
command -v "$PHP_BIN" >/dev/null 2>&1 || die "php CLI não encontrado"
command -v tar >/dev/null 2>&1 || die "tar não encontrado"

case "$RC_ROOT" in
    / | /home | /home/*/mail* | /var/mail* | /var/vmail* | /etc/dovecot* | /etc/exim* | /etc/postfix*)
        die "recusando operar em $RC_ROOT (diretório de e-mail)"
        ;;
esac

rc_version="$(grep RCMAIL_VERSION "$RC_ROOT/program/include/iniset.php" | head -1 | cut -d "'" -f 4)"
say "Roundcube $rc_version em $RC_ROOT"

if [ "${rc_version#"$RC_VERSION_PREFIX"}" = "$rc_version" ] && [ "$RC_ALLOW_VERSION_MISMATCH" != 1 ]; then
    die "versão $rc_version incompatível com skins feitas para $RC_VERSION_PREFIX (use RC_ALLOW_VERSION_MISMATCH=1 para forçar)"
fi

parse_dsn() {
    "$PHP_BIN" -r '
        $config = [];
        $rcmail_config = [];
        include $argv[1];
        $dsn = $config["db_dsnw"] ?? $rcmail_config["db_dsnw"] ?? "";
        if (strpos($dsn, "mysql://") !== 0) {
            exit(1);
        }
        $parts = parse_url($dsn);
        echo urldecode($parts["user"] ?? ""), "\n";
        echo urldecode($parts["pass"] ?? ""), "\n";
        echo ($parts["host"] ?? "localhost"), "\n";
        echo ltrim(($parts["path"] ?? ""), "/"), "\n";
    ' "$CONFIG_FILE" 2>/dev/null
}

if dsn_fields="$(parse_dsn)"; then
    DB_USER="$(printf '%s\n' "$dsn_fields" | sed -n '1p')"
    DB_PASS="$(printf '%s\n' "$dsn_fields" | sed -n '2p')"
    DB_HOST="$(printf '%s\n' "$dsn_fields" | sed -n '3p')"
    DB_NAME="$(printf '%s\n' "$dsn_fields" | sed -n '4p')"
else
    warn "DSN MySQL não detectado; o banco do Roundcube não será copiado"
fi

dump_database() {
    [ -n "$DB_NAME" ] || return 1
    command -v mysqldump >/dev/null 2>&1 || return 1

    if mysqldump --single-transaction --quick --no-tablespaces \
        -h "$DB_HOST" -u "$DB_USER" --password="$DB_PASS" "$DB_NAME" > "$BK/roundcube.sql" 2>/dev/null; then
        return 0
    fi

    if mysqldump --single-transaction --quick \
        -h "$DB_HOST" -u "$DB_USER" --password="$DB_PASS" "$DB_NAME" > "$BK/roundcube.sql" 2>/dev/null; then
        return 0
    fi

    rm -f "$BK/roundcube.sql"
    return 1
}

write_restore_script() {
    local q_root q_config q_backup q_owner q_user q_pass q_host q_name
    q_root=$(printf '%q' "$RC_ROOT")
    q_config=$(printf '%q' "$RC_CONFIG_DIR")
    q_backup=$(printf '%q' "$BK")
    q_owner=$(printf '%q' "$RC_OWNER")
    q_user=$(printf '%q' "$DB_USER")
    q_pass=$(printf '%q' "$DB_PASS")
    q_host=$(printf '%q' "$DB_HOST")
    q_name=$(printf '%q' "$DB_NAME")

    {
        printf '%s\n' '#!/usr/bin/env bash' 'set -Eeuo pipefail'
        printf 'cp -a %s/config.inc.php %s/config.inc.php\n' "$q_backup" "$q_config"
        if [ "$HAD_OVERLAY" = 1 ]; then
            printf 'cp -a %s/rc-deploy.inc.php %s/rc-deploy.inc.php\n' "$q_backup" "$q_config"
        else
            printf 'rm -f %s/rc-deploy.inc.php\n' "$q_config"
        fi
        printf 'if [ -f %s/absent-skins.txt ]; then while IFS= read -r d; do if [ -n "$d" ]; then rm -rf %s/skins/"$d"; fi; done < %s/absent-skins.txt; fi\n' \
            "$q_backup" "$q_root" "$q_backup"
        printf 'if [ -f %s/absent-plugins.txt ]; then while IFS= read -r d; do if [ -n "$d" ]; then rm -rf %s/plugins/"$d"; fi; done < %s/absent-plugins.txt; fi\n' \
            "$q_backup" "$q_root" "$q_backup"
        printf 'for f in %s/skin-*.tar.gz; do if [ -e "$f" ]; then tar -C %s/skins -xzf "$f"; fi; done\n' "$q_backup" "$q_root"
        printf 'for f in %s/plugin-*.tar.gz; do if [ -e "$f" ]; then tar -C %s/plugins -xzf "$f"; fi; done\n' "$q_backup" "$q_root"
        printf 'if [ -f %s/composer.json ]; then cp -a %s/composer.json %s/composer.json; fi\n' "$q_backup" "$q_backup" "$q_root"
        printf 'if [ -f %s/composer.lock ]; then cp -a %s/composer.lock %s/composer.lock; fi\n' "$q_backup" "$q_backup" "$q_root"
        printf 'if [ -f %s/vendor.tar.gz ]; then rm -rf %s/vendor && tar -C %s -xzf %s/vendor.tar.gz; fi\n' \
            "$q_backup" "$q_root" "$q_root" "$q_backup"
        printf 'if [ -f %s/roundcube.sql ] && [ -n %s ]; then mysql -h %s -u %s --password=%s %s < %s/roundcube.sql; fi\n' \
            "$q_backup" "$q_name" "$q_host" "$q_user" "$q_pass" "$q_name" "$q_backup"
        printf 'chown -R %s %s/skins %s/plugins %s/vendor %s/config.inc.php %s/rc-deploy.inc.php 2>/dev/null || true\n' \
            "$q_owner" "$q_root" "$q_root" "$q_root" "$q_config" "$q_config"
        printf 'chmod 640 %s/config.inc.php %s/rc-deploy.inc.php 2>/dev/null || true\n' "$q_config" "$q_config"
    } > "$BK/restore.sh"

    chmod 700 "$BK/restore.sh"
}

prune_backups() {
    [ -d "$RC_BACKUP_DIR" ] || return 0
    local n=0 dir
    while IFS= read -r dir; do
        n=$((n + 1))
        if [ "$n" -gt "$RC_KEEP_BACKUPS" ]; then
            rm -rf "$dir"
        fi
    done < <(find "$RC_BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -rn | cut -d' ' -f2-)
}

backup() {
    local stamp skin plugin
    stamp="$(date +%Y%m%d-%H%M%S)-$$"
    BK="$RC_BACKUP_DIR/$stamp"
    mkdir -p "$BK"
    chmod 700 "$BK"

    cp -a "$CONFIG_FILE" "$BK/config.inc.php"
    if [ -f "$OVERLAY_FILE" ]; then
        cp -a "$OVERLAY_FILE" "$BK/rc-deploy.inc.php"
        HAD_OVERLAY=1
    fi

    : > "$BK/absent-skins.txt"
    for skin in $RC_SKINS; do
        if [ -d "$RC_ROOT/skins/$skin" ]; then
            tar -C "$RC_ROOT/skins" -czf "$BK/skin-$skin.tar.gz" "$skin"
        else
            printf '%s\n' "$skin" >> "$BK/absent-skins.txt"
        fi
    done

    : > "$BK/absent-plugins.txt"
    for plugin in $RC_PLUGINS; do
        if [ -d "$RC_ROOT/plugins/$plugin" ]; then
            tar -C "$RC_ROOT/plugins" -czf "$BK/plugin-$plugin.tar.gz" "$plugin"
        else
            printf '%s\n' "$plugin" >> "$BK/absent-plugins.txt"
        fi
    done

    if [ -f "$RC_ROOT/composer.json" ]; then
        cp -a "$RC_ROOT/composer.json" "$BK/composer.json"
    fi
    if [ -f "$RC_ROOT/composer.lock" ]; then
        cp -a "$RC_ROOT/composer.lock" "$BK/composer.lock"
    fi

    if [ "$RC_INSTALL_COMPOSER_DEPS" = 1 ] && ! php_deps_ok && [ -d "$RC_ROOT/vendor" ]; then
        say "copiando vendor para rollback"
        tar -C "$RC_ROOT" -czf "$BK/vendor.tar.gz" vendor
    fi

    if [ -n "$DB_NAME" ]; then
        if ! dump_database; then
            if [ "$RC_SKIP_DB_BACKUP" = 1 ]; then
                warn "não foi possível copiar o banco $DB_NAME; continuando por RC_SKIP_DB_BACKUP=1"
            else
                die "não foi possível copiar o banco $DB_NAME (use RC_SKIP_DB_BACKUP=1 para ignorar)"
            fi
        fi
    fi

    write_restore_script
    say "backup em $BK"
}

install_tree() {
    local kind="$1" name="$2" exclude="${3:-}"
    local src="$PAYLOAD/$kind/$name"
    local dest="$RC_ROOT/$kind/$name"

    [ -d "$src" ] || return 0
    mkdir -p "$dest"

    if command -v rsync >/dev/null 2>&1; then
        if [ -n "$exclude" ]; then
            rsync -a --delete --exclude="$exclude" "$src/" "$dest/"
        else
            rsync -a --delete "$src/" "$dest/"
        fi
    else
        if [ -n "$exclude" ]; then
            find "$dest" -mindepth 1 -maxdepth 1 ! -name "$exclude" -exec rm -rf {} +
            ( cd "$src" && tar --exclude="$exclude" -cf - . ) | tar -C "$dest" -xf -
        else
            rm -rf "$dest"
            mkdir -p "$dest"
            cp -a "$src/." "$dest/"
        fi
    fi
}

seed_config() {
    local plugin="$1"
    local dest="$RC_ROOT/plugins/$plugin/config.inc.php"

    [ -f "$dest" ] && return 0
    if [ -f "$PAYLOAD/plugin-configs/$plugin.php" ]; then
        install -m 640 "$PAYLOAD/plugin-configs/$plugin.php" "$dest"
    elif [ -f "$RC_ROOT/plugins/$plugin/config.inc.php.dist" ]; then
        install -m 640 "$RC_ROOT/plugins/$plugin/config.inc.php.dist" "$dest"
    fi
}

php_deps_ok() {
    "$PHP_BIN" -r '
        $root = $argv[1];
        if (!is_file($root . "/vendor/autoload.php")) {
            exit(1);
        }
        require $root . "/vendor/autoload.php";
        exit(class_exists("Sabre\\VObject\\Component\\VCalendar")
            && class_exists("HTTP_Request2")
            && class_exists("Caxy\\HtmlDiff\\HtmlDiff")
            && class_exists("cogpowered\\FineDiff\\Diff") ? 0 : 1);
    ' "$RC_ROOT" >/dev/null 2>&1
}

find_composer() {
    local candidate
    for candidate in "$(command -v composer 2>/dev/null || true)" /root/.composer/composer /usr/local/bin/composer /home/*/.composer/composer; do
        if [ -n "$candidate" ] && [ -x "$candidate" ]; then
            printf '%s' "$candidate"
            return 0
        fi
    done

    if [ -x /usr/local/hestia/bin/v-add-user-composer ]; then
        local u
        for u in admin root; do
            /usr/local/hestia/bin/v-add-user-composer "$u" >/dev/null 2>&1 || true
        done
        u="$(/usr/local/hestia/bin/v-list-users plain 2>/dev/null | awk 'NR==1{print $1}')"
        if [ -n "$u" ]; then
            /usr/local/hestia/bin/v-add-user-composer "$u" >/dev/null 2>&1 || true
        fi
        for candidate in /home/*/.composer/composer /root/.composer/composer /usr/local/bin/composer; do
            if [ -x "$candidate" ]; then
                printf '%s' "$candidate"
                return 0
            fi
        done
    fi

    return 1
}

install_php_deps() {
    local composer_bin

    if php_deps_ok; then
        say "dependências PHP já presentes"
        return 0
    fi

    [ "$RC_INSTALL_COMPOSER_DEPS" = 1 ] || die "dependências PHP ausentes em $RC_ROOT/vendor"

    composer_bin="$(find_composer)" || die "composer não encontrado no servidor"
    say "instalando dependências PHP dos plugins"

    cd "$RC_ROOT"
    export COMPOSER_ALLOW_SUPERUSER=1

    # shellcheck disable=SC2086
    if ! "$PHP_BIN" "$composer_bin" require --no-update --no-interaction --no-progress $RC_COMPOSER_PACKAGES; then
        die "composer require falhou"
    fi

    if ! "$PHP_BIN" "$composer_bin" update --no-dev --no-interaction --no-progress --with-dependencies \
        sabre/vobject pear/http_request2 caxy/php-htmldiff lolli42/finediff; then
        die "composer update falhou"
    fi

    php_deps_ok || die "as dependências PHP continuam ausentes após o composer"
}

append_config_include() {
    "$PHP_BIN" -r '
        $file = $argv[1];
        $overlay = $argv[2];
        $content = file_get_contents($file);
        if ($content === false) {
            exit(1);
        }
        if (substr(rtrim($content), -2) === "?>") {
            $content = preg_replace("/\\?>\\s*$/", "", $content);
        }
        if (strpos($content, "BEGIN rc-deploy") === false) {
            $content = rtrim($content) . "\n\n// BEGIN rc-deploy\n"
                . "if (is_file(" . var_export($overlay, true) . ")) {\n"
                . "    include " . var_export($overlay, true) . ";\n"
                . "}\n// END rc-deploy\n";
            file_put_contents($file, $content);
        }
    ' "$CONFIG_FILE" "$OVERLAY_FILE"
}

system_has_version() {
    local package="$1"

    ( cd "$RC_ROOT" && "$PHP_BIN" -r '
        define("INSTALL_PATH", getcwd() . "/");
        require INSTALL_PATH . "program/include/clisetup.php";
        exit(rcmail_utils::db_version($argv[1]) ? 0 : 1);
    ' "$package" ) >/dev/null 2>&1
}

migrate_plugin() {
    local plugin="$1" dir="$2" package="$3"
    local full="$RC_ROOT/plugins/$plugin/$dir"

    [ -d "$full" ] || return 0

    if system_has_version "$package"; then
        say "aplicando migrações pendentes de $plugin"
        ( cd "$RC_ROOT" && "$PHP_BIN" bin/updatedb.sh --dir="$full" --package="$package" ) \
            || die "updatedb falhou para $plugin"
    else
        [ -x "$RC_ROOT/bin/initdb.sh" ] || die "bin/initdb.sh não encontrado"
        say "criando schema inicial de $plugin"
        ( cd "$RC_ROOT" && "$PHP_BIN" bin/initdb.sh --dir="$full" ) \
            || die "initdb falhou para $plugin"
    fi
}

run_plugin_migrations() {
    local plugin
    migrate_plugin libkolab SQL libkolab
    migrate_plugin calendar drivers/database/SQL calendar-database
    migrate_plugin user SQL user

    for plugin in $RC_PLUGINS; do
        case "$plugin" in
            libkolab | calendar | user) continue ;;
        esac
        migrate_plugin "$plugin" SQL "$plugin"
        migrate_plugin "$plugin" drivers/database/SQL "$plugin-database"
    done
}

reload_php() {
    local svc
    command -v systemctl >/dev/null 2>&1 || return 0
    while IFS= read -r svc; do
        [ -n "$svc" ] || continue
        systemctl reload "$svc" >/dev/null 2>&1 || true
    done < <(systemctl list-units --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}')
}

health_check() {
    [ -n "$RC_HEALTH_URL" ] || return 0
    command -v curl >/dev/null 2>&1 || { warn "curl não encontrado; health check ignorado"; return 0; }
    say "health check $RC_HEALTH_URL"
    curl -fsSL --max-time 30 -o /dev/null "$RC_HEALTH_URL" \
        || die "health check falhou em $RC_HEALTH_URL"
}

backup

INSTALL_STARTED=1

for skin in $RC_SKINS; do
    install_tree skins "$skin"
done

for plugin in $RC_PLUGINS; do
    install_tree plugins "$plugin" config.inc.php
    seed_config "$plugin"
done

install -m 640 "$PAYLOAD/config/rc-deploy.inc.php" "$OVERLAY_FILE"
append_config_include

"$PHP_BIN" -l "$CONFIG_FILE" >/dev/null || die "sintaxe inválida em $CONFIG_FILE"
"$PHP_BIN" -l "$OVERLAY_FILE" >/dev/null || die "sintaxe inválida em $OVERLAY_FILE"

# URL única de webmail para vários domínios: o login precisa usar o endereço
# completo. Um username_domain fixo faria as contas dos outros domínios falharem.
effective_username_domain="$("$PHP_BIN" -r '
    $config = [];
    $rcmail_config = [];
    ob_start();
    include $argv[1];
    ob_end_clean();
    $v = $config["username_domain"] ?? ($rcmail_config["username_domain"] ?? "");
    if (is_array($v)) {
        $v = implode(",", array_filter($v, function ($x) { return $x !== ""; }));
    }
    echo trim((string) $v);
' "$CONFIG_FILE" 2>/dev/null || true)"
if [ -n "$effective_username_domain" ]; then
    die "username_domain fixo ($effective_username_domain) em $CONFIG_FILE impede login multi-domínio"
fi

effective_des_key="$("$PHP_BIN" -r '
    $config = [];
    $rcmail_config = [];
    ob_start();
    include $argv[1];
    ob_end_clean();
    echo trim((string) ($config["des_key"] ?? ($rcmail_config["des_key"] ?? "")));
' "$CONFIG_FILE" 2>/dev/null || true)"
case "$effective_des_key" in
    '' | 'rcmail-!24ByteDESkey*Str')
        warn "des_key padrão/vazio em $CONFIG_FILE; com twofactor_pref_encrypt=true os segredos 2FA usariam chave conhecida. Defina um des_key único e estável."
        ;;
esac

twofactor_config="$RC_ROOT/plugins/twofactor_gauthenticator/config.inc.php"
if [ -f "$twofactor_config" ]; then
    twofactor_allowed="$("$PHP_BIN" -r '
        $config = [];
        $rcmail_config = [];
        ob_start();
        include $argv[1];
        ob_end_clean();
        $v = $rcmail_config["users_allowed_2FA"] ?? ($config["users_allowed_2FA"] ?? null);
        if (is_array($v)) {
            echo implode(",", $v);
        } elseif ($v === null) {
            echo "*";
        } else {
            echo (string) $v;
        }
    ' "$twofactor_config" 2>/dev/null || true)"
    if [ "$twofactor_allowed" != '*' ]; then
        warn "twofactor_gauthenticator: users_allowed_2FA restrito a [$twofactor_allowed]; em multi-domínio use null para todos"
    fi
fi

install_php_deps

run_plugin_migrations

chown -R "$RC_OWNER" "$RC_ROOT/skins" "$RC_ROOT/plugins" "$RC_ROOT/vendor" 2>/dev/null || true
chown "$RC_OWNER" "$CONFIG_FILE" "$OVERLAY_FILE" "$RC_ROOT/composer.json" "$RC_ROOT/composer.lock" 2>/dev/null || true
find "$RC_ROOT/skins" "$RC_ROOT/plugins" -type d -exec chmod 755 {} +
find "$RC_ROOT/skins" "$RC_ROOT/plugins" -type f -exec chmod 644 {} +
chmod 640 "$CONFIG_FILE" "$OVERLAY_FILE"

reload_php
health_check

INSTALL_STARTED=0

if [ "$RC_SPAMHAUS_UNBOUND" = 1 ]; then
    say "configurando Spamhaus com resolvedor local (unbound)"
    RC_SPAMHAUS_DNSBL="$RC_SPAMHAUS_DNSBL" \
        bash "$PAYLOAD/enable-spamhaus-unbound.sh" \
        || die "configuração do Spamhaus/unbound falhou"
fi

prune_backups || warn "não foi possível limpar backups antigos"

say "concluído; skin padrão: $RC_SKIN; backup: $BK"
