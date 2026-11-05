<?php

/**
 * User profile and skin colors
 *
 * Adds a "User" section to Settings where each account can set a display
 * name and choose the colors used by the active skin. Preferences are
 * stored in the plugin's own database table and schema upgrades are
 * shipped as Roundcube SQL migrations.
 *
 * @license GNU GPLv3+
 * @author Hélio CG.
 */
class user extends rcube_plugin
{
    public const VERSION = '1.6.0';
    public const SCHEMA_VERSION = 2026110300;

    private const TABLE = 'user_settings';

    private const TABS = [
        'aparencia',
        'interface',
        'mensagem',
        'compose',
        'addressbook',
        'folders',
        'server',
        'calendar',
    ];

    private const COLOR_ROLES = [
        'accent',
        'menu',
        'background',
        'text',
        'selection',
    ];

    private const SKIN_PALETTES = [
        'elastic' => [
            'light' => [
                'accent' => '#37beff',
                'menu' => '#2f3a3f',
                'background' => '#ffffff',
                'text' => '#2f383b',
                'selection' => '#e5f7ff',
            ],
            'dark' => [
                'accent' => '#37beff',
                'menu' => '#20282b',
                'background' => '#21292c',
                'text' => '#c5d1d3',
                'selection' => '#374549',
            ],
        ],
        'carius' => [
            'light' => [
                'accent' => '#5561e6',
                'menu' => '#4f5be0',
                'background' => '#f7f8fd',
                'text' => '#232741',
                'selection' => '#eef0ff',
            ],
            'dark' => [
                'accent' => '#8b95ff',
                'menu' => '#1b1e2b',
                'background' => '#14161f',
                'text' => '#e7e9f5',
                'selection' => '#2b3050',
            ],
        ],
        'iguatu' => [
            'light' => [
                'accent' => '#6b4cf1',
                'menu' => '#2b2b34',
                'background' => '#ffffff',
                'text' => '#232741',
                'selection' => '#f1edff',
            ],
            'dark' => [
                'accent' => '#9a8cff',
                'menu' => '#1f1b33',
                'background' => '#191527',
                'text' => '#e7e2ff',
                'selection' => '#322a55',
            ],
        ],
        'canga' => [
            'light' => [
                'accent' => '#0f5132',
                'menu' => '#0d566d',
                'background' => '#fbfcf8',
                'text' => '#26342c',
                'selection' => '#e1eee2',
            ],
            'dark' => [
                'accent' => '#6fbf8f',
                'menu' => '#083f52',
                'background' => '#0d1411',
                'text' => '#dce9df',
                'selection' => '#1f3a2a',
            ],
        ],
        'aurora' => [
            'light' => [
                'accent' => '#2f9fdd',
                'menu' => '#ffffff',
                'background' => '#ffffff',
                'text' => '#26303d',
                'selection' => '#e8f4fd',
            ],
            'dark' => [
                'accent' => '#5cb9ea',
                'menu' => '#121820',
                'background' => '#121820',
                'text' => '#e4ebf3',
                'selection' => '#1d2a36',
            ],
        ],
    ];

    private const PRESETS = [
        'azul' => [
            'light' => [
                'accent' => '#2563eb',
                'menu' => '#1e3a8a',
                'background' => '#f8fafc',
                'text' => '#1e293b',
                'selection' => '#dbeafe',
            ],
            'dark' => [
                'accent' => '#60a5fa',
                'menu' => '#0f172a',
                'background' => '#0f172a',
                'text' => '#e2e8f0',
                'selection' => '#1e3a8a',
            ],
        ],
        'vermelho' => [
            'light' => [
                'accent' => '#dc2626',
                'menu' => '#7f1d1d',
                'background' => '#fffafa',
                'text' => '#2b1a1a',
                'selection' => '#fee2e2',
            ],
            'dark' => [
                'accent' => '#f87171',
                'menu' => '#1f1111',
                'background' => '#171111',
                'text' => '#f5e6e6',
                'selection' => '#7f1d1d',
            ],
        ],
        'cinza' => [
            'light' => [
                'accent' => '#475569',
                'menu' => '#1f2937',
                'background' => '#f8fafc',
                'text' => '#1f2937',
                'selection' => '#e2e8f0',
            ],
            'dark' => [
                'accent' => '#cbd5e1',
                'menu' => '#0b1220',
                'background' => '#111827',
                'text' => '#e5e7eb',
                'selection' => '#374151',
            ],
        ],
        'marple' => [
            'light' => [
                'accent' => '#b45309',
                'menu' => '#7c2d12',
                'background' => '#fffbeb',
                'text' => '#422006',
                'selection' => '#fde68a',
            ],
            'dark' => [
                'accent' => '#f59e0b',
                'menu' => '#2b170c',
                'background' => '#1a0f08',
                'text' => '#fef3c7',
                'selection' => '#78350f',
            ],
        ],
    ];

    private static ?bool $tableExists = null;

    private ?array $settings = null;

    /**
     * Plugin metadata.
     */
    public static function info()
    {
        return [
            'name' => 'User',
            'vendor' => 'Hélio CG.',
            'version' => self::VERSION,
            'license' => 'GPL-3.0-or-later',
            'uri' => 'https://github.com/helio-cg/roundcubemail',
            'require' => [],
        ];
    }

    /**
     * Plugin initialization.
     */
    #[\Override]
    public function init()
    {
        $this->load_config();
        $this->add_texts('localization/', false);
        $this->include_stylesheet('user-nav.css');

        $this->add_hook('settings_actions', [$this, 'settings_actions']);
        $this->add_hook('render_page', [$this, 'render_page']);

        $this->register_action('plugin.user', [$this, 'settings_page']);
        $this->register_action('plugin.user-save', [$this, 'save_settings']);
        $this->register_handler('plugin.userdisplayname', [$this, 'userdisplayname']);
        $this->register_handler('plugin.inboxunread', [$this, 'inbox_unread']);
    }

    /**
     * Add the "User" entry to the settings navigation.
     */
    public function settings_actions($args)
    {
        $args['actions'][] = [
            'action' => 'plugin.user',
            'class' => 'user',
            'label' => 'usertab',
            'domain' => 'user',
        ];

        return $args;
    }

    /**
     * Last known INBOX unread count for the current session.
     */
    public function inbox_unread()
    {
        $count = (int) ($_SESSION['unseen_count']['INBOX'] ?? 0);

        return $count > 0 ? (string) $count : '';
    }

    /**
     * Inject the saved skin colors into every HTML page.
     */
    public function render_page($args)
    {
        $rcmail = rcmail::get_instance();

        if (!$rcmail->user->ID || empty($args['content'])) {
            return $args;
        }

        $settings = $this->get_settings();

        if (!$settings['stored'] || !$this->skin_colors_enabled()) {
            return $args;
        }

        $style = $this->build_color_style($settings['colors']);
        $args['content'] = str_ireplace('</head>', $style . '</head>', $args['content']);

        return $args;
    }

    /**
     * Display name used by the skin brand area.
     */
    public function userdisplayname()
    {
        $settings = $this->get_settings();
        $name = trim((string) $settings['display_name']);

        return rcube::Q($name !== '' ? $name : $this->gettext('defaultdisplayname'));
    }

    /**
     * Render the settings page.
     */
    public function settings_page()
    {
        $rcmail = rcmail::get_instance();

        $this->add_texts('localization/', true);
        $this->register_handler('plugin.body', [$this, 'settings_body']);
        $this->include_stylesheet('user.css');
        $this->include_script('user.js');

        if ($flash = $this->consume_flash()) {
            $rcmail->output->show_message($flash['message'], $flash['type']);
        }

        $rcmail->output->set_pagetitle($this->gettext('usertab'));
        $rcmail->output->send('plugin');
    }

    /**
     * Build the settings form.
     */
    public function settings_body()
    {
        $rcmail = rcmail::get_instance();
        $settings = $this->get_settings();
        $colors = $settings['colors'];
        $skin = $this->current_skin();
        $hasTable = $this->table_exists($rcmail->get_dbh());
        $presets = $this->presets();
        $activeTab = $this->active_tab();
        $disabled = $hasTable ? '' : ' disabled';

        $tabs = [
            'aparencia' => $this->gettext('tabaparencia'),
            'interface' => $this->gettext('tabinterface'),
            'mensagem' => $this->gettext('tabmensagem'),
            'compose' => $this->gettext('messagescomposition'),
            'addressbook' => $this->gettext('contacts'),
            'folders' => $this->gettext('specialfolders'),
            'server' => $this->gettext('serversettings'),
        ];

        if (in_array('calendar', $rcmail->plugins->loaded_plugins(), true)) {
            $tabs['calendar'] = $rcmail->gettext('calendar', 'calendar');
        }

        $out = '<div class="user-settings">';
        $out .= '<div class="user-settings-head">';
        $out .= '<h3>' . rcube::Q($this->gettext('usertab')) . '</h3>';
        $out .= '<p class="user-settings-lead">' . rcube::Q($this->gettext('userlead')) . '</p>';
        $out .= '</div>';

        if (!$hasTable) {
            $out .= '<p class="user-settings-error">' . rcube::Q($this->gettext('migrationmissing')) . '</p>';
        }

        $out .= '<form class="user-settings-form" method="post" action="?_task=settings&amp;_action=plugin.user-save">';
        $out .= '<input type="hidden" name="_token" value="' . rcube::Q($rcmail->get_request_token()) . '">';
        $out .= '<input type="hidden" name="_preset" id="user-color-preset" value="' . rcube::Q($settings['preset']) . '">';
        $out .= '<input type="hidden" name="_tab" id="user-active-tab" value="' . rcube::Q($activeTab) . '">';

        $out .= '<div class="user-settings-tabs" role="tablist">';

        foreach ($tabs as $key => $label) {
            $active = $activeTab === $key;
            $out .= '<button type="button" class="user-settings-tab' . ($active ? ' is-active' : '') . '"'
                . ' id="user-tab-' . $key . '" role="tab" data-tab="' . $key . '"'
                . ' aria-controls="user-panel-' . $key . '"'
                . ' aria-selected="' . ($active ? 'true' : 'false') . '"'
                . ' tabindex="' . ($active ? '0' : '-1') . '">'
                . rcube::Q($label) . '</button>';
        }

        $out .= '</div>';

        $out .= '<div class="user-settings-panel" id="user-panel-aparencia" role="tabpanel"'
            . ' aria-labelledby="user-tab-aparencia" data-panel="aparencia"'
            . ($activeTab === 'aparencia' ? '' : ' hidden') . '>';

        $out .= '<div class="user-settings-field">';
        $out .= '<label for="user-display-name">' . rcube::Q($this->gettext('displayname')) . '</label>';
        $out .= '<input id="user-display-name" name="_display_name" type="text" maxlength="255"'
            . ' autocomplete="name" placeholder="' . rcube::Q($this->gettext('defaultdisplayname')) . '"'
            . ' value="' . rcube::Q($settings['display_name']) . '"'
            . $disabled . '>';
        $out .= '<p class="user-settings-hint">' . rcube::Q($this->gettext('displaynamehint')) . '</p>';
        $out .= '</div>';

        if ($this->skin_colors_enabled()) {
            $out .= '<fieldset class="user-settings-colors user-settings-presets">';
            $out .= '<legend>' . rcube::Q($this->gettext('colorschemes')) . '</legend>';
            $out .= '<p class="user-settings-hint">' . rcube::Q($this->gettext('colorschemeshint')) . '</p>';
            $out .= '<div class="user-preset-grid">';

            foreach ($presets as $key => $preset) {
                $active = $settings['preset'] === $key;
                $out .= '<button type="button" class="user-preset' . ($active ? ' is-active' : '') . '"'
                    . ' data-preset="' . rcube::Q($key) . '"'
                    . ' data-light="' . rcube::Q(json_encode($preset['light'])) . '"'
                    . ' data-dark="' . rcube::Q(json_encode($preset['dark'])) . '"'
                    . ' aria-pressed="' . ($active ? 'true' : 'false') . '">';
                $out .= '<span class="user-preset-swatches" aria-hidden="true">';

                foreach (self::COLOR_ROLES as $role) {
                    $out .= '<span class="user-preset-swatch" style="--sw-light:'
                        . rcube::Q($preset['light'][$role]) . ';--sw-dark:'
                        . rcube::Q($preset['dark'][$role]) . '"></span>';
                }

                $out .= '</span>';
                $out .= '<span class="user-preset-name">' . rcube::Q($this->gettext('preset' . $key)) . '</span>';
                $out .= '</button>';
            }

            $out .= '</div>';
            $out .= '</fieldset>';

            $out .= '<div class="user-color-columns">';
            $out .= $this->palette_fieldset('light', $this->gettext('lightmode'), $colors['light'], $hasTable);
            $out .= $this->palette_fieldset('dark', $this->gettext('darkmode'), $colors['dark'], $hasTable);
            $out .= '</div>';

            $out .= '<div class="user-preview-grid">';
            $out .= $this->preview_block($colors['light'], 'light', $skin);
            $out .= $this->preview_block($colors['dark'], 'dark', $skin);
            $out .= '</div>';
        }

        $out .= '</div>';

        $out .= '<div class="user-settings-panel" id="user-panel-interface" role="tabpanel"'
            . ' aria-labelledby="user-tab-interface" data-panel="interface"'
            . ($activeTab === 'interface' ? '' : ' hidden') . '>';

        $out .= '<p class="user-settings-lead">' . rcube::Q($this->gettext('interfacelead')) . '</p>';
        $out .= $this->interface_fields($hasTable);

        $out .= '</div>';

        $out .= '<div class="user-settings-panel" id="user-panel-mensagem" role="tabpanel"'
            . ' aria-labelledby="user-tab-mensagem" data-panel="mensagem"'
            . ($activeTab === 'mensagem' ? '' : ' hidden') . '>';

        $out .= '<p class="user-settings-lead">' . rcube::Q($this->gettext('messagelead')) . '</p>';

        $out .= '<fieldset class="user-settings-group">';
        $out .= '<legend>' . rcube::Q($this->gettext('mailboxview')) . '</legend>';
        $out .= '<p class="user-settings-hint">' . rcube::Q($this->gettext('mailboxviewhint')) . '</p>';
        $out .= $this->mailbox_fields($hasTable);
        $out .= '</fieldset>';

        $out .= '<fieldset class="user-settings-group">';
        $out .= '<legend>' . rcube::Q($this->gettext('messagesdisplaying')) . '</legend>';
        $out .= '<p class="user-settings-hint">' . rcube::Q($this->gettext('messagesdisplayinghint')) . '</p>';
        $out .= $this->message_fields($hasTable);
        $out .= '</fieldset>';

        $out .= '</div>';

        foreach (['compose', 'addressbook', 'folders'] as $section) {
            $out .= '<div class="user-settings-panel" id="user-panel-' . $section . '" role="tabpanel"'
                . ' aria-labelledby="user-tab-' . $section . '" data-panel="' . $section . '"'
                . ($activeTab === $section ? '' : ' hidden') . '>';
            $out .= $this->core_prefs_panel($section);
            $out .= '</div>';
        }

        $out .= '<div class="user-settings-panel" id="user-panel-server" role="tabpanel"'
            . ' aria-labelledby="user-tab-server" data-panel="server"'
            . ($activeTab === 'server' ? '' : ' hidden') . '>';
        $out .= $this->core_prefs_panel('server');
        $out .= '<h4 class="user-settings-subtitle">' . rcube::Q($this->gettext('encryption')) . '</h4>';
        $out .= $this->core_prefs_panel('encryption');
        $out .= '</div>';

        if (isset($tabs['calendar'])) {
            $out .= '<div class="user-settings-panel" id="user-panel-calendar" role="tabpanel"'
                . ' aria-labelledby="user-tab-calendar" data-panel="calendar"'
                . ($activeTab === 'calendar' ? '' : ' hidden') . '>';
            $out .= $this->core_prefs_panel('calendar');
            $out .= '</div>';
        }

        $out .= '<div class="user-settings-actions">';
        $out .= '<button class="button mainaction" type="submit"' . $disabled . '>'
            . rcube::Q($this->gettext('save')) . '</button>';
        $out .= '<button class="button" type="submit" name="_reset" value="1"' . $disabled . '>'
            . rcube::Q($this->gettext('reset')) . '</button>';
        $out .= '</div>';
        $out .= '</form>';
        $out .= '</div>';

        return '<div class="formcontainer"><div class="formcontent">' . $out . '</div></div>';
    }

    /**
     * Valid tab for the settings page.
     */
    private function active_tab(): string
    {
        $tab = (string) rcube_utils::get_input_value('_tab', rcube_utils::INPUT_GPC);

        return in_array($tab, self::TABS, true) ? $tab : 'aparencia';
    }

    /**
     * Fields for the mailbox display section.
     */
    private function mailbox_fields(bool $hasTable): string
    {
        $rcmail = rcmail::get_instance();
        $config = $rcmail->config;
        $no_override = array_flip((array) $config->get('dont_override'));
        $disabled = !$hasTable;
        $out = '';

        $supported = (array) $config->get('supported_layouts', ['widescreen', 'desktop', 'list']);

        if (!isset($no_override['layout']) && count($supported) > 1) {
            $labels = [
                'widescreen' => 'layoutwidescreen',
                'desktop' => 'layoutdesktop',
                'list' => 'layoutlist',
            ];
            $options = [];

            foreach (array_intersect_key($labels, array_flip($supported)) as $value => $label) {
                $options[$value] = $this->gettext($label);
            }

            $out .= $this->select_field(
                '_layout',
                'user-layout',
                $this->gettext('layout'),
                $options,
                $config->get('layout') ?: 'widescreen',
                $this->gettext('layouthint'),
                $disabled
            );
        }

        if (!isset($no_override['mail_read_time'])) {
            $options = [
                '-1' => $this->gettext('never'),
                '0' => $this->gettext('immediately'),
            ];

            foreach ([5, 10, 20, 30] as $seconds) {
                $options[$seconds] = sprintf($this->gettext('afterseconds'), $seconds);
            }

            $out .= $this->select_field(
                '_mail_read_time',
                'user-mail-read-time',
                $this->gettext('automarkread'),
                $options,
                (int) $config->get('mail_read_time'),
                $this->gettext('automarkreadhint'),
                $disabled
            );
        }

        if (!isset($no_override['autoexpand_threads'])) {
            $storage = $rcmail->get_storage();

            if ($storage->get_capability('THREAD')) {
                $options = [
                    '0' => $this->gettext('threadsnever'),
                    '1' => $this->gettext('threadsexpand'),
                    '2' => $this->gettext('threadsexpandunread'),
                ];

                $out .= $this->select_field(
                    '_autoexpand_threads',
                    'user-autoexpand-threads',
                    $this->gettext('autoexpandthreads'),
                    $options,
                    (int) $config->get('autoexpand_threads'),
                    $this->gettext('autoexpandthreadshint'),
                    $disabled
                );
            }
        }

        if (!isset($no_override['mail_pagesize'])) {
            $out .= $this->number_field(
                '_mail_pagesize',
                'user-mail-pagesize',
                $this->gettext('pagesize'),
                (int) ($config->get('mail_pagesize') ?: $config->get('pagesize') ?: 50),
                $this->gettext('pagesizehint'),
                2,
                (int) $config->get('max_pagesize') ?: null,
                $disabled
            );
        }

        if (!isset($no_override['check_all_folders'])) {
            $out .= $this->checkbox_field(
                '_check_all_folders',
                'user-check-all-folders',
                $this->gettext('checkallfolders'),
                (bool) $config->get('check_all_folders'),
                $this->gettext('checkallfoldershint'),
                $disabled
            );
        }

        return $out;
    }

    /**
     * Fields for the message display section.
     */
    private function message_fields(bool $hasTable): string
    {
        $rcmail = rcmail::get_instance();
        $config = $rcmail->config;
        $no_override = array_flip((array) $config->get('dont_override'));
        $disabled = !$hasTable;
        $preferHtml = (bool) $config->get('prefer_html');
        $out = '';

        if (!isset($no_override['message_extwin'])) {
            $out .= $this->checkbox_field(
                '_message_extwin',
                'user-message-extwin',
                $this->gettext('showinextwin'),
                (bool) $config->get('message_extwin'),
                '',
                $disabled
            );
        }

        if (!isset($no_override['message_show_email'])) {
            $out .= $this->checkbox_field(
                '_message_show_email',
                'user-message-show-email',
                $this->gettext('showemail'),
                (bool) $config->get('message_show_email'),
                '',
                $disabled
            );
        }

        if (!isset($no_override['prefer_html'])) {
            $out .= $this->checkbox_field(
                '_prefer_html',
                'user-prefer-html',
                $this->gettext('preferhtml'),
                $preferHtml,
                $this->gettext('preferhtmlhint'),
                $disabled
            );
        }

        if (!isset($no_override['show_images'])) {
            $options = [
                '0' => $this->gettext('never'),
                '1' => $this->gettext('frommycontacts'),
                '3' => $this->gettext('fromtrustedsenders'),
                '2' => $this->gettext('always'),
            ];

            $attributes = ' data-show-images="1"' . ($disabled ? ' data-locked="1"' : '');

            $out .= $this->select_field(
                '_show_images',
                'user-show-images',
                $this->gettext('allowremoteresources'),
                $options,
                $preferHtml ? (int) $config->get('show_images') : 0,
                $this->gettext('remoteresourceshint'),
                $disabled || !$preferHtml,
                $attributes
            );
        }

        if (!isset($no_override['inline_images'])) {
            $out .= $this->checkbox_field(
                '_inline_images',
                'user-inline-images',
                $this->gettext('inlineimages'),
                (bool) $config->get('inline_images'),
                '',
                $disabled
            );
        }

        if (!isset($no_override['mdn_requests'])) {
            $options = [
                '0' => $this->gettext('mdnaskuser'),
                '1' => $this->gettext('mdnautosend'),
                '3' => $this->gettext('mdnautosendknown'),
                '4' => $this->gettext('mdnautosendknownignore'),
                '5' => $this->gettext('mdnautosendtrusted'),
                '6' => $this->gettext('mdnautosendtrustedignore'),
                '2' => $this->gettext('mdnignore'),
            ];

            $out .= $this->select_field(
                '_mdn_requests',
                'user-mdn-requests',
                $this->gettext('mdnrequests'),
                $options,
                (int) $config->get('mdn_requests'),
                $this->gettext('mdnrequestshint'),
                $disabled
            );
        }

        return $out;
    }

    /**
     * Fields for the user interface section.
     */
    private function interface_fields(bool $hasTable): string
    {
        $rcmail = rcmail::get_instance();
        $config = $rcmail->config;
        $no_override = array_flip((array) $config->get('dont_override'));
        $disabled = !$hasTable;
        $out = '';

        $main = '';

        if (!isset($no_override['language'])) {
            $languages = $rcmail->list_languages();
            asort($languages);

            $main .= $this->select_field(
                '_language',
                'user-language',
                $this->gettext('language'),
                $languages,
                (string) ($rcmail->user->language ?: $config->get('language')),
                '',
                $disabled
            );
        }

        if (!isset($no_override['timezone'])) {
            $main .= $this->select_field(
                '_timezone',
                'user-timezone',
                $this->gettext('timezone'),
                $this->timezone_options(),
                (string) $config->get('timezone'),
                '',
                $disabled
            );
        }

        if (!isset($no_override['date_format'])) {
            $refdate = mktime(12, 30, 0, 7, 24);
            $defaults = ['Y-m-d', 'd-m-Y', 'Y/m/d', 'm/d/Y', 'd/m/Y', 'd.m.Y', 'j.n.Y'];
            $options = [];

            foreach ((array) $config->get('date_formats', $defaults) as $choice) {
                $options[$choice] = date($choice, $refdate);
            }

            $main .= $this->select_field(
                '_date_format',
                'user-date-format',
                $this->gettext('dateformat'),
                $options,
                (string) $config->get('date_format'),
                '',
                $disabled
            );
        }

        if (!isset($no_override['time_format'])) {
            $reftime = mktime(7, 30, 0);
            $defaults = ['G:i', 'H:i', 'g:i a', 'h:i A'];
            $options = [];

            foreach ((array) $config->get('time_formats', $defaults) as $choice) {
                $options[$choice] = date($choice, $reftime);
            }

            $main .= $this->select_field(
                '_time_format',
                'user-time-format',
                $this->gettext('timeformat'),
                $options,
                (string) $config->get('time_format'),
                '',
                $disabled
            );
        }

        if (!isset($no_override['prettydate'])) {
            $main .= $this->checkbox_field(
                '_pretty_date',
                'user-pretty-date',
                $this->gettext('prettydate'),
                (bool) $config->get('prettydate'),
                '',
                $disabled
            );
        }

        if (!isset($no_override['display_next'])) {
            $main .= $this->checkbox_field(
                '_display_next',
                'user-display-next',
                $this->gettext('displaynext'),
                (bool) $config->get('display_next'),
                '',
                $disabled
            );
        }

        if (!isset($no_override['refresh_interval'])) {
            $options = ['0' => $this->gettext('never')];
            $minInterval = (int) $config->get('min_refresh_interval');

            foreach ([1, 3, 5, 10, 15, 30, 60] as $minutes) {
                if (!$minInterval || $minInterval <= $minutes * 60) {
                    $options[(string) $minutes] = $this->gettext([
                        'name' => 'everynminutes',
                        'vars' => ['n' => $minutes],
                    ]);
                }
            }

            $main .= $this->select_field(
                '_refresh_interval',
                'user-refresh-interval',
                $this->gettext('refreshinterval'),
                $options,
                (int) round(((int) $config->get('refresh_interval')) / 60),
                '',
                $disabled
            );
        }

        if ($main !== '') {
            $out .= '<fieldset class="user-settings-group user-settings-group-main">';
            $out .= '<legend>' . rcube::Q($this->gettext('mainoptions')) . '</legend>';
            $out .= $main;
            $out .= '</fieldset>';
        }

        $side = '';

        if (!isset($no_override['skin'])) {
            $side .= $this->skin_field($disabled);
        }

        if (!isset($no_override['standard_windows'])) {
            $side .= '<fieldset class="user-settings-group">';
            $side .= '<legend>' . rcube::Q($this->gettext('browseroptions')) . '</legend>';
            $side .= $this->checkbox_field(
                '_standard_windows',
                'user-standard-windows',
                $this->gettext('standardwindows'),
                (bool) $config->get('standard_windows'),
                '',
                $disabled
            );
            $side .= '</fieldset>';
        }

        if ($out === '' && $side === '') {
            return '';
        }

        return '<div class="user-settings-columns">'
            . '<div class="user-settings-column">' . $out . '</div>'
            . '<div class="user-settings-column">' . $side . '</div>'
            . '</div>';
    }

    /**
     * Render a preferences section using Roundcube's own settings renderer.
     */
    private function core_prefs_panel(string $section): string
    {
        if (!class_exists('rcmail_action_settings_index')) {
            return '';
        }

        [$sections] = \rcmail_action_settings_index::user_prefs($section);

        if (empty($sections[$section]['blocks'])) {
            return '';
        }

        $out = '<input type="hidden" name="_user_sections[]" value="' . rcube::Q($section) . '">';

        foreach ($sections[$section]['blocks'] as $class => $block) {
            $legend = html::tag('legend', null, $block['name'] ?? '');

            if (!empty($block['options'])) {
                $table = new html_table(['cols' => 2]);

                foreach ($block['options'] as $option) {
                    if (isset($option['title'])) {
                        $table->add('title', $option['title']);
                        $table->add(null, $option['content']);
                    } else {
                        $table->add(['colspan' => 2], $option['content']);
                    }
                }

                $out .= html::tag('fieldset', 'user-settings-group ' . $class, $legend . $table->show());
            } elseif (!empty($block['content'])) {
                $out .= html::tag('fieldset', 'user-settings-group ' . $class, $legend . $block['content']);
            }
        }

        return $out;
    }

    /**
     * Time zone options with localized labels and GMT offsets.
     */
    private function timezone_options(): array
    {
        $options = ['auto' => $this->gettext('autodetect')];

        if (!class_exists('rcmail_action_settings_index')) {
            return $options;
        }

        $zones = [];

        foreach (\DateTimeZone::listIdentifiers() as $timezone) {
            $data = \rcmail_action_settings_index::timezone_standard_time_data($timezone);

            if ($data) {
                $zones[$data['key']] = [$timezone, $data['offset']];
            }
        }

        ksort($zones);

        foreach ($zones as [$timezone, $offset]) {
            $options[$timezone] = '(GMT ' . $offset . ') '
                . \rcmail_action_settings_index::timezone_label($timezone);
        }

        return $options;
    }

    /**
     * Available interface skins.
     */
    private function skin_options(): array
    {
        if (!class_exists('rcmail_action_settings_index')) {
            return [];
        }

        $rcmail = rcmail::get_instance();
        $options = [];

        foreach (\rcmail_action_settings_index::get_skins() as $skin) {
            $meta = $rcmail->output->get_skin_info($skin);
            $options[$skin] = $meta['name'] ?? $skin;
        }

        return $options;
    }

    /**
     * Skin picker with radio buttons and thumbnails, like the original page.
     */
    private function skin_field(bool $disabled): string
    {
        $skins = $this->skin_options();

        if (count($skins) < 2) {
            return '';
        }

        $rcmail = rcmail::get_instance();
        $selected = $this->current_skin();
        $out = '<fieldset class="user-settings-group">';
        $out .= '<legend>' . rcube::Q($this->gettext('skin')) . '</legend>';
        $out .= '<div class="user-skin-grid">';

        foreach ($skins as $skin => $name) {
            $meta = $rcmail->output->get_skin_info($skin);
            $id = 'user-skin-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $skin);
            $author = !empty($meta['author_link'])
                ? $this->gettext(['name' => 'skinauthor', 'vars' => ['author' => $meta['author_link']]])
                : '';
            $license = !empty($meta['license_link'])
                ? $this->gettext('license') . ':&nbsp;' . $meta['license_link']
                : '';

            $out .= '<label class="skinselection" for="' . rcube::Q($id) . '">';
            $out .= '<span class="skinitem"><input type="radio" name="_skin" id="' . rcube::Q($id) . '"'
                . ' value="' . rcube::Q($skin) . '"' . ($selected === $skin ? ' checked' : '')
                . ($disabled ? ' disabled' : '') . '></span>';
            $out .= '<span class="skinitem">' . html::img([
                'src' => $rcmail->output->asset_url("skins/{$skin}/thumbnail.png"),
                'class' => 'skinthumbnail',
                'alt' => $name,
                'width' => 64,
                'height' => 64,
                'onerror' => "this.onerror = null; this.src = 'data:image/gif;base64,"
                    . rcmail_output::BLANK_GIF . "';",
            ]) . '</span>';
            $out .= '<span class="skinitem"><span class="skinname">' . rcube::Q($name) . '</span>';

            if ($author !== '') {
                $out .= '<br><span class="skinauthor">' . rcube::Q($author) . '</span>';
            }

            if ($license !== '') {
                $out .= '<br><span class="skinlicense">' . $license . '</span>';
            }

            $out .= '</span>';
            $out .= '</label>';
        }

        $out .= '</div>';
        $out .= '</fieldset>';

        return $out;
    }

    /**
     * Render one label, control and hint block.
     */
    private function select_field(
        string $name,
        string $id,
        string $label,
        array $options,
        $selected,
        string $hint = '',
        bool $disabled = false,
        string $attributes = ''
    ): string {
        $out = '<div class="user-settings-field">';
        $out .= '<label for="' . rcube::Q($id) . '">' . rcube::Q($label) . '</label>';
        $out .= '<select class="user-settings-select" id="' . rcube::Q($id) . '" name="' . rcube::Q($name) . '"'
            . ($disabled ? ' disabled' : '') . $attributes . '>';

        foreach ($options as $value => $text) {
            $out .= '<option value="' . rcube::Q((string) $value) . '"'
                . ((string) $selected === (string) $value ? ' selected' : '') . '>'
                . rcube::Q($text) . '</option>';
        }

        $out .= '</select>';

        if ($hint !== '') {
            $out .= '<p class="user-settings-hint">' . rcube::Q($hint) . '</p>';
        }

        $out .= '</div>';

        return $out;
    }

    /**
     * Render a numeric input field.
     */
    private function number_field(
        string $name,
        string $id,
        string $label,
        int $value,
        string $hint = '',
        int $min = 0,
        ?int $max = null,
        bool $disabled = false
    ): string {
        $out = '<div class="user-settings-field">';
        $out .= '<label for="' . rcube::Q($id) . '">' . rcube::Q($label) . '</label>';
        $out .= '<input class="user-settings-number" id="' . rcube::Q($id) . '" name="' . rcube::Q($name) . '"'
            . ' type="number" min="' . $min . '"'
            . ($max !== null ? ' max="' . $max . '"' : '')
            . ' value="' . rcube::Q((string) $value) . '"'
            . ($disabled ? ' disabled' : '') . '>';

        if ($hint !== '') {
            $out .= '<p class="user-settings-hint">' . rcube::Q($hint) . '</p>';
        }

        $out .= '</div>';

        return $out;
    }

    /**
     * Render a checkbox field.
     */
    private function checkbox_field(
        string $name,
        string $id,
        string $label,
        bool $checked,
        string $hint = '',
        bool $disabled = false
    ): string {
        $out = '<div class="user-settings-field user-settings-checkfield">';
        $out .= '<label class="user-settings-check" for="' . rcube::Q($id) . '">';
        $out .= '<input type="checkbox" id="' . rcube::Q($id) . '" name="' . rcube::Q($name) . '" value="1"'
            . ($checked ? ' checked' : '') . ($disabled ? ' disabled' : '') . '>';
        $out .= '<span>' . rcube::Q($label) . '</span>';
        $out .= '</label>';

        if ($hint !== '') {
            $out .= '<p class="user-settings-hint">' . rcube::Q($hint) . '</p>';
        }

        $out .= '</div>';

        return $out;
    }

    /**
     * Save or reset the current user's preferences.
     */
    public function save_settings()
    {
        $rcmail = rcmail::get_instance();

        if (!$rcmail->user->ID) {
            $rcmail->output->redirect(['_task' => 'login']);
            exit;
        }

        $tab = (string) rcube_utils::get_input_value('_tab', rcube_utils::INPUT_POST);
        $tab = in_array($tab, self::TABS, true) ? $tab : 'aparencia';
        $redirect = ['_task' => 'settings', '_action' => 'plugin.user', '_tab' => $tab];

        if (!$rcmail->check_request()) {
            $this->flash($this->gettext('errorcsrf'), 'error');
            $rcmail->output->redirect($redirect);
            exit;
        }

        $db = $rcmail->get_dbh();

        if (!$this->table_exists($db)) {
            $this->flash($this->gettext('migrationmissing'), 'error');
            $rcmail->output->redirect($redirect);
            exit;
        }

        $reset = (bool) rcube_utils::get_input_value('_reset', rcube_utils::INPUT_POST);

        if ($reset) {
            $db->query(
                'UPDATE ' . $db->table_name(self::TABLE, true)
                . ' SET colors = NULL, changed = ? WHERE user_id = ?',
                date('Y-m-d H:i:s'),
                $rcmail->user->ID
            );

            $this->flash($this->gettext('resetdone'), 'confirmation');
            $rcmail->output->redirect($redirect);
            exit;
        }

        $name = (string) rcube_utils::get_input_value('_display_name', rcube_utils::INPUT_POST);
        $name = trim($name);

        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 255);
        } else {
            $name = substr($name, 0, 255);
        }

        $current = $this->get_settings();
        $colors = $current['colors'];
        $preset = $current['preset'];

        if ($this->skin_colors_enabled()) {
            $light = $this->palette_input('_colors_light', $current['colors']['light']);
            $dark = $this->palette_input('_colors_dark', $current['colors']['dark']);
            $preset = $this->sanitize_preset(
                (string) rcube_utils::get_input_value('_preset', rcube_utils::INPUT_POST)
            );

            $colors = [
                'preset' => $preset,
                'light' => $light,
                'dark' => $dark,
            ];
        }

        $mailPrefsSaved = $this->save_mail_prefs();
        $interfacePrefsSaved = $this->save_interface_prefs();
        $remainingPrefsSaved = $this->save_remaining_prefs();

        $now = date('Y-m-d H:i:s');
        $table = $db->table_name(self::TABLE, true);

        $db->query(
            'SELECT user_id FROM ' . $table . ' WHERE user_id = ?',
            $rcmail->user->ID
        );

        if ($db->fetch_array()) {
            $db->query(
                'UPDATE ' . $table . ' SET display_name = ?, colors = ?, changed = ? WHERE user_id = ?',
                $name,
                json_encode($colors),
                $now,
                $rcmail->user->ID
            );
        } else {
            $db->query(
                'INSERT INTO ' . $table . ' (user_id, display_name, colors, created, changed) VALUES (?, ?, ?, ?, ?)',
                $rcmail->user->ID,
                $name,
                json_encode($colors),
                $now,
                $now
            );
        }

        if ($db->is_error() || !$mailPrefsSaved || !$interfacePrefsSaved || !$remainingPrefsSaved) {
            $this->flash($this->gettext('errorsave'), 'error');
        } else {
            $this->flash($this->gettext('saved'), 'confirmation');
        }

        $rcmail->output->redirect($redirect);
        exit;
    }

    /**
     * Save the mailbox and message display preferences.
     */
    private function save_mail_prefs(): bool
    {
        $rcmail = rcmail::get_instance();
        $config = $rcmail->config;
        $no_override = array_flip((array) $config->get('dont_override'));

        $mailbox = [];

        if (!isset($no_override['layout'])) {
            $layout = (string) rcube_utils::get_input_value('_layout', rcube_utils::INPUT_POST);
            $supported = (array) $config->get('supported_layouts', ['widescreen', 'desktop', 'list']);

            if (in_array($layout, $supported, true)) {
                $mailbox['layout'] = $layout;
            }
        }

        if (!isset($no_override['mail_read_time'])) {
            $mailbox['mail_read_time'] = (int) rcube_utils::get_input_value('_mail_read_time', rcube_utils::INPUT_POST);
        }

        if (!isset($no_override['autoexpand_threads'])
            && $rcmail->get_storage()->get_capability('THREAD')
        ) {
            $mailbox['autoexpand_threads'] = (int) rcube_utils::get_input_value(
                '_autoexpand_threads',
                rcube_utils::INPUT_POST
            );
        }

        if (!isset($no_override['mail_pagesize'])) {
            $pagesize = max(2, (int) rcube_utils::get_input_value('_mail_pagesize', rcube_utils::INPUT_POST));
            $maxPagesize = (int) $config->get('max_pagesize');

            if ($maxPagesize && $pagesize > $maxPagesize) {
                $pagesize = $maxPagesize;
            }

            $mailbox['mail_pagesize'] = $pagesize;
        }

        if (!isset($no_override['check_all_folders'])) {
            $mailbox['check_all_folders'] = !empty($_POST['_check_all_folders']);
        }

        $mailview = [];

        if (!isset($no_override['message_extwin'])) {
            $mailview['message_extwin'] = !empty($_POST['_message_extwin']) ? 1 : 0;
        }

        if (!isset($no_override['message_show_email'])) {
            $mailview['message_show_email'] = !empty($_POST['_message_show_email']);
        }

        $preferHtml = isset($no_override['prefer_html'])
            ? (bool) $config->get('prefer_html')
            : !empty($_POST['_prefer_html']);

        if (!isset($no_override['prefer_html'])) {
            $mailview['prefer_html'] = $preferHtml;
        }

        if (!isset($no_override['show_images'])) {
            $mailview['show_images'] = $preferHtml
                ? (int) rcube_utils::get_input_value('_show_images', rcube_utils::INPUT_POST)
                : 0;
        }

        if (!isset($no_override['inline_images'])) {
            $mailview['inline_images'] = !empty($_POST['_inline_images']);
        }

        if (!isset($no_override['mdn_requests'])) {
            $mailview['mdn_requests'] = (int) rcube_utils::get_input_value('_mdn_requests', rcube_utils::INPUT_POST);
        }

        $mailbox = $this->filter_prefs_save('mailbox', $mailbox);

        if ($mailbox === null) {
            return false;
        }

        $mailview = $this->filter_prefs_save('mailview', $mailview);

        if ($mailview === null) {
            return false;
        }

        return (bool) $rcmail->user->save_prefs($mailbox + $mailview);
    }

    /**
     * Save the user interface preferences (language, formats, behavior).
     */
    private function save_interface_prefs(): bool
    {
        $rcmail = rcmail::get_instance();
        $config = $rcmail->config;
        $no_override = array_flip((array) $config->get('dont_override'));

        $prefs = [
            'language' => $this->pref_input('language', '/^[a-zA-Z0-9_-]+$/'),
            'timezone' => $this->pref_input('timezone', '/^[a-zA-Z_\/-]+$/'),
            'date_format' => $this->pref_input('date_format', '/^[a-zA-Z_.\/ -]+$/'),
            'time_format' => $this->pref_input('time_format', '/^[a-zA-Z0-9: ]+$/'),
            'prettydate' => isset($_POST['_pretty_date']),
            'display_next' => isset($_POST['_display_next']),
            'refresh_interval' => ((int) rcube_utils::get_input_value(
                '_refresh_interval',
                rcube_utils::INPUT_POST
            )) * 60,
            'standard_windows' => isset($_POST['_standard_windows']),
            'skin' => $this->pref_input('skin', '/^[a-zA-Z0-9_.-]+$/'),
        ];

        foreach ($no_override as $name => $_) {
            $prefs[$name] = $config->get($name);
        }

        if ((isset($_POST['_date_format']) || isset($_POST['_time_format']))
            && !empty($prefs['date_format'])
            && !empty($prefs['time_format'])
        ) {
            $prefs['date_short'] = 'D ' . $prefs['time_format'];
            $prefs['date_long'] = $prefs['date_format'] . ' ' . $prefs['time_format'];
        }

        if (isset($_POST['_language']) && $prefs['language'] != ($_SESSION['language'] ?? '')) {
            $rcmail->load_language($prefs['language']);
        }

        if (!empty($prefs['skin']) && !$rcmail->output->check_skin($prefs['skin'])) {
            unset($prefs['skin']);
        }

        $prefs['timezone'] = (string) $prefs['timezone'];

        $minInterval = (int) $config->get('min_refresh_interval');

        if (!empty($prefs['refresh_interval']) && $minInterval && $prefs['refresh_interval'] < $minInterval) {
            $prefs['refresh_interval'] = $minInterval;
        }

        $prefs = $this->filter_prefs_save('general', $prefs);

        if ($prefs === null) {
            return false;
        }

        return (bool) $rcmail->user->save_prefs($prefs);
    }

    /**
     * Read and validate a textual preference from the request.
     */
    private function pref_input(string $name, string $pattern): ?string
    {
        $rcmail = rcmail::get_instance();
        $value = rcube_utils::get_input_value('_' . $name, rcube_utils::INPUT_POST);

        if (!is_string($value)) {
            return null;
        }

        if ($value !== '' && !preg_match($pattern, $value)) {
            return (string) $rcmail->config->get($name);
        }

        return $value;
    }

    /**
     * Integer preference from the request.
     */
    private function post_int(string $name): int
    {
        return (int) rcube_utils::get_input_value('_' . $name, rcube_utils::INPUT_POST);
    }

    /**
     * Raw (unescaped) preference from the request.
     */
    private function post_raw(string $name): string
    {
        return (string) rcube_utils::get_input_value('_' . $name, rcube_utils::INPUT_POST, true);
    }

    /**
     * Save the remaining core preferences sections.
     */
    private function save_remaining_prefs(): bool
    {
        $rcmail = rcmail::get_instance();
        $config = $rcmail->config;
        $no_override = array_flip((array) $config->get('dont_override'));

        $sections = ['compose', 'addressbook', 'folders', 'server', 'encryption'];

        if (in_array('calendar', $rcmail->plugins->loaded_plugins(), true)) {
            $sections[] = 'calendar';
        }

        $submitted = (array) rcube_utils::get_input_value('_user_sections', rcube_utils::INPUT_POST);
        $prefs = [];

        foreach ($sections as $section) {
            if (!in_array($section, $submitted, true)) {
                continue;
            }

            $sectionPrefs = $this->filter_prefs_save($section, $this->section_prefs($section));

            if ($sectionPrefs === null) {
                return false;
            }

            foreach ($no_override as $name => $_) {
                $sectionPrefs[$name] = $config->get($name);
            }

            $prefs += $this->verify_prefs($section, $sectionPrefs);
        }

        return (bool) $rcmail->user->save_prefs($prefs);
    }

    /**
     * Read one core preferences section from the request.
     */
    private function section_prefs(string $section): array
    {
        switch ($section) {
            case 'compose':
                return [
                    'compose_extwin' => $this->post_int('compose_extwin'),
                    'htmleditor' => $this->post_int('htmleditor'),
                    'draft_autosave' => $this->post_int('draft_autosave'),
                    'mime_param_folding' => $this->post_int('mime_param_folding'),
                    'force_7bit' => isset($_POST['_force_7bit']),
                    'mdn_default' => isset($_POST['_mdn_default']),
                    'dsn_default' => isset($_POST['_dsn_default']),
                    'keep_formatting_default' => isset($_POST['_keep_formatting_default']),
                    'reply_same_folder' => isset($_POST['_reply_same_folder']),
                    'spellcheck_before_send' => isset($_POST['_spellcheck_before_send']),
                    'spellcheck_ignore_syms' => isset($_POST['_spellcheck_ignore_syms']),
                    'spellcheck_ignore_nums' => isset($_POST['_spellcheck_ignore_nums']),
                    'spellcheck_ignore_caps' => isset($_POST['_spellcheck_ignore_caps']),
                    'show_sig' => $this->post_int('show_sig'),
                    'reply_mode' => $this->post_int('reply_mode'),
                    'enable_autolink' => isset($_POST['_enable_autolink']),
                    'sig_below' => isset($_POST['_sig_below']),
                    'strip_existing_sig' => isset($_POST['_strip_existing_sig']),
                    'sig_separator' => isset($_POST['_sig_separator']),
                    'default_font' => $this->pref_input('default_font', '/^[a-zA-Z ]+$/'),
                    'default_font_size' => $this->pref_input('default_font_size', '/^[0-9]+pt$/'),
                    'reply_all_mode' => $this->post_int('reply_all_mode'),
                    'forward_attachment' => !empty($_POST['_forward_attachment']),
                    'compose_save_localstorage' => $this->post_int('compose_save_localstorage'),
                ];

            case 'addressbook':
                return [
                    'default_addressbook' => $this->post_raw('default_addressbook'),
                    'collected_recipients' => $this->post_raw('collected_recipients'),
                    'collected_senders' => $this->post_raw('collected_senders'),
                    'autocomplete_single' => isset($_POST['_autocomplete_single']),
                    'addressbook_sort_col' => $this->pref_input('addressbook_sort_col', '/^[a-z_]+$/'),
                    'addressbook_name_listing' => $this->post_int('addressbook_name_listing'),
                    'addressbook_pagesize' => max(2, $this->post_int('addressbook_pagesize')),
                    'contact_form_mode' => $this->pref_input('contact_form_mode', '/^(private|business)$/'),
                ];

            case 'folders':
                $dont_override = (array) rcmail::get_instance()->config->get('dont_override');
                $prefs = [
                    'show_real_foldernames' => isset($_POST['_show_real_foldernames']),
                    'lock_special_folders' => !in_array('lock_special_folders', $dont_override),
                ];

                foreach (\rcube_storage::$folder_types as $type) {
                    $prefs[$type . '_mbox'] = $this->post_raw($type . '_mbox');
                }

                return $prefs;

            case 'server':
                return [
                    'read_when_deleted' => isset($_POST['_read_when_deleted']),
                    'skip_deleted' => isset($_POST['_skip_deleted']),
                    'flag_for_deletion' => isset($_POST['_flag_for_deletion']),
                    'delete_junk' => isset($_POST['_delete_junk']),
                    'logout_purge' => $this->pref_input('logout_purge', '/^(all|never|30|60|90)$/'),
                    'logout_expunge' => isset($_POST['_logout_expunge']),
                ];

            case 'encryption':
                return [
                    'mailvelope_main_keyring' => isset($_POST['_mailvelope_main_keyring']),
                ];
        }

        return [];
    }

    /**
     * Validate a section's preferences before saving.
     */
    private function verify_prefs(string $section, array $prefs): array
    {
        $rcmail = rcmail::get_instance();
        $config = $rcmail->config;

        switch ($section) {
            case 'addressbook':
                if (($prefs['addressbook_pagesize'] ?? 0) < 1) {
                    $prefs['addressbook_pagesize'] = 10;
                }

                $maxPagesize = (int) $config->get('max_pagesize');

                if ($maxPagesize && ($prefs['addressbook_pagesize'] ?? 0) > $maxPagesize) {
                    $prefs['addressbook_pagesize'] = $maxPagesize;
                }

                break;

            case 'folders':
                $specials = [];

                foreach (\rcube_storage::$folder_types as $type) {
                    $specials[$type] = $prefs[$type . '_mbox'] ?? '';
                }

                $rcmail->get_storage()->set_special_folders($specials);

                break;

            case 'server':
                if (isset($prefs['logout_purge']) && !is_numeric($prefs['logout_purge'])) {
                    $prefs['logout_purge'] = $prefs['logout_purge'] !== 'never';
                }

                break;
        }

        return $prefs;
    }

    /**
     * Run the preferences_save hook for one section.
     */
    private function filter_prefs_save(string $section, array $prefs): ?array
    {
        $plugin = rcmail::get_instance()->plugins->exec_hook('preferences_save', [
            'prefs' => $prefs,
            'section' => $section,
        ]);

        if (!empty($plugin['abort'])) {
            return null;
        }

        return is_array($plugin['prefs'] ?? null) ? $plugin['prefs'] : $prefs;
    }

    /**
     * Read the current user's settings, falling back to the skin palette.
     */
    private function get_settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $rcmail = rcmail::get_instance();
        $defaults = $this->default_settings();
        $this->settings = $defaults;

        if (!$rcmail->user->ID) {
            return $this->settings;
        }

        $db = $rcmail->get_dbh();

        if (!$this->table_exists($db)) {
            return $this->settings;
        }

        $result = $db->query(
            'SELECT display_name, colors FROM ' . $db->table_name(self::TABLE, true) . ' WHERE user_id = ?',
            $rcmail->user->ID
        );

        if ($row = $db->fetch_assoc($result)) {
            $this->settings['display_name'] = (string) $row['display_name'];

            if (!empty($row['colors'])) {
                $decoded = $this->decode_colors($row['colors'], $defaults['colors']);

                $this->settings['stored'] = true;
                $this->settings['preset'] = $decoded['preset'];

                // The "atual" preset follows the active skin, so a palette
                // saved on another skin must not leak into this one.
                $this->settings['colors'] = $decoded['preset'] === 'atual'
                    ? $defaults['colors']
                    : $decoded['colors'];
            }
        }

        return $this->settings;
    }

    /**
     * Name of the skin currently being rendered.
     *
     * The config value is the default skin, not necessarily the one the user
     * picked, so prefer the skin the output layer has actually loaded.
     */
    private function current_skin(): string
    {
        $rcmail = rcmail::get_instance();
        $skin = '';

        if (isset($rcmail->output) && is_object($rcmail->output)
            && method_exists($rcmail->output, 'get_env')
        ) {
            $skin = (string) $rcmail->output->get_env('skin');
        }

        if ($skin === '') {
            $skin = (string) $rcmail->config->get('skin', 'elastic');
        }

        return $skin !== '' ? $skin : 'elastic';
    }

    /**
     * Default values for the current skin.
     */
    private function default_settings(): array
    {
        $skin = $this->current_skin();
        $palette = self::SKIN_PALETTES[$skin] ?? self::SKIN_PALETTES['elastic'];

        return [
            'display_name' => '',
            'colors' => [
                'light' => $palette['light'],
                'dark' => $palette['dark'],
            ],
            'preset' => 'atual',
            'stored' => false,
        ];
    }

    /**
     * Validate a stored color map and merge it with the defaults.
     */
    private function decode_colors($json, array $defaults): array
    {
        $colors = $defaults;
        $preset = 'custom';
        $decoded = is_string($json) ? json_decode($json, true) : null;

        if (is_array($decoded)) {
            if (isset($decoded['light']) || isset($decoded['dark'])) {
                $colors['light'] = $this->validate_palette(
                    is_array($decoded['light'] ?? null) ? $decoded['light'] : [],
                    $defaults['light']
                );
                $colors['dark'] = $this->validate_palette(
                    is_array($decoded['dark'] ?? null) ? $decoded['dark'] : [],
                    $defaults['dark']
                );
                $preset = $this->sanitize_preset((string) ($decoded['preset'] ?? 'custom'));
            } elseif (isset($decoded['accent'])) {
                $colors['light'] = $this->validate_palette($decoded, $defaults['light']);
            }
        }

        return [
            'colors' => $colors,
            'preset' => $preset,
        ];
    }

    /**
     * Validate a palette received from the request.
     */
    private function palette_input(string $name, array $default): array
    {
        $input = rcube_utils::get_input_value($name, rcube_utils::INPUT_POST);

        return $this->validate_palette(is_array($input) ? $input : [], $default);
    }

    /**
     * Validate a palette and fill missing roles with fallback values.
     */
    private function validate_palette(array $input, array $fallback): array
    {
        $colors = $fallback;

        foreach (self::COLOR_ROLES as $role) {
            if (isset($input[$role]) && is_string($input[$role])
                && preg_match('/^#[0-9a-f]{6}$/i', $input[$role])
            ) {
                $colors[$role] = strtolower($input[$role]);
            }
        }

        return $colors;
    }

    /**
     * Validate a preset identifier.
     */
    private function sanitize_preset(string $preset): string
    {
        if ($preset === 'atual' || $preset === 'custom' || isset(self::PRESETS[$preset])) {
            return $preset;
        }

        return 'custom';
    }

    /**
     * Available color schemes, including the active skin palette.
     */
    private function presets(): array
    {
        $skin = $this->current_skin();
        $current = self::SKIN_PALETTES[$skin] ?? self::SKIN_PALETTES['elastic'];

        return ['atual' => $current] + self::PRESETS;
    }

    /**
     * Build one light/dark palette fieldset.
     */
    private function palette_fieldset(string $mode, string $legend, array $colors, bool $hasTable): string
    {
        $out = '<fieldset class="user-settings-colors">';
        $out .= '<legend>' . rcube::Q($legend) . '</legend>';
        $out .= '<div class="user-color-grid">';

        foreach (self::COLOR_ROLES as $role) {
            $id = 'user-color-' . $mode . '-' . $role;
            $out .= '<label class="user-color" for="' . $id . '">';
            $out .= '<span class="user-color-label">' . rcube::Q($this->gettext('color' . $role)) . '</span>';
            $out .= '<input id="' . $id . '" name="_colors_' . $mode . '[' . $role . ']" type="color"'
                . ' data-mode="' . $mode . '" data-role="' . $role . '"'
                . ' value="' . rcube::Q($colors[$role]) . '"'
                . ($hasTable ? '' : ' disabled') . '>';
            $out .= '</label>';
        }

        $out .= '</div>';
        $out .= '</fieldset>';

        return $out;
    }

    /**
     * Check whether the plugin's table exists.
     */
    private function table_exists($db): bool
    {
        if (self::$tableExists === null) {
            self::$tableExists = in_array($db->table_name(self::TABLE), (array) $db->list_tables());
        }

        return self::$tableExists;
    }

    /**
     * Whether color customization is enabled by configuration.
     */
    private function skin_colors_enabled(): bool
    {
        return (bool) rcmail::get_instance()->config->get('user_enable_skin_colors', true);
    }

    /**
     * Build the CSS that applies the saved colors to the interface.
     */
    private function build_color_style(array $colors): string
    {
        $light = $colors['light'];
        $dark = $colors['dark'];

        $css = '<style id="user-skin-colors">'
            . ':root{--user-accent:' . $light['accent'] . ';--user-menu:' . $light['menu'] . ';'
            . '--user-background:' . $light['background'] . ';--user-text:' . $light['text'] . ';'
            . '--user-selection:' . $light['selection'] . ';}'
            . 'html.dark-mode{--user-accent:' . $dark['accent'] . ';--user-menu:' . $dark['menu'] . ';'
            . '--user-background:' . $dark['background'] . ';--user-text:' . $dark['text'] . ';'
            . '--user-selection:' . $dark['selection'] . ';'
            . 'background-color:' . $dark['background'] . ' !important;}'
            . 'html{scrollbar-color:' . $light['accent'] . ' ' . $light['background'] . ';}'
            . 'html ::-webkit-scrollbar-track{background:' . $light['background'] . ' !important;}'
            . 'html ::-webkit-scrollbar-thumb{background:' . $light['accent'] . ' !important;border-radius:6px;}'
            . 'html.dark-mode{scrollbar-color:' . $dark['accent'] . ' ' . $dark['background'] . ';}'
            . 'html.dark-mode ::-webkit-scrollbar-track{background:' . $dark['background'] . ' !important;}'
            . 'html.dark-mode ::-webkit-scrollbar-thumb{background:' . $dark['accent'] . ' !important;border-radius:6px;}'
            . $this->color_rules($light)
            . $this->color_rules($dark, 'html.dark-mode')
            . '</style>';

        return $css;
    }

    /**
     * CSS rules for one palette, optionally scoped to dark mode.
     */
    private function color_rules(array $colors, string $prefix = ''): string
    {
        $menuText = $this->contrast_color($colors['menu']);
        $accentText = $this->contrast_color($colors['accent']);
        $selectionText = $this->contrast_color($colors['selection']);

        $backgroundSelectors = $this->scoped_selectors([
            'body',
            '#layout',
            '#layout-content',
            '#layout-list',
            '#layout-sidebar',
            '.message-content',
            '#messagebody',
            '.message-part',
            '.message-htmlpart',
            '.listing',
            '.scroller',
            '.iframe-wrapper',
            '.boxcontent',
        ], $prefix);

        $textSelectors = $this->scoped_selectors([
            'body',
            '.message-content',
            '#messagebody',
            '.listing',
            '.listing a',
            '.boxcontent',
            '#layout-content .header',
        ], $prefix);

        $linkSelectors = $this->scoped_selectors([
            'a:not(.fc-event)',
            '#layout-content a:not(.fc-event)',
            '.listing a:hover',
        ], $prefix);

        $menuSelectors = $this->scoped_selectors([
            '#taskmenu',
            '.taskmenu',
        ], $prefix);

        $menuTextSelectors = $this->scoped_selectors([
            '#taskmenu a',
            '#taskmenu a:before',
            '#layout-menu #taskmenu>a',
            '#layout-menu #taskmenu>a:before',
        ], $prefix);

        $buttonSelectors = $this->scoped_selectors([
            '.button.primary',
            '.btn-primary',
            'button.primary',
            'input.button.primary',
            '.mainaction',
            '.formbuttons button.mainaction',
            '.button.mainaction',
        ], $prefix);

        $selectionSelectors = $this->scoped_selectors([
            '.listing li.selected',
            '.listing li.selected>a',
            '.listing tr.selected>td',
            '.menu li.selected>a',
            '.mailbox li.selected>a',
            '#layout-sidebar .listing li.selected',
            '#layout-sidebar .listing li.selected>a',
        ], $prefix);

        return $textSelectors . '{color:' . $colors['text'] . ' !important;}'
            . $backgroundSelectors . '{background-color:' . $colors['background'] . ' !important;}'
            . $linkSelectors . '{color:' . $colors['accent'] . ' !important;}'
            . $menuSelectors . '{background-color:' . $colors['menu'] . ' !important;}'
            . $menuTextSelectors . '{color:' . $menuText . ' !important;}'
            . $buttonSelectors . '{background-color:' . $colors['accent'] . ' !important;'
            . 'border-color:' . $colors['accent'] . ' !important;color:' . $accentText . ' !important;}'
            . $selectionSelectors . '{background-color:' . $colors['selection'] . ' !important;}'
            . $this->canga_rules($colors, $prefix, $menuText, $selectionText)
            . $this->carius_rules($colors, $prefix, $menuText, $selectionText)
            . $this->chrome_rules($colors, $prefix, $menuText, $accentText, $selectionText)
            . $this->plugin_rules($colors, $prefix, $menuText, $accentText, $selectionText);
    }

    /**
     * Carius top bar, account popover and task buttons.
     */
    private function carius_rules(array $colors, string $prefix, string $menuText, string $selectionText): string
    {
        $accentText = $this->contrast_color($colors['accent']);

        $topbar = $this->scoped_selectors([
            '#layout-menu.carius-topbar',
        ], $prefix);

        $topbarText = $this->scoped_selectors([
            '#layout-menu.carius-topbar',
            '#layout-menu.carius-topbar .carius-brand',
            '#layout-menu.carius-topbar .carius-brand-name',
            '#layout-menu.carius-topbar #taskmenu > a',
            '#layout-menu.carius-topbar #taskmenu > a:before',
            '#layout-menu.carius-topbar .carius-icon-btn',
            '#layout-menu.carius-topbar .carius-avatar',
        ], $prefix);

        $topbarHover = $this->scoped_selectors([
            '#layout-menu.carius-topbar #taskmenu > a:hover',
            '#layout-menu.carius-topbar #taskmenu > a:focus',
            '#layout-menu.carius-topbar #taskmenu > a.selected',
            '#layout-menu.carius-topbar #taskmenu > a.button-selected',
            '#layout-menu.carius-topbar .carius-icon-btn:hover',
            '#layout-menu.carius-topbar .carius-icon-btn:focus',
            '#layout-menu.carius-topbar .carius-avatar:hover',
            '#layout-menu.carius-topbar .carius-avatar:focus',
        ], $prefix);

        $popover = $this->scoped_selectors([
            '.carius-popover',
        ], $prefix);

        $popoverText = $this->scoped_selectors([
            '.carius-popover',
            '.carius-popover-title',
            '.carius-account-name',
            '.carius-account-email',
            '.carius-bell-text',
            '.carius-menu-item',
        ], $prefix);

        $popoverHover = $this->scoped_selectors([
            '.carius-menu-item:hover',
            '.carius-menu-item:focus',
            '.carius-popover-link:hover',
        ], $prefix);

        $listHeader = $this->scoped_selectors([
            '#layout-list > .header',
            '#layout-list > .header .header-title',
        ], $prefix);

        $listButtons = $this->scoped_selectors([
            '#layout-list > .header .carius-btn',
            '#layout-list > .header .carius-btn .inner',
        ], $prefix);

        $listPrimary = $this->scoped_selectors([
            '#layout-list > .header .carius-btn-primary',
            '#layout-list > .header .carius-btn-primary .inner',
        ], $prefix);

        $listButtonsHover = $this->scoped_selectors([
            '#layout-list > .header .carius-btn:hover',
            '#layout-list > .header .carius-btn:focus',
            '#layout-list > .header .carius-btn-primary:hover',
            '#layout-list > .header .carius-btn-primary:focus',
        ], $prefix);

        return $topbar . '{background:' . $colors['menu'] . ' !important;}'
            . $topbarText . '{color:' . $menuText . ' !important;}'
            . $topbarHover . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $selectionText . ' !important;}'
            . $popover . '{background:' . $colors['background'] . ' !important;'
            . 'border-color:rgba(128,128,128,.35) !important;}'
            . $popoverText . '{color:' . $colors['text'] . ' !important;}'
            . $popoverHover . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $selectionText . ' !important;}'
            . $listHeader . '{background:' . $colors['background'] . ' !important;'
            . 'color:' . $colors['text'] . ' !important;}'
            . $listButtons . '{background:' . $colors['background'] . ' !important;'
            . 'border-color:rgba(128,128,128,.35) !important;'
            . 'color:' . $colors['text'] . ' !important;}'
            . $listPrimary . '{background:' . $colors['accent'] . ' !important;'
            . 'border-color:' . $colors['accent'] . ' !important;'
            . 'color:' . $accentText . ' !important;}'
            . $listButtonsHover . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $selectionText . ' !important;}';
    }

    /**
     * Message view, toolbars, popup menus, compose and scrollbars.
     */
    private function chrome_rules(array $colors, string $prefix, string $menuText, string $accentText, string $selectionText): string
    {
        $surfaces = $this->scoped_selectors([
            '#layout-content > .header',
            '#message-header',
            '.message-partheaders',
            '.message-part',
            '.message-htmlpart',
            '#messagebody',
            '.attachmentslist',
            '#compose-content',
            '#compose-content .formcontent.scroller',
            '#composebody',
            'body.action-compose #layout > #layout-sidebar',
            '.popupmenu',
            '.popupmenu .menu',
            '.popupmenu .menu.listing',
            '.canga-header-toolbar .toolbar.menu a',
            '.canga-header-toolbar > .toolbar-button',
            '.canga-header-toolbar > a.task-menu-button',
            '.canga-header-toolbar > a.toolbar-menu-button',
            '.canga-header-toolbar > a.toolbar-list-button',
        ], $prefix);

        $text = $this->scoped_selectors([
            '#layout-content > .header',
            '#layout-content > .header .header-title',
            '#message-header',
            '#message-header .subject',
            '#message-header .header-summary',
            '#message-header .header-links a',
            '.message-partheaders',
            '.message-part',
            '.message-htmlpart',
            '#messagebody',
            '#compose-content',
            '#composebody',
            '#compose-content .compose-headers label',
            '#compose-content .form-group label',
            '.popupmenu .menu li a',
            '.popupmenu .menu li a span',
            '.canga-header-toolbar .toolbar.menu a',
            '.canga-header-toolbar > .toolbar-button',
            '.canga-header-toolbar > a.task-menu-button',
            '.canga-header-toolbar > a.toolbar-menu-button',
            '.canga-header-toolbar > a.toolbar-list-button',
        ], $prefix);

        $icons = $this->scoped_selectors([
            '#layout-content > .header .toolbar.menu a:before',
            '#layout-content > .header .toolbar-button:before',
            '#layout-content > .header .dropbutton > a:before',
            '.popupmenu .menu li a:before',
            '.canga-header-toolbar .toolbar.menu a:before',
            '.canga-header-toolbar > .toolbar-button:before',
            '.canga-header-toolbar > a.task-menu-button:before',
            '.canga-header-toolbar > a.toolbar-menu-button:before',
            '.canga-header-toolbar > a.toolbar-list-button:before',
        ], $prefix);

        $headerButtons = $this->scoped_selectors([
            '#layout-content > .header .toolbar.menu a',
            '#layout-content > .header .toolbar-button',
            '#layout-content > .header .dropbutton > a',
        ], $prefix);

        $headerButtonsHover = $this->scoped_selectors([
            '#layout-content > .header .toolbar.menu a:hover',
            '#layout-content > .header .toolbar-button:hover',
            '#layout-content > .header .dropbutton > a:hover',
        ], $prefix);

        $popupHover = $this->scoped_selectors([
            '.popupmenu .menu li a:hover',
            '.popupmenu .menu li a:focus',
            '.popupmenu .menu li a.active',
        ], $prefix);

        $popupHoverIcons = $this->scoped_selectors([
            '.popupmenu .menu li a:hover:before',
            '.popupmenu .menu li a:focus:before',
            '.popupmenu .menu li a.active:before',
        ], $prefix);

        $cangaCardsHover = $this->scoped_selectors([
            '.canga-header-toolbar .toolbar.menu a:hover',
            '.canga-header-toolbar > .toolbar-button:hover',
            '.canga-header-toolbar > a.task-menu-button:hover',
            '.canga-header-toolbar > a.toolbar-menu-button:hover',
            '.canga-header-toolbar > a.toolbar-list-button:hover',
        ], $prefix);

        $inputs = $this->scoped_selectors([
            '#compose-content input',
            '#compose-content select',
            '#compose-content textarea',
            '#composebody',
        ], $prefix);

        return $surfaces . '{background:' . $colors['background'] . ' !important;'
            . 'color:' . $colors['text'] . ' !important;}'
            . $text . '{color:' . $colors['text'] . ' !important;}'
            . $icons . '{color:' . $colors['accent'] . ' !important;}'
            . $headerButtons . '{background:transparent !important;'
            . 'border-color:transparent !important;'
            . 'color:' . $colors['text'] . ' !important;}'
            . $headerButtonsHover . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $selectionText . ' !important;}'
            . $popupHover . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $selectionText . ' !important;}'
            . $popupHoverIcons . '{color:' . $selectionText . ' !important;}'
            . $cangaCardsHover . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $selectionText . ' !important;}'
            . $inputs . '{background:' . $colors['background'] . ' !important;'
            . 'border-color:rgba(128,128,128,.35) !important;'
            . 'color:' . $colors['text'] . ' !important;}';
    }

    /**
     * Canga-specific chrome: top bar, brand, page header and dropdown.
     */
    private function canga_rules(array $colors, string $prefix, string $menuText, string $selectionText): string
    {
        $topbar = $this->scoped_selectors([
            '#canga-topbar',
            '#layout-menu.canga-menu',
        ], $prefix);

        $topbarText = $this->scoped_selectors([
            '#canga-topbar',
            '#canga-topbar .canga-topbar-trigger',
            '#canga-topbar .canga-topbar-label',
            '#canga-topbar .canga-topbar-icon',
            '#canga-topbar .canga-topbar-caret:before',
            '#layout-menu.canga-menu .canga-brand-name',
            '#layout-menu.canga-menu .canga-brand-user',
            '#layout-menu.canga-menu .canga-brand a.cancel',
        ], $prefix);

        $topbarHoverSelectors = [];
        $topbarStates = [
            ':hover',
            ':focus',
            ':active',
            '.active',
            '.is-active',
            '[aria-expanded="true"]',
        ];

        foreach ($topbarStates as $state) {
            $trigger = '#canga-topbar .canga-topbar-trigger' . $state;

            $topbarHoverSelectors[] = $trigger;
            $topbarHoverSelectors[] = $trigger . ' .canga-topbar-label';
            $topbarHoverSelectors[] = $trigger . ' .canga-topbar-icon';
            $topbarHoverSelectors[] = $trigger . ' .canga-topbar-caret:before';
        }

        $topbarHover = $this->scoped_selectors($topbarHoverSelectors, $prefix);

        $dropdown = $this->scoped_selectors(['.canga-topbar-dropdown'], $prefix);
        $dropdownText = $this->scoped_selectors(['.canga-topbar-dropdown a'], $prefix);
        $dropdownIcon = $this->scoped_selectors(['.canga-topbar-dropdown a:before'], $prefix);
        $dropdownHover = $this->scoped_selectors([
            '.canga-topbar-dropdown a:hover',
            '.canga-topbar-dropdown a:focus',
            '.canga-topbar-dropdown a.active',
        ], $prefix);
        $dropdownHoverIcon = $this->scoped_selectors([
            '.canga-topbar-dropdown a:hover:before',
            '.canga-topbar-dropdown a:focus:before',
            '.canga-topbar-dropdown a.active:before',
        ], $prefix);

        $pageheader = $this->scoped_selectors(['#canga-pageheader', '.canga-pageheader'], $prefix);
        $pageheaderTitle = $this->scoped_selectors(['#canga-pageheader-title'], $prefix);
        $pageheaderSubtitle = $this->scoped_selectors(['#canga-pageheader-subtitle'], $prefix);

        $staticMenuBase = 'body #layout-menu.canga-menu.canga-menu .canga-menu-nav'
            . ' > .canga-static-submenu a';
        $staticMenuClasses = ['inbox', 'contacts-all', 'calendar-all', 'preferences'];
        $staticMenuHoverBackground = [];
        $staticMenuHoverContent = [];
        $staticMenuActiveBackground = [];
        $staticMenuActiveContent = [];

        foreach ($staticMenuClasses as $class) {
            $link = $staticMenuBase . '.' . $class;

            $staticMenuHoverBackground[] = $link . ':hover';
            $staticMenuHoverBackground[] = $link . ':focus';
            $staticMenuHoverContent[] = $link . ':hover:before';
            $staticMenuHoverContent[] = $link . ':focus:before';
            $staticMenuActiveBackground[] = $link . '.active';
            $staticMenuActiveContent[] = $link . '.active:before';
        }

        $staticMenuHoverBackground[] = $staticMenuBase . ':hover';
        $staticMenuHoverBackground[] = $staticMenuBase . ':focus';
        $staticMenuHoverContent[] = $staticMenuBase . ':hover:before';
        $staticMenuHoverContent[] = $staticMenuBase . ':focus:before';
        $staticMenuActiveBackground[] = $staticMenuBase . '.active';
        $staticMenuActiveContent[] = $staticMenuBase . '.active:before';

        $staticMenuHoverBackground = $this->scoped_selectors($staticMenuHoverBackground, $prefix);
        $staticMenuHoverContent = $this->scoped_selectors($staticMenuHoverContent, $prefix);
        $staticMenuActiveBackground = $this->scoped_selectors($staticMenuActiveBackground, $prefix);
        $staticMenuActiveContent = $this->scoped_selectors($staticMenuActiveContent, $prefix);

        return $topbar . '{background:' . $colors['menu'] . ' !important;}'
            . $topbarText . '{color:' . $menuText . ' !important;}'
            . $topbarHover . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $selectionText . ' !important;}'
            . $dropdown . '{background:' . $colors['background'] . ' !important;'
            . 'border-color:rgba(128,128,128,.35) !important;}'
            . $dropdownText . '{color:' . $colors['text'] . ' !important;}'
            . $dropdownIcon . '{color:' . $colors['accent'] . ' !important;}'
            . $dropdownHover . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $selectionText . ' !important;}'
            . $dropdownHoverIcon . '{color:' . $selectionText . ' !important;}'
            . $pageheader . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $colors['text'] . ' !important;'
            . 'border-bottom-color:rgba(128,128,128,.3) !important;}'
            . $pageheaderTitle . '{color:' . $colors['text'] . ' !important;}'
            . $pageheaderSubtitle . '{color:' . $colors['text'] . ' !important;opacity:.72;}'
            . $staticMenuHoverBackground . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $selectionText . ' !important;}'
            . $staticMenuHoverContent . '{color:' . $selectionText . ' !important;}'
            . $staticMenuActiveBackground . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $selectionText . ' !important;'
            . 'box-shadow: inset 3px 0 0 ' . $colors['accent'] . ' !important;}'
            . $staticMenuActiveContent . '{color:' . $selectionText . ' !important;}';
    }

    /**
     * Plugin pages: calendar, two-factor authentication and generic dialogs.
     */
    private function plugin_rules(array $colors, string $prefix, string $menuText, string $accentText, string $selectionText): string
    {
        $pluginPrefix = $prefix === '' ? 'body' : $prefix . ' body';

        $containers = $this->scoped_selectors([
            '.formcontainer',
            '.formcontent',
            '.settingsbox',
            '.boxcontent',
            '.boxtitle',
            '.propform',
            '.ui-dialog',
            '.ui-dialog-content',
            '.ui-widget-content',
        ], $pluginPrefix);

        $containerText = $this->scoped_selectors([
            '.formcontainer',
            '.formcontent',
            '.settingsbox',
            '.boxcontent',
            '.boxtitle',
            '.propform',
            '.ui-dialog',
            '.ui-dialog-content',
            '.ui-widget-content',
        ], $pluginPrefix);

        $dialogHeader = $this->scoped_selectors([
            '.ui-dialog-titlebar',
            '.ui-widget-header',
        ], $pluginPrefix);

        $dialogHeaderText = $this->scoped_selectors([
            '.ui-dialog-titlebar',
            '.ui-widget-header',
            '.ui-dialog-title',
        ], $pluginPrefix);

        $calendarSurface = $this->scoped_selectors([
            'body.task-calendar #layout-content #calendar',
            'body.task-calendar #layout-content #calendartoolbar',
            'body.task-calendar #layout-content .fc',
            'body.task-calendar #layout-content .fc-widget-header',
            'body.task-calendar #layout-content .fc-widget-content',
            'body.task-calendar #layout-content .fc-head-container',
            'body.task-calendar #layout-content .fc-body',
            'body.task-calendar #layout-content .fc-row',
            'body.task-calendar #layout-content .fc-day',
            'body.task-calendar #layout-content .fc-axis',
            'body.task-calendar #layout-content .fc-view',
            'body.task-calendar #layout-content .fc-toolbar',
            'body.task-calendar #layout-content .fc-list-view',
            'body.task-calendar #layout-content .fc-list-heading td',
            'body.task-calendar #layout-content .fc-list-item',
        ], $prefix);

        $calendarText = $this->scoped_selectors([
            'body.task-calendar #layout-content #calendar',
            'body.task-calendar #layout-content #calendartoolbar',
            'body.task-calendar #layout-content .fc',
            'body.task-calendar #layout-content .fc-widget-content',
            'body.task-calendar #layout-content .fc-widget-header',
            'body.task-calendar #layout-content .fc-day-number',
            'body.task-calendar #layout-content .fc-day-header',
            'body.task-calendar #layout-content .fc-center',
            'body.task-calendar #layout-content .fc-toolbar h2',
            'body.task-calendar #layout-content .fc-list-item',
            'body.task-calendar #layout-content .fc-list-heading td',
        ], $prefix);

        $calendarButtons = $this->scoped_selectors([
            'body.task-calendar #layout-content .fc-button',
            'body.task-calendar #layout-content .fc-toolbar .btn',
            'body.task-calendar #layout-content #calendar .btn',
        ], $prefix);

        $calendarSelection = $this->scoped_selectors([
            'body.task-calendar #layout-content .fc-day-today',
            'body.task-calendar #layout-content .fc-state-highlight',
            'body.task-calendar #layout-content .fc-state-active',
            'body.task-calendar #layout-content .fc-button.fc-state-active',
            'body.task-calendar #layout-content .fc-list-item.fc-selected',
        ], $prefix);

        $calendarSelText = $this->scoped_selectors([
            'body.task-calendar #layout-content .fc-state-active',
            'body.task-calendar #layout-content .fc-button.fc-state-active',
        ], $prefix);

        $calendarSidebar = $this->scoped_selectors([
            '#layout-sidebar #calendarslist li.selected',
            '#layout-sidebar #calendarslist li.selected>a',
            '#layout-sidebar #calendarslist li.selected .calname',
            '#layout-sidebar .listing li.selected',
            '#layout-sidebar .listing li.selected>a',
            '#layout-menu.canga-menu #layout-sidebar #calendarslist li.selected',
            '#layout-menu.canga-menu #layout-sidebar #calendarslist li.selected>a',
            '#layout-menu.canga-menu #layout-sidebar #calendarslist li.selected .calname',
        ], $prefix);

        $inboxSurfaces = $this->scoped_selectors([
            'body.task-mail #layout-list',
            'body.task-mail #messagelist-content',
            'body.task-mail #messagelist',
            'body.task-mail #messagelist-header',
            'body.task-mail .canga-inbox-tabs',
            'body.task-mail .pagenav',
            'body.task-mail .searchbar',
        ], $prefix);

        $inboxText = $this->scoped_selectors([
            'body.task-mail #messagelist',
            'body.task-mail #messagelist tbody tr td',
            'body.task-mail #messagelist .fromto',
            'body.task-mail #messagelist .subject',
            'body.task-mail #messagelist .date',
            'body.task-mail #messagelist-header',
            'body.task-mail #messagelist-header .header-title',
            'body.task-mail .canga-inbox-tabs',
            'body.task-mail .pagenav',
            'body.task-mail .searchbar',
        ], $prefix);

        $inboxRows = $this->scoped_selectors([
            'body.task-mail #messagelist tbody tr td',
        ], $prefix);

        $inboxHover = $this->scoped_selectors([
            'body.task-mail #messagelist tbody tr:hover td',
        ], $prefix);

        $inboxSelected = $this->scoped_selectors([
            'body.task-mail #messagelist tbody tr.selected td',
            'body.task-mail #messagelist tbody tr.focused td',
            'body.task-mail #messagelist tbody tr.selected:hover td',
            'body.task-mail #messagelist tbody tr.selected td a',
            'body.task-mail #messagelist tbody tr.focused td a',
            'body.task-mail #messagelist tbody tr.selected:hover td a',
        ], $prefix);

        $inboxUnread = $this->scoped_selectors([
            'body.task-mail #messagelist tbody tr.unread td.subject',
            'body.task-mail #messagelist tbody tr.unread td.subject a',
        ], $prefix);

        $inboxTabsActive = $this->scoped_selectors([
            'body.task-mail .canga-inbox-tabs > a.active',
            'body.task-mail .canga-inbox-tabs > a:not(.canga-mobile-menu).active',
        ], $prefix);

        $inboxTabCount = $this->scoped_selectors([
            'body.task-mail .canga-inbox-tab-count',
        ], $prefix);

        $inboxSearchInput = $this->scoped_selectors([
            'body.task-mail .searchbar input',
        ], $prefix);

        $inboxToolbarButton = $this->scoped_selectors([
            'body.task-mail #messagelist-header .toolbar-button',
            'body.task-mail #messagelist-header .toolbar.menu a',
            'body.task-mail #messagelist-header .toolbar-button:before',
            'body.task-mail #messagelist-header .toolbar.menu a:before',
        ], $prefix);

        $inboxToolbarButtonHover = $this->scoped_selectors([
            'body.task-mail #messagelist-header .toolbar-button:hover',
            'body.task-mail #messagelist-header .toolbar.menu a:hover',
            'body.task-mail #messagelist-header .toolbar-button:hover:before',
            'body.task-mail #messagelist-header .toolbar.menu a:hover:before',
        ], $prefix);

        $twofactorCard = $this->scoped_selectors(['.twofactor-2fa'], $prefix);
        $twofactorText = $this->scoped_selectors([
            '.twofactor-2fa',
            '.twofactor-2fa-title h3',
            '.twofactor-2fa-user',
            '.twofactor-2fa-lead',
            '.twofactor-2fa-label',
            '.twofactor-2fa-hint',
        ], $prefix);
        $twofactorDivider = $this->scoped_selectors(['.twofactor-2fa-head'], $prefix);
        $twofactorInput = $this->scoped_selectors(['.twofactor-2fa-input'], $prefix);
        $twofactorPrimary = $this->scoped_selectors(['.twofactor-2fa-btn.is-primary'], $prefix);
        $twofactorActive = $this->scoped_selectors(['.twofactor-2fa.is-active .twofactor-2fa-badge'], $prefix);

        return $containers . '{background:' . $colors['background'] . ' !important;'
            . 'color:' . $colors['text'] . ' !important;}'
            . $containerText . '{color:' . $colors['text'] . ' !important;}'
            . $dialogHeader . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $colors['text'] . ' !important;}'
            . $dialogHeaderText . '{color:' . $colors['text'] . ' !important;}'
            . $calendarSurface . '{background:' . $colors['background'] . ' !important;'
            . 'color:' . $colors['text'] . ' !important;}'
            . $calendarText . '{color:' . $colors['text'] . ' !important;}'
            . $calendarButtons . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $colors['text'] . ' !important;'
            . 'border-color:rgba(128,128,128,.35) !important;}'
            . $calendarSelection . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $colors['text'] . ' !important;}'
            . $calendarSelText . '{color:' . $selectionText . ' !important;}'
            . $calendarSidebar . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $colors['text'] . ' !important;}'
            . $twofactorCard . '{background:' . $colors['background'] . ' !important;'
            . 'border-color:rgba(128,128,128,.35) !important;'
            . 'color:' . $colors['text'] . ' !important;}'
            . $twofactorText . '{color:' . $colors['text'] . ' !important;}'
            . $twofactorDivider . '{border-bottom-color:rgba(128,128,128,.3) !important;}'
            . $twofactorInput . '{background:' . $colors['background'] . ' !important;'
            . 'border-color:rgba(128,128,128,.4) !important;'
            . 'color:' . $colors['text'] . ' !important;}'
            . $twofactorPrimary . '{background:' . $colors['accent'] . ' !important;'
            . 'color:' . $accentText . ' !important;}'
            . $twofactorActive . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $selectionText . ' !important;}'
            . $inboxSurfaces . '{background:' . $colors['background'] . ' !important;'
            . 'color:' . $colors['text'] . ' !important;}'
            . $inboxText . '{color:' . $colors['text'] . ' !important;}'
            . $inboxRows . '{background:' . $colors['background'] . ' !important;'
            . 'border-color:rgba(128,128,128,.22) !important;'
            . 'color:' . $colors['text'] . ' !important;}'
            . $inboxHover . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $selectionText . ' !important;}'
            . $inboxSelected . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $selectionText . ' !important;}'
            . $inboxUnread . '{color:' . $colors['text'] . ' !important;font-weight:600 !important;}'
            . $inboxTabsActive . '{color:' . $colors['accent'] . ' !important;'
            . 'border-bottom-color:' . $colors['accent'] . ' !important;}'
            . $inboxTabCount . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $selectionText . ' !important;}'
            . $inboxSearchInput . '{background:' . $colors['background'] . ' !important;'
            . 'border-color:rgba(128,128,128,.35) !important;'
            . 'color:' . $colors['text'] . ' !important;}'
            . $inboxToolbarButton . '{color:' . $colors['text'] . ' !important;}'
            . $inboxToolbarButtonHover . '{background:' . $colors['selection'] . ' !important;'
            . 'color:' . $selectionText . ' !important;}';
    }

    /**
     * Prefix a list of selectors.
     */
    private function scoped_selectors(array $selectors, string $prefix): string
    {
        if ($prefix === '') {
            return implode(',', $selectors);
        }

        return implode(',', array_map(static fn($selector) => $prefix . ' ' . $selector, $selectors));
    }

    /**
     * Pick a readable foreground for a background color.
     */
    private function contrast_color(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) !== 6) {
            return '#ffffff';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.6 ? '#161b1d' : '#ffffff';
    }

    /**
     * Wrap a preview with its mode label.
     */
    private function preview_block(array $colors, string $mode, string $skin): string
    {
        $label = $this->gettext($mode === 'dark' ? 'darkmode' : 'lightmode');

        return '<div class="user-preview-card">'
            . '<span class="user-preview-mode">' . rcube::Q($label) . '</span>'
            . $this->preview_html($colors, $mode, $skin)
            . '</div>';
    }

    /**
     * Build one live preview.
     */
    private function preview_html(array $colors, string $mode, string $skin): string
    {
        $menuText = $this->contrast_color($colors['menu']);
        $accentText = $this->contrast_color($colors['accent']);
        $style = '--preview-accent:' . $colors['accent'] . ';'
            . '--preview-menu:' . $colors['menu'] . ';'
            . '--preview-menu-text:' . $menuText . ';'
            . '--preview-background:' . $colors['background'] . ';'
            . '--preview-text:' . $colors['text'] . ';'
            . '--preview-selection:' . $colors['selection'] . ';'
            . '--preview-accent-text:' . $accentText . ';';

        $out = '<div class="user-settings-preview" data-mode="' . rcube::Q($mode) . '"'
            . ' data-skin="' . rcube::Q($skin) . '" style="' . rcube::Q($style) . '">';
        $out .= '<div class="user-preview-top">';
        $out .= '<span class="user-preview-logo"></span>';
        $out .= '<span class="user-preview-menu-item">' . rcube::Q($this->gettext('previewmail')) . '</span>';
        $out .= '<span class="user-preview-menu-item is-selected">' . rcube::Q($this->gettext('previewcontacts')) . '</span>';
        $out .= '<span class="user-preview-menu-item">' . rcube::Q($this->gettext('previewsettings')) . '</span>';
        $out .= '</div>';
        $out .= '<div class="user-preview-body">';
        $out .= '<div class="user-preview-sidebar">';
        $out .= '<span class="user-preview-list-item is-selected">' . rcube::Q($this->gettext('previewinbox')) . '</span>';
        $out .= '<span class="user-preview-list-item">' . rcube::Q($this->gettext('previewsent')) . '</span>';
        $out .= '</div>';
        $out .= '<div class="user-preview-content">';
        $out .= '<h4>' . rcube::Q($this->gettext('previewtitle')) . '</h4>';
        $out .= '<p>' . rcube::Q($this->gettext('previewtext')) . '</p>';
        $out .= '<button class="user-preview-button" type="button">' . rcube::Q($this->gettext('previewbutton')) . '</button>';
        $out .= '</div>';
        $out .= '</div>';
        $out .= '</div>';

        return $out;
    }

    /**
     * Store a one-shot status message.
     */
    private function flash(string $message, string $type = 'confirmation'): void
    {
        $_SESSION['user_plugin_flash'] = [
            'message' => $message,
            'type' => $type,
        ];
    }

    /**
     * Read and clear the one-shot status message.
     */
    private function consume_flash(): ?array
    {
        $flash = $_SESSION['user_plugin_flash'] ?? null;
        unset($_SESSION['user_plugin_flash']);

        return is_array($flash) && isset($flash['message']) ? $flash : null;
    }
}
