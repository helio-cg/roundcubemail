#!/usr/bin/env bash
# Recompile the minified CSS of every local skin and plugin skin.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LESS_CMD=(npx --yes -p less -p less-plugin-clean-css lessc "--clean-css=--s1 --advanced")

compile() {
    local dir="$1" input="$2" output="$3"
    (cd "$dir" && "${LESS_CMD[@]}" "$input" "$output" >/dev/null)
    printf '[css] %s/%s\n' "${dir#$ROOT/}" "$output"
}

for skin in carius iguatu canga; do
    dir="$ROOT/src/skins/$skin"
    [ -d "$dir" ] || continue
    compile "$dir" styles/styles.less styles/styles.min.css
    compile "$dir" styles/print.less styles/print.min.css
    compile "$dir" styles/embed.less styles/embed.min.css

    libkolab="$ROOT/src/plugins/libkolab/skins/$skin"
    if [ -f "$libkolab/libkolab.less" ]; then
        compile "$libkolab" libkolab.less libkolab.min.css
    fi
done
