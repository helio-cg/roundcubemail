#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PAYLOAD="$ROOT/.build/payload"
SKINS="${RC_SKINS:-carius aurora iguatu canga}"
ALLOW_STALE_CSS="${ALLOW_STALE_CSS:-0}"

LESS_CMD=(npx --yes -p less -p less-plugin-clean-css lessc "--clean-css=--s1 --advanced")

say() { printf '[build] %s\n' "$*"; }
fail() { printf '[build] erro: %s\n' "$*" >&2; exit 1; }

have_less=1
command -v node >/dev/null 2>&1 || have_less=0
command -v npx >/dev/null 2>&1 || have_less=0

compile_less() {
    local src="$1" out="$2"

    [ -f "$src" ] || return 0

    if [ "$have_less" = 1 ] && "${LESS_CMD[@]}" "$src" "$out" >/dev/null 2>&1; then
        say "css $(basename "$out")"
        return 0
    fi

    if [ "$ALLOW_STALE_CSS" = 1 ] && [ -f "$out" ]; then
        say "aviso: usando CSS pre-compilado para $(basename "$out")"
        return 0
    fi

    fail "não foi possível compilar $src (instale Node/npx ou use ALLOW_STALE_CSS=1)"
}

rm -rf "$ROOT/.build"
mkdir -p "$PAYLOAD/skins" "$PAYLOAD/plugins" "$PAYLOAD/plugin-configs" "$PAYLOAD/config"

cp -a "$ROOT/src/skins/." "$PAYLOAD/skins/"
cp -a "$ROOT/src/plugins/." "$PAYLOAD/plugins/"
rm -f "$PAYLOAD/plugins/.gitkeep"

for skin in $SKINS; do
    [ -d "$PAYLOAD/skins/$skin" ] || continue

    compile_less "$PAYLOAD/skins/$skin/styles/styles.less" "$PAYLOAD/skins/$skin/styles/styles.min.css"
    compile_less "$PAYLOAD/skins/$skin/styles/print.less" "$PAYLOAD/skins/$skin/styles/print.min.css"
    compile_less "$PAYLOAD/skins/$skin/styles/embed.less" "$PAYLOAD/skins/$skin/styles/embed.min.css"

    libkolab_less="$PAYLOAD/plugins/libkolab/skins/$skin/libkolab.less"
    libkolab_css="$PAYLOAD/plugins/libkolab/skins/$skin/libkolab.min.css"
    libkolab_skin="$(grep -m1 "^@skin:" "$libkolab_less" 2>/dev/null | cut -d'"' -f2 || true)"

    if [ "$libkolab_skin" = "$skin" ]; then
        compile_less "$libkolab_less" "$libkolab_css"
    elif [ ! -f "$libkolab_css" ]; then
        fail "CSS do libkolab ausente para $skin"
    fi
done

cp -a "$ROOT/deploy/config/rc-deploy.inc.php" "$PAYLOAD/config/rc-deploy.inc.php"
cp -a "$ROOT/deploy/config/plugins/." "$PAYLOAD/plugin-configs/"
cp -a "$ROOT/deploy/remote-install.sh" "$PAYLOAD/remote-install.sh"
chmod +x "$PAYLOAD/remote-install.sh"

{
    printf 'commit=%s\n' "$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)"
    printf 'built=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'skins=%s\n' "$SKINS"
    printf 'plugins=%s\n' "$(find "$PAYLOAD/plugins" -mindepth 1 -maxdepth 1 -type d -printf '%f ' | sed 's/ $//')"
} > "$PAYLOAD/build-info.txt"

say "payload pronto em $PAYLOAD"
