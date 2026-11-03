# Changelog

All notable changes to the User plugin are documented here.
This project follows [Semantic Versioning](https://semver.org/).

## 1.2.2 - 2026-11-03

- Standardize the Canga menu hover and active states for every entry, including
  **Inbox** and **All Contacts**.
- Remember whether the address book is showing **All Contacts** or **Personal**
  so the selected menu entry stays highlighted.
- Keep the database schema unchanged (`2026110300`).

## 1.2.1 - 2026-11-03

- Replace the Indigo scheme with **MArple**, a warm maple palette with light
  and dark variants.
- Fix the contrast of the Canga top bar text and icons when a trigger is
  hovered, focused or open.
- Add a guaranteed fallback icon for the user preferences link.
- Keep the database schema unchanged (`2026110300`).

## 1.2.0 - 2026-11-03

- Make the preferences link visible in every bundled skin: main task bar on
  Carius, Iguatu and Elastic, Carius account menu and Canga settings menu.
- **Restore defaults** now resets only the colors and keeps the user's name.
- Keep the database schema unchanged (`2026110300`).

## 1.1.0 - 2026-11-03

- Add recommended color schemes: Current, Blue, Red, Gray and Indigo.
- Each scheme includes a prepared light and dark palette.
- Inject both light and dark palettes, switching automatically with dark mode.
- Apply the palette to the Canga top bar, brand block, page header and dropdown.
- Apply the palette to the inbox list, tabs, search bar, pager and toolbar.
- Apply the palette to other plugin interfaces, including calendar and two-factor.
- Add a visible **Preferences** link to the main task bar and the Canga menu.
- Show the saved display name next to the Canga logo, defaulting to **Seu nome**.
- Keep the database schema unchanged (`2026110300`).

## 1.0.0 - 2026-11-03

- Initial release.
- Add a **User** section to Settings.
- Add a display name field stored per Roundcube user.
- Add skin color preferences (accent, menu, background, text and selection)
  with a live preview.
- Apply saved colors to all enabled skins: Carius, Iguatu, Canga and Elastic.
- Add Roundcube SQL migrations for MySQL, PostgreSQL and SQLite.
  Initial database schema version: `2026110300`.
