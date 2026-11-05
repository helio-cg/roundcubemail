# User plugin

Adds a **User** section to Roundcube Settings with:

- **Appearance**, **User interface**, **Messages**, **Composing Messages**,
  **Contacts**, **Special Folders**, **Folders**, **Identities**,
  **Server Settings**, **Responses** and **Calendar** tabs;
- canned **Responses** management moved into the preferences page, with a list
  to view, create, edit and delete the responses used when composing messages;
- **Folders** management moved into the preferences page, with a list to create,
  rename and delete mail folders;
- **Identities** management moved into the preferences page, with a list to
  create, edit, delete and set the default sender identity;
- a display name for the current account, also shown next to the Canga logo
  (defaults to **Seu nome**);
- the core interface preferences: language, time zone, date/time formats,
  pretty dates, refresh interval, skin and standard windows;
- the remaining core preferences rendered with Roundcube's own settings
  renderer, including **Encryption** as a subsection of **Server Settings**;
- recommended color schemes: Current, Blue, Red, Gray and MArple;
- light and dark color controls (accent, menu, background, text and selection);
- live previews for both light and dark mode;
- mailbox view and message display preferences, grouped under the **Messages**
  tab.

Preferences are stored in the plugin's own `user_settings` table, keyed by the
Roundcube `user_id`. The colors are injected into every authenticated page, so
the same preferences apply in **Carius**, **Iguatu**, **Canga** and **Elastic**,
including the Canga top bar, brand block, page header, the inbox list and plugin
interfaces such as the calendar and two-factor authentication pages. The bundled
skins point their **Preferences** menu entry to this page.

## Requirements

- Roundcube 1.7 or newer
- PHP 8.1 or newer

## Installation

Copy the `user` directory to `plugins/` and add `user` to the `plugins` option in
the Roundcube configuration:

```php
$config['plugins'][] = 'user';
```

When installed with Composer, the `roundcube/plugin-installer` runs the SQL
migrations automatically through `extra.roundcube.sql-dir`.

For a manual installation, create the schema or apply pending migrations with
Roundcube's own tools:

```bash
# First installation
bin/initdb.sh --dir=plugins/user/SQL

# Later upgrades
bin/updatedb.sh --dir=plugins/user/SQL --package=user
```

The schema version is tracked in Roundcube's `system` table as `user-version`,
exactly like Roundcube core tracks `roundcube-version`.

## Migrations and versioning

The SQL layout follows the Roundcube convention:

```text
SQL/
├── mysql.initial.sql       # current schema (fresh installation)
├── postgres.initial.sql
├── sqlite.initial.sql
├── mysql/
│   └── 2026110300.sql      # upgrade to schema version 2026110300
├── postgres/
│   └── 2026110300.sql
└── sqlite/
    └── 2026110300.sql
```

Schema versions are date-based numbers (`YYYYMMDDNN`). A new plugin release
that changes the schema must:

1. add the same numbered file to each provider directory (`mysql`, `postgres`,
   `sqlite`);
2. update the provider `*.initial.sql` files with the resulting schema;
3. update `SCHEMA_VERSION` in `user.php`;
4. record the release in `CHANGELOG.md`.

Plugin release versions use semantic versioning (`MAJOR.MINOR.PATCH`) in
`composer.json`, in the `info()` method and in `CHANGELOG.md`.

## Configuration

`config.inc.php.dist` documents the available option:

```php
$config['user_enable_skin_colors'] = true;
```

## Docker development environment

The repository's `docker/entrypoint-tasks/post-setup/20-user.sh` script runs
`bin/updatedb.sh` after Roundcube initializes its database, so the plugin table
is created or upgraded on container start. The compose file also mounts the
plugin and adds `user` to `ROUNDCUBEMAIL_PLUGINS`.
