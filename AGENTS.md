# Roundcube dev environment — context for future updates

Local Roundcube 1.7.5 skin/theme lab. Containerised with Podman, served by lerd at
`https://roundcube.test` (direct: `http://localhost:8000`). Test account:
`demo@example.net` / `demo`.

## Skins

Local skins live in `src/skins/`: `carius`, `aurora`, `iguatu`, `canga` (plus the
original `elastic` from the image). **Canga is the actively customised skin.**
Never change a global file in a way that restyles other skins; scope everything
Canga-specific to `src/skins/canga` and the Canga branch of `src/plugins/user`.

## Building

Compiled CSS is what production uses (devel mode is off). After editing any
`.less` file:

```bash
scripts/build-skins.sh
```

That rebuilds `styles.min.css`, `print.min.css` and `embed.min.css` for the local
skins and `libkolab.min.css` for the Canga plugin skin. The Roundcube template
asks for `styles.css`; in production `rcmail_output_html` swaps it to
`styles.min.css` automatically. Do not edit the `.min.css` files by hand.

JS: `src/skins/canga/ui-canga.js` is loaded directly from
`templates/includes/footer.html` (no minify step). `ui.js`/`ui.min.js` are the
upstream Elastic scripts.

## Canga architecture

- `styles/_variables.less` — base palette and dark-mode variables used by the
  Elastic widgets (`dark.less`, lists, forms, dialogs).
- `styles/_theme.less` — Canga-only theme. Contains:
  1. the static "reference" look (light canvas, rounded cards, violet accent);
  2. light and dark rules for Canga-specific chrome;
  3. at the end, the **palette bridge**: `--canga-*` variables resolving to the
     `--user-*` variables injected by the `user` plugin.
- `styles/_canga.less` — legacy Canga layout/menu/topbar rules. It has **no**
  dark-mode block anymore; dark mode is handled by `_theme.less` plus
  `_variables.less`. Avoid adding new hardcoded dark colors here.
- `styles/styles.less` imports `_canga` then `_theme` (the order matters; the
  palette bridge must stay last).

### Color system (important)

The `user` plugin injects a `<style id="user-skin-colors">` block into every
page. `user.php` exposes:

- `--user-accent`, `--user-accent-text`
- `--user-menu`, `--user-menu-text`
- `--user-background`, `--user-text`
- `--user-selection`, `--user-selection-text`
- `--user-surface`, `--user-surface-alt`, `--user-border` (derived in
  `palette_vars()` and `mix_hex()`)

Canga surfaces use `var(--canga-*)`, which resolve to those variables. That is
why the appearance palette works in both light and dark mode. When adding new
Canga styles, **use `var(--canga-accent)`, `var(--canga-surface)`, etc.** rather
than hardcoded colors, and make sure the rule is specific enough (`html body ...`)
to win over `_canga.less` legacy rules.

Mapping used by the theme (must stay like the Aparência preview):

- **top bar** → `--canga-menu` / `--canga-menu-text` (the `Menu` color)
- **left navigation and submenu background** → `--canga-selection` /
  `--canga-selection-text` (the `Seleção` color)
- page canvas → `--canga-bg` (the `Fundo` color)
- active nav item, selected submenu item, primary buttons, links, badges →
  `--canga-accent` / `--canga-accent-text` (the `Destaque` color)
- cards, list body, content, dialogs, compose, calendar → `--canga-surface`
- inputs and secondary surfaces → `--canga-surface-alt`
- borders → `--canga-border`
- menu separators/borders → `--canga-menu-line` (a darker shade of
  `--canga-selection`; **never white**)

The page title header (`#canga-pageheader`) was intentionally removed. Do not
reintroduce it. The topbar is 62px and the cards start at 78px (16px gap); the
old 142px/158px offsets were reduced to 78px/94px in `_canga.less`.

`src/plugins/user/user.php`:
- `SKIN_PALETTES['canga']` holds the default Canga palette.
- `PRESETS` holds the named schemes (Azul, Vermelho, Cinza, **Verde**, MArple).
  Add a new scheme in `PRESETS` and its `preset<name>` label in the
  `localization/*.inc` files. **Verde is Canga-only**: `presets()` hides it for
  other skins, `sanitize_preset()` rejects it there, and `get_settings()` falls
  back to the skin palette if a non-Canga user has it stored.
- `palette_vars()` emits the CSS variables (including contrast/surface values).
- `canga_rules()` styles Canga chrome and is scoped to the Canga selectors.
- `plugin_rules()` / `chrome_rules()` have Canga-gated blocks for cards, inbox,
  calendar and dialogs. Keep those blocks guarded by
  `$this->current_skin() === 'canga'` so other skins are untouched.
- Top bar uses `menu`; left navigation/submenu uses `selection`; active items use
  `accent`.

## List actions

`ui-canga.js` (`moveListToolbar()` / `listActionsHolder()`) moves each list header
to `#canga-list-actions`, a row at the bottom of its own list column, directly
above the pagination:

- inbox (`task-mail`): right-aligned;
- address book / settings: centered (the contacts "Selecionar" button is here);
- styled in `_theme.less` under `body #layout-list .canga-list-actions`.

## Canga-only rules

Everything below is only for the Canga skin; never change another skin:

- CSS changes go in `src/skins/canga/styles/_theme.less` (palette bridge last) or
  Canga-scoped selectors in `_canga.less`. Never edit `elastic`/`carius`/
  `iguatu`/`aurora`.
- Plugin changes must be gated by `$this->current_skin() === 'canga'` (see
  `canga_rules()` and the Canga blocks in `plugin_rules()`).
- Use `var(--canga-*)` for colors so Aparência presets keep working in light and
  dark mode. Never hardcode a light/dark family in `_canga.less` (the old teal
  dark block was removed for this reason).
- The compose **Enviar** button is always green with a gradient/shadow/hover
  lift, independent of the palette (`.btn-primary.send` at the end of
  `_theme.less`). Do not tie it to `--canga-accent`.
- Plugin CSS that is global (`twofactor_gauthenticator.css`) must be overridden in
  `_theme.less`, never edited directly.
- After any `.less` change run `scripts/build-skins.sh`; JS (`ui-canga.js`) is
  loaded directly, no build step.
- Check at least: mail, address book, compose, calendar and Aparência with
  presets Atual, Cinza and Verde, in light and dark mode.

## Default skin

**Canga is the default skin everywhere.** Keep it that way unless explicitly
asked otherwise:

- local env: `.env` / `.env.example` → `ROUNDCUBE_SKIN=canga`
- `docker-compose.yml` → `${ROUNDCUBE_SKIN:-canga}`
- `Dockerfile` → `ARG ROUNDCUBE_SKIN=canga`
- dev config: `config/roundcube.dev.inc.php` → `$config['skin'] = 'canga'`
  and `skins_allowed` starts with `canga`
- deploy: `deploy/config/rc-deploy.inc.php` → `$config['skin'] = 'canga'`,
  `$rcDeploySkins = ['canga', 'carius', 'iguatu']`
- deploy env/scripts: `RC_SKIN=canga`, `RC_SKINS="canga carius iguatu"`
- skin picker (Preferências > Interface do usuário): `skin_options()` puts Canga
  first; the current skin (Canga by default) is the checked option

## Plugin CSS

`twofactor_gauthenticator.css` is global. Its Canga look is overridden inside
`_theme.less`, never in the plugin CSS itself (that would affect other skins).
Calendar colors/today highlight live in
`src/plugins/libkolab/skins/canga/include/calendar.less` and are rebuilt into
`libkolab.min.css`.

## Quick checks after changes

1. `scripts/build-skins.sh`
2. Login as `demo@example.net` / `demo`.
3. Check mail (light + dark), compose, address book, calendar, settings and
   Preferências > Aparência.
4. In Aparência pick a preset (e.g. Cinza or Verde), save, and confirm the whole
   UI follows the palette in light and dark: Menu = top bar, Seleção = left
   navigation, Destaque = active items/buttons.
5. Confirm the inbox actions sit above the pagination (right) and the contacts
   "Selecionar" sits above the pagination (centered), and that no page shows the
   old `#canga-pageheader` band.
