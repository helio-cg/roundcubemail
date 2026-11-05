#!/usr/bin/env php
<?php

/**
 * Export, verify and restore the Roundcube data owned by a set of accounts.
 *
 * The export is grouped by HestiaCP user (the shell wrapper builds that
 * grouping).  On restore the target user_id is resolved by email address,
 * because a fresh Hestia/Roundcube installation assigns new numeric IDs.
 * Related rows (contacts, groups, calendars, events, attachments) are then
 * remapped before being inserted.
 *
 * Usage:
 *   rc-data.php list    --config=/etc/roundcube/config.inc.php
 *   rc-data.php export  --config=/etc/roundcube/config.inc.php
 *                       --hestia-user=USER --emails=a@x,b@x
 *                       --output=/path/data.ser.gz --manifest=/path/manifest.json
 *   rc-data.php verify  --data=/path/data.ser.gz [--manifest=/path/manifest.json]
 *   rc-data.php restore --config=/etc/roundcube/config.inc.php
 *                       --data=/path/data.ser.gz --mode=replace|merge [--dry-run]
 */

declare(strict_types=1);

const RC_DATA_FORMAT = 'roundcube-user-backup';
const RC_DATA_FORMAT_VERSION = 1;
const RC_DATA_TOOL_VERSION = '1.0.0';

const RC_DATA_USER_TABLES = [
    'identities',
    'responses',
    'contacts',
    'contactgroups',
    'collected_addresses',
    'calendars',
    'itipinvitations',
    'user_settings',
    'searches',
    'filestore',
    'dictionary',
    'kolab_alarms',
];

const RC_DATA_AUTO_PK = [
    'contacts' => 'contact_id',
    'contactgroups' => 'contactgroup_id',
    'calendars' => 'calendar_id',
    'events' => 'event_id',
    'attachments' => 'attachment_id',
    'identities' => 'identity_id',
    'responses' => 'response_id',
    'searches' => 'search_id',
    'collected_addresses' => 'address_id',
    'filestore' => 'file_id',
    'dictionary' => 'id',
];

exit(rc_data_main($argv));

function rc_data_main(array $argv): int
{
    try {
        $command = $argv[1] ?? '';
        $opts = rc_data_parse_options(array_slice($argv, 2));

        return match ($command) {
            'list' => rc_data_cmd_list($opts),
            'export' => rc_data_cmd_export($opts),
            'verify' => rc_data_cmd_verify($opts),
            'restore' => rc_data_cmd_restore($opts),
            default => rc_data_usage(),
        };
    } catch (Throwable $e) {
        fwrite(STDERR, 'rc-data: erro: ' . $e->getMessage() . "\n");
        return 1;
    }
}

function rc_data_usage(): int
{
    fwrite(STDERR, <<<'TXT'
rc-data.php <list|export|verify|restore> [opções]

Opções comuns:
  --config=ARQUIVO       config.inc.php do Roundcube (pode repetir)
  --dsn=DSN              DSN PDO (mysql://... ou sqlite://...) para testes
  --emails=a@x,b@x       emails a processar
  --email=a@x            email a processar (pode repetir)
  --emails-file=ARQUIVO  um email por linha

export:
  --hestia-user=USER --output=ARQUIVO --manifest=ARQUIVO

restore:
  --data=ARQUIVO [--manifest=ARQUIVO] [--mode=replace|merge] [--dry-run]

verify:
  --data=ARQUIVO [--manifest=ARQUIVO]
TXT . "\n");

    return 2;
}

function rc_data_parse_options(array $args): array
{
    $opts = [];

    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];

        if (!str_starts_with($arg, '--')) {
            throw new RuntimeException("argumento inesperado: {$arg}");
        }

        $name = substr($arg, 2);
        $value = true;

        if (str_contains($name, '=')) {
            [$name, $value] = explode('=', $name, 2);
        } elseif (isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '--')) {
            $value = $args[++$i];
        }

        if (array_key_exists($name, $opts)) {
            $opts[$name] = array_merge((array) $opts[$name], (array) $value);
        } else {
            $opts[$name] = $value;
        }
    }

    return $opts;
}

function rc_data_option(array $opts, string $name, $default = null)
{
    return $opts[$name] ?? $default;
}

function rc_data_emails(array $opts): array
{
    $emails = [];

    foreach ((array) rc_data_option($opts, 'emails', []) as $value) {
        foreach (preg_split('/[,;\s]+/', (string) $value) ?: [] as $email) {
            $email = strtolower(trim($email));
            if ($email !== '') {
                $emails[$email] = $email;
            }
        }
    }

    foreach ((array) rc_data_option($opts, 'email', []) as $email) {
        $email = strtolower(trim((string) $email));
        if ($email !== '') {
            $emails[$email] = $email;
        }
    }

    $file = rc_data_option($opts, 'emails-file');

    if ($file) {
        if (!is_readable((string) $file)) {
            throw new RuntimeException("arquivo de emails não encontrado: {$file}");
        }

        foreach (file((string) $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $email) {
            $email = strtolower(trim($email));
            if ($email !== '' && !str_starts_with($email, '#')) {
                $emails[$email] = $email;
            }
        }
    }

    return array_values($emails);
}

function rc_data_config(array $opts): array
{
    $dsn = (string) rc_data_option($opts, 'dsn', '');

    if ($dsn !== '') {
        return ['dsn' => $dsn, 'des_key' => null, 'mail_host' => null, 'files' => [], 'config' => []];
    }

    $files = rc_data_option($opts, 'config', getenv('RC_CONFIG') ?: '/etc/roundcube/config.inc.php');
    $files = is_array($files) ? $files : [$files];
    $config = [];
    $rcmail_config = [];

    foreach ($files as $file) {
        $file = (string) $file;
        if (is_file($file)) {
            include $file;
        }
    }

    $dsn = (string) ($config['db_dsnw'] ?? $rcmail_config['db_dsnw'] ?? '');

    if ($dsn === '') {
        throw new RuntimeException('db_dsnw não encontrado na configuração do Roundcube');
    }

    return [
        'dsn' => $dsn,
        'des_key' => $config['des_key'] ?? $rcmail_config['des_key'] ?? null,
        'mail_host' => $config['mail_host'] ?? $rcmail_config['mail_host'] ?? null,
        'files' => $files,
        'config' => $config,
    ];
}

function rc_data_connect(array $cfg): PDO
{
    $dsn = (string) $cfg['dsn'];

    if (str_starts_with($dsn, 'mysql://') || str_starts_with($dsn, 'pgsql://')) {
        $parts = parse_url($dsn);

        if (!is_array($parts)) {
            throw new RuntimeException('DSN inválido');
        }

        $driver = $parts['scheme'] === 'pgsql' ? 'pgsql' : 'mysql';
        $pdoDsn = $driver . ':host=' . ($parts['host'] ?? 'localhost');

        if (!empty($parts['port'])) {
            $pdoDsn .= ';port=' . (int) $parts['port'];
        }

        $pdoDsn .= ';dbname=' . ltrim((string) ($parts['path'] ?? ''), '/');

        if ($driver === 'mysql') {
            $pdoDsn .= ';charset=utf8mb4';
        }

        return new PDO(
            $pdoDsn,
            urldecode((string) ($parts['user'] ?? '')),
            urldecode((string) ($parts['pass'] ?? '')),
            rc_data_pdo_options()
        );
    }

    if (str_starts_with($dsn, 'sqlite://')) {
        $rest = substr($dsn, strlen('sqlite://'));
        $path = str_starts_with($rest, '//') ? '/' . ltrim($rest, '/') : $rest;
        $path = preg_replace('/\?.*$/', '', $path);

        return new PDO('sqlite:' . $path, null, null, rc_data_pdo_options());
    }

    throw new RuntimeException('DSN não suportado: apenas mysql://, pgsql:// e sqlite://');
}

function rc_data_pdo_options(): array
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    if (defined('PDO::ATTR_EMULATE_PREPARES')) {
        $options[PDO::ATTR_EMULATE_PREPARES] = false;
    }

    return $options;
}

function rc_data_driver(PDO $pdo): string
{
    return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
}

function rc_data_quote(string $name, string $driver): string
{
    return $driver === 'mysql'
        ? '`' . str_replace('`', '``', $name) . '`'
        : '"' . str_replace('"', '""', $name) . '"';
}

function rc_data_tables(PDO $pdo, string $driver): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . $driver;

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    if ($driver === 'sqlite') {
        $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($driver === 'pgsql') {
        $rows = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")
            ->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    }

    return $cache[$cacheKey] = array_values(array_map('strval', $rows));
}

function rc_data_columns(PDO $pdo, string $driver, string $table, array &$cache): array
{
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!in_array($table, rc_data_tables($pdo, $driver), true)) {
        return $cache[$table] = [];
    }

    if ($driver === 'sqlite') {
        $rows = $pdo->query('PRAGMA table_info(' . rc_data_quote($table, $driver) . ')')->fetchAll();
        $columns = array_column($rows, 'name');
    } elseif ($driver === 'pgsql') {
        $stmt = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_name = ? AND table_schema = current_schema() ORDER BY ordinal_position');
        $stmt->execute([$table]);
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $rows = $pdo->query('SHOW COLUMNS FROM ' . rc_data_quote($table, $driver))->fetchAll();
        $columns = array_column($rows, 'Field');
    }

    return $cache[$table] = array_values(array_map('strval', $columns));
}

function rc_data_select(PDO $pdo, string $driver, string $table, array $where = [], array $params = []): array
{
    $sql = 'SELECT * FROM ' . rc_data_quote($table, $driver);

    if ($where) {
        $parts = [];
        foreach ($where as $column => $_) {
            $parts[] = rc_data_quote((string) $column, $driver) . ' = ?';
        }
        $sql .= ' WHERE ' . implode(' AND ', $parts);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function rc_data_select_in(PDO $pdo, string $driver, string $table, string $column, array $values): array
{
    $values = array_values(array_unique(array_map('intval', $values)));

    if (!$values) {
        return [];
    }

    $sql = 'SELECT * FROM ' . rc_data_quote($table, $driver)
        . ' WHERE ' . rc_data_quote($column, $driver) . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    return $stmt->fetchAll();
}

function rc_data_find_user(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(username) = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function rc_data_versions(PDO $pdo, string $driver): array
{
    $tables = rc_data_tables($pdo, $driver);

    if (!in_array('system', $tables, true)) {
        return [];
    }

    $versions = [];
    foreach (rc_data_select($pdo, $driver, 'system') as $row) {
        if (isset($row['name'], $row['value'])) {
            $versions[(string) $row['name']] = (string) $row['value'];
        }
    }

    return $versions;
}

function rc_data_export_rows(PDO $pdo, string $driver, string $table, int $userId, array $tables, array &$columns): array
{
    if (!in_array($table, $tables, true)) {
        return [];
    }

    $tableColumns = rc_data_columns($pdo, $driver, $table, $columns);
    $where = ['user_id' => $userId];

    if (in_array('del', $tableColumns, true)) {
        return rc_data_select(
            $pdo,
            $driver,
            $table,
            ['user_id' => $userId, 'del' => 0],
            [$userId, 0]
        );
    }

    return rc_data_select($pdo, $driver, $table, $where, [$userId]);
}

function rc_data_export(array $cfg, array $emails, string $hestiaUser): array
{
    $pdo = rc_data_connect($cfg);
    $driver = rc_data_driver($pdo);
    $tables = rc_data_tables($pdo, $driver);
    $columns = [];
    $data = [
        'format' => RC_DATA_FORMAT,
        'format_version' => RC_DATA_FORMAT_VERSION,
        'tool_version' => RC_DATA_TOOL_VERSION,
        'created_at' => gmdate('c'),
        'hestia_user' => $hestiaUser,
        'source' => [
            'host' => gethostname() ?: '',
            'db_driver' => $driver,
            'des_key' => $cfg['des_key'] ?: null,
            'versions' => rc_data_versions($pdo, $driver),
        ],
        'users' => [],
        'skipped_emails' => [],
    ];

    foreach ($emails as $email) {
        $user = rc_data_find_user($pdo, $email);

        if (!$user) {
            $data['skipped_emails'][] = $email;
            continue;
        }

        $userId = (int) $user['user_id'];
        $record = [
            'email' => strtolower(trim($email)),
            'old_user_id' => $userId,
            'user' => $user,
            'tables' => [],
        ];

        foreach (RC_DATA_USER_TABLES as $table) {
            $record['tables'][$table] = rc_data_export_rows($pdo, $driver, $table, $userId, $tables, $columns);
        }

        if (in_array('contactgroups', $tables, true) && in_array('contactgroupmembers', $tables, true)) {
            $groupIds = array_column($record['tables']['contactgroups'] ?? [], 'contactgroup_id');
            $record['tables']['contactgroupmembers'] = rc_data_select_in(
                $pdo,
                $driver,
                'contactgroupmembers',
                'contactgroup_id',
                $groupIds
            );
        }

        if (in_array('calendars', $tables, true)) {
            $calendarIds = array_column($record['tables']['calendars'] ?? [], 'calendar_id');
            $record['tables']['events'] = in_array('events', $tables, true)
                ? rc_data_select_in($pdo, $driver, 'events', 'calendar_id', $calendarIds)
                : [];
            $eventIds = array_column($record['tables']['events'] ?? [], 'event_id');
            $record['tables']['attachments'] = in_array('attachments', $tables, true)
                ? rc_data_select_in($pdo, $driver, 'attachments', 'event_id', $eventIds)
                : [];
        }

        $record['tables'] = array_filter($record['tables'], static fn ($rows) => is_array($rows));
        $record['counts'] = array_map('count', $record['tables']);
        $data['users'][] = $record;
    }

    return $data;
}

function rc_data_manifest(array $data, string $dataFile): array
{
    $users = [];

    foreach ($data['users'] as $record) {
        $users[] = [
            'email' => $record['email'],
            'old_user_id' => $record['old_user_id'],
            'tables' => $record['counts'],
        ];
    }

    return [
        'format' => RC_DATA_FORMAT,
        'format_version' => RC_DATA_FORMAT_VERSION,
        'tool_version' => RC_DATA_TOOL_VERSION,
        'created_at' => $data['created_at'],
        'hestia_user' => $data['hestia_user'],
        'source' => $data['source'],
        'emails' => array_column($data['users'], 'email'),
        'users' => $users,
        'skipped_emails' => $data['skipped_emails'],
        'data' => [
            'file' => basename($dataFile),
            'sha256' => hash_file('sha256', $dataFile),
            'size' => filesize($dataFile),
        ],
    ];
}

function rc_data_cmd_export(array $opts): int
{
    $cfg = rc_data_config($opts);
    $emails = rc_data_emails($opts);
    $hestiaUser = trim((string) rc_data_option($opts, 'hestia-user', ''));

    if ($hestiaUser === '') {
        throw new RuntimeException('informe --hestia-user');
    }

    if (!$emails) {
        throw new RuntimeException('informe --emails, --email ou --emails-file');
    }

    $output = (string) rc_data_option($opts, 'output', '');

    if ($output === '') {
        throw new RuntimeException('informe --output');
    }

    $data = rc_data_export($cfg, $emails, $hestiaUser);

    $payload = gzencode(serialize($data), 9);

    if ($payload === false || file_put_contents($output, $payload) === false) {
        throw new RuntimeException("não foi possível gravar {$output}");
    }

    @chmod($output, 0600);

    $manifestPath = (string) rc_data_option($opts, 'manifest', '');

    if ($manifestPath !== '') {
        $manifest = rc_data_manifest($data, $output);
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false || file_put_contents($manifestPath, $json . "\n") === false) {
            throw new RuntimeException("não foi possível gravar {$manifestPath}");
        }

        @chmod($manifestPath, 0600);
    }

    echo json_encode([
        'ok' => true,
        'hestia_user' => $hestiaUser,
        'users' => array_map(static fn ($record) => [
            'email' => $record['email'],
            'old_user_id' => $record['old_user_id'],
            'counts' => $record['counts'],
        ], $data['users']),
        'skipped_emails' => $data['skipped_emails'],
        'output' => $output,
        'sha256' => hash_file('sha256', $output),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return 0;
}

function rc_data_load(string $file): array
{
    if (!is_readable($file)) {
        throw new RuntimeException("arquivo não encontrado: {$file}");
    }

    $raw = file_get_contents($file);

    if ($raw === false) {
        throw new RuntimeException("não foi possível ler {$file}");
    }

    $json = @gzdecode($raw);
    if ($json === false) {
        throw new RuntimeException("{$file} não é um gzip válido");
    }

    $data = @unserialize($json, ['allowed_classes' => false]);

    if (!is_array($data) || ($data['format'] ?? '') !== RC_DATA_FORMAT) {
        throw new RuntimeException("{$file} não contém um backup Roundcube válido");
    }

    if ((int) ($data['format_version'] ?? 0) > RC_DATA_FORMAT_VERSION) {
        throw new RuntimeException('backup criado por uma versão mais nova da ferramenta');
    }

    return $data;
}

function rc_data_validate(array $data): array
{
    $errors = [];
    $warnings = [];
    $emails = [];
    $ids = [];

    if (empty($data['users']) || !is_array($data['users'])) {
        $warnings[] = 'backup sem usuários';
        return [$errors, $warnings];
    }

    foreach ($data['users'] as $record) {
        $email = strtolower((string) ($record['email'] ?? ''));
        $oldId = (int) ($record['old_user_id'] ?? 0);
        $tables = $record['tables'] ?? null;
        $user = $record['user'] ?? null;

        if ($email === '' || !str_contains($email, '@')) {
            $errors[] = 'registro sem email válido';
            continue;
        }

        if ($oldId <= 0) {
            $errors[] = "{$email}: old_user_id inválido";
        }

        if (isset($emails[$email])) {
            $errors[] = "{$email}: email duplicado no backup";
        }
        $emails[$email] = true;

        if (isset($ids[$oldId])) {
            $errors[] = "{$email}: old_user_id duplicado ({$oldId})";
        }
        $ids[$oldId] = true;

        if (!is_array($user) || ($user['username'] ?? '') === '') {
            $errors[] = "{$email}: linha de users ausente";
        }

        if (!is_array($tables)) {
            $errors[] = "{$email}: tabelas ausentes";
            continue;
        }

        $contactIds = array_flip(array_map('intval', array_column($tables['contacts'] ?? [], 'contact_id')));
        $groupIds = array_flip(array_map('intval', array_column($tables['contactgroups'] ?? [], 'contactgroup_id')));
        $calendarIds = array_flip(array_map('intval', array_column($tables['calendars'] ?? [], 'calendar_id')));
        $eventIds = array_flip(array_map('intval', array_column($tables['events'] ?? [], 'event_id')));

        foreach ($tables['contactgroupmembers'] ?? [] as $row) {
            if (!isset($groupIds[(int) ($row['contactgroup_id'] ?? 0)])) {
                $warnings[] = "{$email}: contato de grupo sem grupo correspondente";
            }
            if (!isset($contactIds[(int) ($row['contact_id'] ?? 0)])) {
                $warnings[] = "{$email}: membro de grupo sem contato correspondente";
            }
        }

        foreach ($tables['events'] ?? [] as $row) {
            if (!isset($calendarIds[(int) ($row['calendar_id'] ?? 0)])) {
                $errors[] = "{$email}: evento sem calendário correspondente";
            }
        }

        foreach ($tables['attachments'] ?? [] as $row) {
            if (!isset($eventIds[(int) ($row['event_id'] ?? 0)])) {
                $errors[] = "{$email}: anexo sem evento correspondente";
            }
        }
    }

    return [$errors, $warnings];
}

function rc_data_cmd_verify(array $opts): int
{
    $dataFile = (string) rc_data_option($opts, 'data', '');

    if ($dataFile === '') {
        throw new RuntimeException('informe --data');
    }

    $manifestFile = (string) rc_data_option($opts, 'manifest', '');
    $manifest = null;

    if ($manifestFile !== '') {
        if (!is_readable($manifestFile)) {
            throw new RuntimeException("manifesto não encontrado: {$manifestFile}");
        }

        $manifest = json_decode((string) file_get_contents($manifestFile), true);

        if (!is_array($manifest) || ($manifest['format'] ?? '') !== RC_DATA_FORMAT) {
            throw new RuntimeException('manifesto inválido');
        }

        $expected = (string) ($manifest['data']['sha256'] ?? '');
        $actual = hash_file('sha256', $dataFile);

        if ($expected === '' || !hash_equals($expected, $actual)) {
            throw new RuntimeException('sha256 do arquivo de dados não confere com o manifesto');
        }

        if ((int) ($manifest['data']['size'] ?? -1) !== filesize($dataFile)) {
            throw new RuntimeException('tamanho do arquivo de dados não confere com o manifesto');
        }
    }

    $data = rc_data_load($dataFile);
    [$errors, $warnings] = rc_data_validate($data);
    $users = [];

    foreach ($data['users'] as $record) {
        $counts = [];

        foreach ($record['tables'] as $table => $rows) {
            $counts[$table] = count($rows);
            if (!isset($record['counts'][$table]) || (int) $record['counts'][$table] !== count($rows)) {
                $errors[] = "{$record['email']}: contagem diverge em {$table}";
            }
        }

        $users[] = [
            'email' => $record['email'],
            'old_user_id' => $record['old_user_id'],
            'rows' => array_sum($counts),
            'tables' => $counts,
        ];
    }

    if ($manifest !== null) {
        $manifestEmails = array_map('strtolower', (array) ($manifest['emails'] ?? []));
        $dataEmails = array_map(static fn ($record) => strtolower((string) $record['email']), $data['users']);

        if ($manifestEmails !== $dataEmails) {
            $errors[] = 'emails do manifesto não conferem com o arquivo de dados';
        }
    }

    echo json_encode([
        'ok' => !$errors,
        'data' => basename($dataFile),
        'sha256' => hash_file('sha256', $dataFile),
        'size' => filesize($dataFile),
        'hestia_user' => $data['hestia_user'] ?? '',
        'created_at' => $data['created_at'] ?? '',
        'users' => $users,
        'warnings' => array_values(array_unique($warnings)),
        'errors' => array_values(array_unique($errors)),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return $errors ? 1 : 0;
}

function rc_data_cmd_list(array $opts): int
{
    $cfg = rc_data_config($opts);
    $pdo = rc_data_connect($cfg);
    $rows = [];

    foreach ($pdo->query('SELECT user_id, username FROM users ORDER BY LOWER(username)')->fetchAll() as $row) {
        $rows[] = ['user_id' => (int) $row['user_id'], 'email' => (string) $row['username']];
    }

    if (!empty($opts['json'])) {
        echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        foreach ($rows as $row) {
            echo $row['email'] . "\t" . $row['user_id'] . "\n";
        }
    }

    return 0;
}

function rc_data_cmd_restore(array $opts): int
{
    $cfg = rc_data_config($opts);
    $dataFile = (string) rc_data_option($opts, 'data', '');

    if ($dataFile === '') {
        throw new RuntimeException('informe --data');
    }

    $manifestFile = (string) rc_data_option($opts, 'manifest', '');

    if ($manifestFile !== '') {
        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        $expected = (string) ($manifest['data']['sha256'] ?? '');
        $actual = hash_file('sha256', $dataFile);

        if ($expected === '' || !hash_equals($expected, $actual)) {
            throw new RuntimeException('sha256 do arquivo de dados não confere com o manifesto');
        }
    }

    $data = rc_data_load($dataFile);
    [$errors, $warnings] = rc_data_validate($data);

    if ($errors) {
        throw new RuntimeException('backup inválido: ' . implode('; ', array_slice($errors, 0, 5)));
    }

    $mode = strtolower((string) rc_data_option($opts, 'mode', 'replace'));

    if (!in_array($mode, ['replace', 'merge'], true)) {
        throw new RuntimeException('--mode deve ser replace ou merge');
    }

    $dryRun = !empty($opts['dry-run']);
    $filter = array_flip(rc_data_emails($opts));
    $targetMailHost = trim((string) ($cfg['mail_host'] ?? ''));
    $pdo = rc_data_connect($cfg);
    $driver = rc_data_driver($pdo);
    $columnsCache = [];
    $tables = rc_data_tables($pdo, $driver);
    $summary = ['ok' => true, 'mode' => $mode, 'dry_run' => $dryRun, 'users' => [], 'warnings' => [], 'errors' => []];

    $sourceKey = $data['source']['des_key'] ?? null;
    $targetKey = $cfg['des_key'] ?? null;

    if ($sourceKey && $targetKey && !hash_equals((string) $sourceKey, (string) $targetKey)) {
        $summary['warnings'][] = 'des_key do backup difere do Roundcube atual; preferências cifradas e 2FA podem não abrir';
    }

    if ($dryRun) {
        foreach ($data['users'] as $record) {
            $email = strtolower((string) $record['email']);

            if ($filter && !isset($filter[$email])) {
                continue;
            }

            $existing = rc_data_find_user($pdo, $email);
            $summary['users'][] = [
                'email' => $email,
                'old_user_id' => (int) $record['old_user_id'],
                'new_user_id' => $existing ? (int) $existing['user_id'] : null,
                'action' => $existing ? 'update' : 'create',
                'counts' => $record['counts'],
            ];
        }

        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        return 0;
    }

    if ($driver === 'mysql') {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    } elseif ($driver === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys=OFF');
    }

    try {
        foreach ($data['users'] as $record) {
            $email = strtolower((string) $record['email']);

            if ($filter && !isset($filter[$email])) {
                continue;
            }

            $pdo->beginTransaction();

            try {
                $existing = rc_data_find_user($pdo, $email);

                if ($existing) {
                    $newUserId = (int) $existing['user_id'];
                    $action = 'update';

                    if ($mode === 'replace') {
                        rc_data_update_user($pdo, $driver, $newUserId, $record['user'], $columnsCache);
                    }
                } else {
                    if ($targetMailHost !== '' && isset($record['user']['mail_host'])) {
                        $record['user']['mail_host'] = $targetMailHost;
                    }

                    $newUserId = rc_data_insert_user($pdo, $driver, $record['user'], $columnsCache);
                    $action = 'create';
                }

                if ($newUserId <= 0) {
                    throw new RuntimeException("{$email}: não foi possível obter o novo user_id");
                }

                if ($mode === 'replace') {
                    rc_data_delete_user($pdo, $driver, $newUserId, $tables);
                }

                $inserted = rc_data_restore_tables($pdo, $driver, $newUserId, $record['tables'], $columnsCache, $mode);

                $pdo->commit();

                $summary['users'][] = [
                    'email' => $email,
                    'old_user_id' => (int) $record['old_user_id'],
                    'new_user_id' => $newUserId,
                    'action' => $action,
                    'inserted' => $inserted,
                ];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
    } finally {
        if ($driver === 'mysql') {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } elseif ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys=ON');
        }
    }

    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return 0;
}

function rc_data_update_user(PDO $pdo, string $driver, int $userId, array $source, array &$columns): void
{
    $targetColumns = rc_data_columns($pdo, $driver, 'users', $columns);
    $updates = [];

    foreach (['language', 'preferences'] as $column) {
        if (isset($source[$column]) && in_array($column, $targetColumns, true)) {
            $updates[$column] = $source[$column];
        }
    }

    if (!$updates) {
        return;
    }

    $sets = [];
    $params = [];
    foreach ($updates as $column => $value) {
        $sets[] = rc_data_quote($column, $driver) . ' = ?';
        $params[] = $value;
    }
    $params[] = $userId;

    $stmt = $pdo->prepare(
        'UPDATE ' . rc_data_quote('users', $driver)
        . ' SET ' . implode(', ', $sets)
        . ' WHERE ' . rc_data_quote('user_id', $driver) . ' = ?'
    );
    $stmt->execute($params);
}

function rc_data_insert_user(PDO $pdo, string $driver, array $source, array &$columns): int
{
    $targetColumns = rc_data_columns($pdo, $driver, 'users', $columns);
    $row = array_intersect_key($source, array_flip($targetColumns));
    unset($row['user_id']);

    if (!$row) {
        throw new RuntimeException('não há colunas compatíveis para criar o usuário');
    }

    $cols = array_keys($row);
    $sql = 'INSERT INTO ' . rc_data_quote('users', $driver)
        . ' (' . implode(', ', array_map(static fn ($col) => rc_data_quote((string) $col, $driver), $cols)) . ')'
        . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($row));

    $newId = (int) $pdo->lastInsertId();

    if ($newId > 0) {
        return $newId;
    }

    $user = rc_data_find_user($pdo, (string) ($source['username'] ?? ''));

    return $user ? (int) $user['user_id'] : 0;
}

function rc_data_delete_user(PDO $pdo, string $driver, int $userId, array $tables): void
{
    $tables = array_flip($tables);

    if (isset($tables['attachments'], $tables['events'], $tables['calendars'])) {
        $pdo->prepare(
            'DELETE FROM ' . rc_data_quote('attachments', $driver)
            . ' WHERE event_id IN (SELECT event_id FROM ' . rc_data_quote('events', $driver)
            . ' WHERE calendar_id IN (SELECT calendar_id FROM ' . rc_data_quote('calendars', $driver) . ' WHERE user_id = ?))'
        )->execute([$userId]);
    }

    if (isset($tables['events'], $tables['calendars'])) {
        $pdo->prepare(
            'DELETE FROM ' . rc_data_quote('events', $driver)
            . ' WHERE calendar_id IN (SELECT calendar_id FROM ' . rc_data_quote('calendars', $driver) . ' WHERE user_id = ?)'
        )->execute([$userId]);
    }

    if (isset($tables['calendars'])) {
        $pdo->prepare('DELETE FROM ' . rc_data_quote('calendars', $driver) . ' WHERE user_id = ?')->execute([$userId]);
    }

    if (isset($tables['contactgroupmembers'], $tables['contactgroups'], $tables['contacts'])) {
        $pdo->prepare(
            'DELETE FROM ' . rc_data_quote('contactgroupmembers', $driver)
            . ' WHERE contactgroup_id IN (SELECT contactgroup_id FROM ' . rc_data_quote('contactgroups', $driver) . ' WHERE user_id = ?)'
            . ' OR contact_id IN (SELECT contact_id FROM ' . rc_data_quote('contacts', $driver) . ' WHERE user_id = ?)'
        )->execute([$userId, $userId]);
    }

    foreach (['contactgroups', 'contacts', 'identities', 'responses', 'collected_addresses', 'searches', 'filestore', 'dictionary', 'user_settings', 'itipinvitations', 'kolab_alarms'] as $table) {
        if (isset($tables[$table])) {
            $pdo->prepare('DELETE FROM ' . rc_data_quote($table, $driver) . ' WHERE user_id = ?')->execute([$userId]);
        }
    }
}

function rc_data_restore_tables(PDO $pdo, string $driver, int $userId, array $tables, array &$columns, string $mode): array
{
    $inserted = [];
    $contactMap = [];
    $groupMap = [];
    $calendarMap = [];
    $eventMap = [];

    foreach ($tables['contacts'] ?? [] as $row) {
        $oldId = (int) ($row['contact_id'] ?? 0);
        unset($row['contact_id']);
        $row['user_id'] = $userId;
        [$newId] = rc_data_insert($pdo, $driver, 'contacts', $row, $columns);
        $contactMap[$oldId] = $newId;
        $inserted['contacts'] = ($inserted['contacts'] ?? 0) + 1;
    }

    foreach ($tables['contactgroups'] ?? [] as $row) {
        $oldId = (int) ($row['contactgroup_id'] ?? 0);
        unset($row['contactgroup_id']);
        $row['user_id'] = $userId;
        [$newId] = rc_data_insert($pdo, $driver, 'contactgroups', $row, $columns);
        $groupMap[$oldId] = $newId;
        $inserted['contactgroups'] = ($inserted['contactgroups'] ?? 0) + 1;
    }

    foreach ($tables['contactgroupmembers'] ?? [] as $row) {
        $oldGroup = (int) ($row['contactgroup_id'] ?? 0);
        $oldContact = (int) ($row['contact_id'] ?? 0);

        if (!isset($groupMap[$oldGroup], $contactMap[$oldContact])) {
            continue;
        }

        $row['contactgroup_id'] = $groupMap[$oldGroup];
        $row['contact_id'] = $contactMap[$oldContact];
        rc_data_insert($pdo, $driver, 'contactgroupmembers', $row, $columns, $mode === 'merge');
        $inserted['contactgroupmembers'] = ($inserted['contactgroupmembers'] ?? 0) + 1;
    }

    foreach ($tables['calendars'] ?? [] as $row) {
        $oldId = (int) ($row['calendar_id'] ?? 0);
        unset($row['calendar_id']);
        $row['user_id'] = $userId;
        [$newId] = rc_data_insert($pdo, $driver, 'calendars', $row, $columns);
        $calendarMap[$oldId] = $newId;
        $inserted['calendars'] = ($inserted['calendars'] ?? 0) + 1;
    }

    foreach ($tables['events'] ?? [] as $row) {
        $oldId = (int) ($row['event_id'] ?? 0);
        $oldCalendar = (int) ($row['calendar_id'] ?? 0);

        if (!isset($calendarMap[$oldCalendar])) {
            continue;
        }

        unset($row['event_id']);
        $row['calendar_id'] = $calendarMap[$oldCalendar];
        [$newId] = rc_data_insert($pdo, $driver, 'events', $row, $columns);
        $eventMap[$oldId] = $newId;
        $inserted['events'] = ($inserted['events'] ?? 0) + 1;
    }

    foreach ($tables['attachments'] ?? [] as $row) {
        $oldEvent = (int) ($row['event_id'] ?? 0);

        if (!isset($eventMap[$oldEvent])) {
            continue;
        }

        unset($row['attachment_id']);
        $row['event_id'] = $eventMap[$oldEvent];
        rc_data_insert($pdo, $driver, 'attachments', $row, $columns);
        $inserted['attachments'] = ($inserted['attachments'] ?? 0) + 1;
    }

    foreach (['identities', 'responses', 'collected_addresses', 'searches', 'filestore'] as $table) {
        $pk = RC_DATA_AUTO_PK[$table] ?? null;

        foreach ($tables[$table] ?? [] as $row) {
            if ($pk !== null) {
                unset($row[$pk]);
            }
            $row['user_id'] = $userId;
            rc_data_insert($pdo, $driver, $table, $row, $columns);
            $inserted[$table] = ($inserted[$table] ?? 0) + 1;
        }
    }

    foreach ($tables['dictionary'] ?? [] as $row) {
        $row['user_id'] = $userId;
        unset($row[RC_DATA_AUTO_PK['dictionary']]);
        rc_data_delete_key($pdo, $driver, 'dictionary', ['user_id' => $userId, 'language' => $row['language'] ?? '']);
        rc_data_insert($pdo, $driver, 'dictionary', $row, $columns);
        $inserted['dictionary'] = ($inserted['dictionary'] ?? 0) + 1;
    }

    foreach ($tables['user_settings'] ?? [] as $row) {
        $row['user_id'] = $userId;
        rc_data_delete_key($pdo, $driver, 'user_settings', ['user_id' => $userId]);
        rc_data_insert($pdo, $driver, 'user_settings', $row, $columns);
        $inserted['user_settings'] = ($inserted['user_settings'] ?? 0) + 1;
    }

    foreach ($tables['itipinvitations'] ?? [] as $row) {
        $row['user_id'] = $userId;
        rc_data_delete_key($pdo, $driver, 'itipinvitations', ['token' => $row['token'] ?? '']);
        rc_data_insert($pdo, $driver, 'itipinvitations', $row, $columns, true);
        $inserted['itipinvitations'] = ($inserted['itipinvitations'] ?? 0) + 1;
    }

    foreach ($tables['kolab_alarms'] ?? [] as $row) {
        $row['user_id'] = $userId;
        rc_data_insert($pdo, $driver, 'kolab_alarms', $row, $columns, true);
        $inserted['kolab_alarms'] = ($inserted['kolab_alarms'] ?? 0) + 1;
    }

    return $inserted;
}

function rc_data_insert(PDO $pdo, string $driver, string $table, array $row, array &$columns, bool $ignore = false): array
{
    $targetColumns = rc_data_columns($pdo, $driver, $table, $columns);
    $row = array_intersect_key($row, array_flip($targetColumns));

    if (!$row) {
        return [0, []];
    }

    $cols = array_keys($row);
    $verb = $ignore
        ? ($driver === 'mysql' ? 'INSERT IGNORE' : ($driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT'))
        : 'INSERT';
    $sql = $verb . ' INTO ' . rc_data_quote($table, $driver)
        . ' (' . implode(', ', array_map(static fn ($col) => rc_data_quote((string) $col, $driver), $cols)) . ')'
        . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($row));
    } catch (PDOException $e) {
        if ($ignore && $driver === 'pgsql') {
            return [0, $row];
        }
        throw $e;
    }

    return [(int) $pdo->lastInsertId(), $row];
}

function rc_data_delete_key(PDO $pdo, string $driver, string $table, array $key): void
{
    $where = [];
    $params = [];

    foreach ($key as $column => $value) {
        $where[] = rc_data_quote((string) $column, $driver) . ' = ?';
        $params[] = $value;
    }

    if (!$where) {
        return;
    }

    $pdo->prepare('DELETE FROM ' . rc_data_quote($table, $driver) . ' WHERE ' . implode(' AND ', $where))
        ->execute($params);
}
