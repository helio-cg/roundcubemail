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
    public const VERSION = '1.2.2';
    public const SCHEMA_VERSION = 2026110300;

    private const TABLE = 'user_settings';

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
        $this->add_hook('template_container', [$this, 'template_container']);

        $this->register_action('plugin.user', [$this, 'settings_page']);
        $this->register_action('plugin.user-save', [$this, 'save_settings']);
        $this->register_handler('plugin.userdisplayname', [$this, 'userdisplayname']);
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
     * Add a direct "Preferences" link to the main task bar.
     */
    public function template_container($args)
    {
        $rcmail = rcmail::get_instance();

        if (($args['name'] ?? '') !== 'taskbar' || !$rcmail->user->ID) {
            return $args;
        }

        $this->add_texts('localization/', false);
        $url = $rcmail->url(['_task' => 'settings', '_action' => 'plugin.user']);
        $label = rcube::Q($this->gettext('preferences'));

        $args['content'] .= '<a class="user-preferences" href="' . rcube::Q($url) . '"'
            . ' role="button" title="' . $label . '"><span class="inner">' . $label . '</span></a>';

        return $args;
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
        $skin = (string) $rcmail->config->get('skin', 'elastic');
        $hasTable = $this->table_exists($rcmail->get_dbh());
        $presets = $this->presets();

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

        $out .= '<div class="user-settings-field">';
        $out .= '<label for="user-display-name">' . rcube::Q($this->gettext('displayname')) . '</label>';
        $out .= '<input id="user-display-name" name="_display_name" type="text" maxlength="255"'
            . ' autocomplete="name" placeholder="' . rcube::Q($this->gettext('defaultdisplayname')) . '"'
            . ' value="' . rcube::Q($settings['display_name']) . '"'
            . ($hasTable ? '' : ' disabled') . '>';
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

            $out .= $this->palette_fieldset('light', $this->gettext('lightmode'), $colors['light'], $hasTable);
            $out .= $this->palette_fieldset('dark', $this->gettext('darkmode'), $colors['dark'], $hasTable);
        }

        $out .= '<div class="user-settings-actions">';
        $out .= '<button class="button mainaction" type="submit"' . ($hasTable ? '' : ' disabled') . '>'
            . rcube::Q($this->gettext('save')) . '</button>';
        $out .= '<button class="button" type="submit" name="_reset" value="1"' . ($hasTable ? '' : ' disabled') . '>'
            . rcube::Q($this->gettext('reset')) . '</button>';
        $out .= '</div>';
        $out .= '</form>';

        if ($this->skin_colors_enabled()) {
            $out .= '<div class="user-preview-grid">';
            $out .= $this->preview_block($colors['light'], 'light', $skin);
            $out .= $this->preview_block($colors['dark'], 'dark', $skin);
            $out .= '</div>';
        }

        $out .= '</div>';

        return '<div class="formcontainer"><div class="formcontent">' . $out . '</div></div>';
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

        $redirect = ['_task' => 'settings', '_action' => 'plugin.user'];

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

        if ($db->is_error()) {
            $this->flash($this->gettext('errorsave'), 'error');
        } else {
            $this->flash($this->gettext('saved'), 'confirmation');
        }

        $rcmail->output->redirect($redirect);
        exit;
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
                $this->settings['colors'] = $decoded['colors'];
                $this->settings['preset'] = $decoded['preset'];
            }
        }

        return $this->settings;
    }

    /**
     * Default values for the current skin.
     */
    private function default_settings(): array
    {
        $rcmail = rcmail::get_instance();
        $skin = (string) $rcmail->config->get('skin', 'elastic');
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
        $rcmail = rcmail::get_instance();
        $skin = (string) $rcmail->config->get('skin', 'elastic');
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
            'a',
            '#layout-content a',
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
            . $dialogHeader . '{background:' . $colors['menu'] . ' !important;'
            . 'color:' . $menuText . ' !important;}'
            . $dialogHeaderText . '{color:' . $menuText . ' !important;}'
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
