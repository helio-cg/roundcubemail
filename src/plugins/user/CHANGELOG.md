# Changelog

All notable changes to the User plugin are documented here.
This project follows [Semantic Versioning](https://semver.org/).

## 1.6.0 - 2026-11-04

- Show the INBOX unread count in the Canga menu on every task, using the last
  count stored in the session.
- Keep the Canga menu counts in sync with the message list while reading mail.
- Fix the Canga menu and sidebar layout by closing template conditionals with
  `<roundcube:endif />`.
- Make the **Atual** color scheme follow the active skin, so a palette saved on
  another skin no longer leaks into it (e.g. a blue menu on the Iguatu theme).
- Show the light and dark color palettes side by side on large screens.
- Keep the database schema unchanged (`2026110300`).

## 1.5.1 - 2026-11-04

- Remove the extra taskbar **Preferences** link injected by the plugin.
- Point the bundled skins' **Preferences** entry to the plugin page.
- Rename the settings section from **User preferences** to **Preferences**.
- Keep the database schema unchanged (`2026110300`).

## 1.5.0 - 2026-11-04

- Add tabs for **Composing Messages**, **Contacts**, **Special Folders**,
  **Server Settings** and **Calendar**.
- Render those sections with Roundcube's own settings renderer, so the fields,
  plugin additions and `dont_override` behavior match the original page.
- Show **Encryption** as a labeled subsection inside **Server Settings**.
- Pick the skin with radio buttons and thumbnails, like the original page.
- Save these preferences through the standard `preferences_save` hooks.
- Keep the database schema unchanged (`2026110300`).

## 1.4.0 - 2026-11-04

- Add a **User Interface** tab with the core interface preferences: language,
  time zone, date and time formats, pretty dates, next-entry after delete,
  refresh interval, skin and standard windows.
- Save the interface preferences through Roundcube's own `save_prefs()` and
  `preferences_save` hook, honoring `dont_override`.
- Rename the reset button to **Restore default**; it still resets only the
  colors and keeps the user's display name.
- Keep the database schema unchanged (`2026110300`).

## 1.3.0 - 2026-11-04

- Organize the User preferences page into **Appearance** and **Messages** tabs.
- Add the Roundcube mailbox view and message display preferences to the
  **Messages** tab, split into two labeled groups.
- Keep the database schema unchanged (`2026110300`).

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
