<?php

/**
 * Two-factor Google Authenticator for Roundcube
 *
 * Modernized, minimal 2FA enrollment:
 *   - scan a QR code and confirm a 6-digit code to enable;
 *   - enter the current code to disable.
 *
 * The login-time verification, rate-limiting, trusted-proxy handling and
 * remember-me cookie logic are kept from the hardened upstream plugin.
 *
 * @author Alexandre Espinosa <aemenor@gmail.com>
 * @author Hélio CG.
 * @license BSD
 */
require_once 'PHPGangsta/GoogleAuthenticator.php';
require_once 'CIDR.php';

class twofactor_gauthenticator extends rcube_plugin
{
    /** How long a pending enrollment secret stays valid (seconds). */
    private const PENDING_TTL = 900;

    /** Settings-page throttle window and delays (seconds). */
    private const SETTINGS_THROTTLE_WINDOW = 900;
    private const SETTINGS_THROTTLE_DELAYS = [1, 2, 5, 20, 60];

    public function init()
    {
        $rcmail = rcmail::get_instance();

        $this->load_config();

        // Completely block AJAX requests for unauthenticated users
        if (!$rcmail->user->ID && !isset($_SESSION['twofactor_gauthenticator_login']) && isset($_REQUEST['_remote'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Session expired or invalid',
                'redirect' => '?_task=login&_err=session',
            ]);
            exit;
        }

        // Block data access via AJAX for partially authenticated users who have 2FA enabled
        if (isset($_SESSION['twofactor_gauthenticator_login'])
            && !$this->__is2FAFresh()
            && isset($_REQUEST['_remote'])
            && $rcmail->task !== 'login'
            && $this->__pluginAllowedByConfig()
        ) {
            $tfa = $this->__get2FAconfig();

            if (!empty($tfa['activate'])) {
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => '2FA authentication required',
                    'redirect' => '?_task=login&_err=session',
                ]);
                exit;
            }
        }

        // Hooks must be registered before the user is known (login request).
        $this->add_hook('login_after', [$this, 'login_after']);
        $this->add_hook('send_page', [$this, 'check_2FAlogin']);

        $allowed = $this->__pluginAllowedByConfig();

        $this->add_texts('localization/', true);

        // Always register the settings actions so a stale/bookmarked URL never
        // produces Roundcube's "No handler found" error page.
        $this->register_action('twofactor_gauthenticator', [$this, 'twofactor_gauthenticator_init']);
        $this->register_action('plugin.twofactor_gauthenticator-save', [$this, 'twofactor_gauthenticator_save']);

        if ($allowed) {
            $this->include_script('twofactor_gauthenticator.js');
        }
    }

    /**
     * Check whether the current user may use the plugin.
     * Patterns are treated as regular expressions, matching upstream behaviour.
     */
    public function __pluginAllowedByConfig(): bool
    {
        $rcmail = rcmail::get_instance();
        $this->load_config();

        $users = $rcmail->config->get('users_allowed_2FA');

        if (is_array($users)) {
            $username = $rcmail->user->data['username'] ?? '';

            foreach ($users as $pattern) {
                if (!is_string($pattern) || $pattern === '') {
                    continue;
                }

                $regex = '/' . str_replace('/', '\\/', $pattern) . '/';

                if (@preg_match($regex, $username)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * After a successful password login, ask for the 2FA code.
     */
    public function login_after($args)
    {
        $_SESSION['twofactor_gauthenticator_login'] = time();

        $rcmail = rcmail::get_instance();
        $config = $this->__get2FAconfig();

        if (empty($config['activate'])) {
            if ($rcmail->config->get('force_enrollment_users')) {
                $this->__goingRoundcubeTask('settings', 'plugin.twofactor_gauthenticator');
            }

            return;
        }

        if ($this->__cookie(false) || !$this->__pluginAllowedByConfig()) {
            // Mark this login as fresh so the session can proceed.
            $_SESSION['twofactor_gauthenticator_login'] -= 1;
            $this->__goingRoundcubeTask('mail');

            return;
        }

        $rcmail->output->set_pagetitle($this->gettext('twofactor_gauthenticator'));
        $rcmail->output->set_env('allow_save_device_30days', $rcmail->config->get('allow_save_device_30days', true));
        $rcmail->output->set_env('twofactor_formfield_as_password', $rcmail->config->get('twofactor_formfield_as_password', false));

        $this->add_texts('localization', true);
        $this->include_script('twofactor_gauthenticator_form.js');

        $rcmail->output->send('login');
    }

    /**
     * Gate every page render until the second factor has been provided.
     */
    public function check_2FAlogin($p)
    {
        $rcmail = rcmail::get_instance();
        $config = $this->__get2FAconfig();

        if (!empty($config['activate'])) {
            // Users no longer allowed by config must not be locked out by a stale secret.
            if (!$this->__pluginAllowedByConfig()) {
                return $p;
            }

            // IP whitelist bypass (explicit, documented configuration).
            if ($rcmail->config->get('whitelist')) {
                $realip = $this->__getClientIP();

                foreach ($rcmail->config->get('whitelist') as $ip_to_check) {
                    if (CIDR::match($realip, $ip_to_check)) {
                        if (isset($_SESSION['twofactor_gauthenticator_login'])) {
                            if ($rcmail->task === 'login') {
                                $this->__goingRoundcubeTask('mail');
                            }

                            return $p;
                        }
                    }
                }
            }

            $code = rcube_utils::get_input_value('_code_2FA', rcube_utils::INPUT_POST);
            $remember = rcube_utils::get_input_value('_remember_2FA', rcube_utils::INPUT_POST);

            if ($code !== null && $code !== '') {
                // The login form carries Roundcube's request token. Reject cross-site posts.
                if (!$rcmail->check_request()) {
                    $this->__exitSession();
                }

                if ($this->__isLockedOut()) {
                    $this->__waitSeconds($this->__blockedRemaining());
                    $this->__exitSession();
                }

                $normalized = $this->__normalizeCode($code);
                $slice = $normalized !== null ? $this->__verifyCode($normalized) : null;
                $totp_ok = $slice !== null && !$this->__isReplay($slice);
                $recovery_ok = $this->__isRecoveryCode($code);

                if ($totp_ok || $recovery_ok) {
                    $this->__clearFailedAttempts();

                    if ($totp_ok) {
                        $this->__rememberSlice($slice);
                    }

                    if ($recovery_ok) {
                        $this->__consumeRecoveryCode($code);
                    }

                    if ($remember === 'yes') {
                        $this->__cookie(true);
                    }

                    $this->__goingRoundcubeTask('mail');
                }

                if ($rcmail->config->get('enable_fail_logs')) {
                    $this->__logError();
                }

                $wait = $this->__registerFailedAttempt();
                $this->__waitSeconds($wait);
                $this->__exitSession();
            } elseif ($rcmail->task !== 'login' && !$this->__is2FAFresh()) {
                $this->__exitSession();
            }
        } elseif ($rcmail->config->get('force_enrollment_users')
            && ($rcmail->task !== 'settings' || $rcmail->action !== 'plugin.twofactor_gauthenticator')
        ) {
            if ($rcmail->task !== 'login') {
                $this->__goingRoundcubeTask('settings', 'plugin.twofactor_gauthenticator');
            }
        }

        return $p;
    }

    /**
     * Render the settings page.
     */
    public function twofactor_gauthenticator_init()
    {
        $rcmail = rcmail::get_instance();

        $this->add_texts('localization/', true);

        if (!$this->__pluginAllowedByConfig()) {
            $flash = $this->__consumeFlash();
            if ($flash) {
                $rcmail->output->show_message($flash['message'], $flash['type']);
            }

            $this->register_handler('plugin.body', [$this, 'twofactor_gauthenticator_denied']);
            $this->include_stylesheet('twofactor_gauthenticator.css');
            $rcmail->output->set_pagetitle($this->gettext('twofactor_gauthenticator'));
            $rcmail->output->send('plugin');
        }

        $this->register_handler('plugin.body', [$this, 'twofactor_gauthenticator_form']);
        $this->include_stylesheet('twofactor_gauthenticator.css');
        $this->include_script('qrcode.min.js');
        $this->include_script('twofactor_gauthenticator_settings.js');

        $flash = $this->__consumeFlash();
        if ($flash) {
            // Keep the same QR across a failed attempt, even if the session write from
            // the previous request was lost.
            if (!empty($flash['secret']) && preg_match('/^[A-Z2-7]{16,64}$/', $flash['secret'])) {
                $_SESSION['twofactor_gauthenticator_pending'] = [
                    'secret' => $flash['secret'],
                    'time' => time(),
                ];
            }

            $rcmail->output->show_message($flash['message'], $flash['type']);
        }

        // Create/reuse the enrollment secret before rendering, so the session write
        // happens while the request is still writable.
        $config = $this->__get2FAconfig();
        if (empty($config['activate'])) {
            $this->__getPendingSecret(true);
        }

        $rcmail->output->set_pagetitle($this->gettext('twofactor_gauthenticator'));
        $rcmail->output->send('plugin');
    }

    /**
     * Shown when the current account is not allowed to use the plugin.
     */
    public function twofactor_gauthenticator_denied()
    {
        $this->add_texts('localization/', true);

        return '<div class="formcontainer"><div class="formcontent"><div class="twofactor-2fa is-inactive">'
            . '<div class="twofactor-2fa-head"><div class="twofactor-2fa-title"><h3>'
            . rcube::Q($this->gettext('twofactor_gauthenticator'))
            . '</h3></div><span class="twofactor-2fa-badge">'
            . rcube::Q($this->gettext('status_off'))
            . '</span></div><p class="twofactor-2fa-lead">'
            . $this->gettext('error_not_allowed')
            . '</p></div></div></div>';
    }

    /**
     * Handle activation and deactivation (POST + CSRF token + PRG).
     */
    public function twofactor_gauthenticator_save()
    {
        $rcmail = rcmail::get_instance();

        if (!$rcmail->user->ID) {
            header('Location: ?_task=login');
            exit;
        }

        if (!$rcmail->check_request()) {
            $this->__flash($this->gettext('error_csrf'), 'error');
            $this->__redirectToSettings();
        }

        $this->add_texts('localization/', true);

        $redirect = ['_task' => 'settings', '_action' => 'plugin.twofactor_gauthenticator'];

        if (!$this->__pluginAllowedByConfig()) {
            $this->__flash($this->gettext('error_not_allowed'), 'error');
            $rcmail->output->redirect($redirect);
        }

        $action = (string) rcube_utils::get_input_value('_2fa_action', rcube_utils::INPUT_POST);
        $code = (string) rcube_utils::get_input_value('_2fa_code', rcube_utils::INPUT_POST);
        $config = $this->__get2FAconfig();
        $active = !empty($config['activate']) && !empty($config['secret']);
        $forced = (bool) $rcmail->config->get('force_enrollment_users');

        if ($action === 'activate') {
            if ($active) {
                $this->__flash($this->gettext('error_already_active'), 'error');
                $rcmail->output->redirect($redirect);
            }

            if ($this->__settingsThrottleRemaining() > 0) {
                $this->__flash($this->gettext('error_too_many_attempts'), 'error');
                $rcmail->output->redirect($redirect);
            }

            $submitted = (string) rcube_utils::get_input_value('_2fa_secret', rcube_utils::INPUT_POST);
            $pending = $this->__getPendingSecret();
            $secret = $pending['secret'] ?? $submitted;

            if (!preg_match('/^[A-Z2-7]{16,64}$/', $secret)
                || ($pending && !hash_equals($pending['secret'], $submitted))
            ) {
                $this->__flash($this->gettext('error_pending_expired'), 'error');
                $rcmail->output->redirect($redirect);
            }

            $normalized = $this->__normalizeCode($code);

            if ($normalized === null || $this->__verifyCode($normalized, $secret) === null) {
                $this->__registerSettingsFailure();
                $this->__flash($this->gettext('error_invalid_code'), 'error', $secret);
                $rcmail->output->redirect($redirect);
            }

            $this->__clearSettingsFailures();

            $saved = $this->__set2FAconfig([
                'activate' => true,
                'secret' => $secret,
                'created' => time(),
                'last_slice' => 0,
            ]);

            if (!$saved) {
                $this->__flash($this->gettext('error_saving'), 'error', $secret);
                $rcmail->output->redirect($redirect);
            }

            $this->__clearPendingSecret();
            $this->__clearRememberMeCookie();
            $_SESSION['twofactor_gauthenticator_2FA_login'] = time();

            rcube::write_log('twofactor_gauthenticator', '2FA activated for ' . ($rcmail->user->data['username'] ?? 'unknown'));
            $this->__flash($this->gettext('successfully_activated'), 'confirmation');
            $rcmail->output->redirect($redirect);
        }

        if ($action === 'disable') {
            if (!$active) {
                $this->__flash($this->gettext('error_not_active'), 'error');
                $rcmail->output->redirect($redirect);
            }

            if ($forced) {
                $this->__flash($this->gettext('error_forced_enrollment'), 'error');
                $rcmail->output->redirect($redirect);
            }

            if (!$this->__is2FAFresh()) {
                $this->__exitSession();
            }

            if ($this->__settingsThrottleRemaining() > 0) {
                $this->__flash($this->gettext('error_too_many_attempts'), 'error');
                $rcmail->output->redirect($redirect);
            }

            $normalized = $this->__normalizeCode($code);

            if ($normalized === null || $this->__verifyCode($normalized) === null) {
                $this->__registerSettingsFailure();
                $this->__flash($this->gettext('error_invalid_code'), 'error');
                $rcmail->output->redirect($redirect);
            }

            $this->__clearSettingsFailures();

            if (!$this->__set2FAconfig([])) {
                $this->__flash($this->gettext('error_saving'), 'error');
                $rcmail->output->redirect($redirect);
            }

            $this->__clearPendingSecret();
            $this->__clearRememberMeCookie();

            rcube::write_log('twofactor_gauthenticator', '2FA disabled for ' . ($rcmail->user->data['username'] ?? 'unknown'));
            $this->__flash($this->gettext('successfully_disabled'), 'confirmation');
            $rcmail->output->redirect($redirect);
        }

        $this->__flash($this->gettext('error_invalid_action'), 'error');
        $rcmail->output->redirect($redirect);
    }

    /**
     * Build the simplified settings card.
     */
    public function twofactor_gauthenticator_form()
    {
        $rcmail = rcmail::get_instance();

        $this->add_texts('localization/', true);

        $config = $this->__get2FAconfig();
        $active = !empty($config['activate']) && !empty($config['secret']);
        $forced = (bool) $rcmail->config->get('force_enrollment_users');
        $username = $rcmail->user->data['username'] ?? '';

        $out = '<div class="twofactor-2fa ' . ($active ? 'is-active' : 'is-inactive') . '">';
        $out .= '<div class="twofactor-2fa-head">';
        $out .= '<div class="twofactor-2fa-title">';
        $out .= '<h3>' . rcube::Q($this->gettext('twofactor_gauthenticator')) . '</h3>';
        $out .= '<p class="twofactor-2fa-user">' . rcube::Q($username) . '</p>';
        $out .= '</div>';
        $out .= '<span class="twofactor-2fa-badge">' . rcube::Q($this->gettext($active ? 'status_on' : 'status_off')) . '</span>';
        $out .= '</div>';

        if ($forced && !$active) {
            $out .= '<p class="twofactor-2fa-notice">' . $this->gettext('forced_enrollment_notice') . '</p>';
        }

        if ($active) {
            $out .= '<p class="twofactor-2fa-lead">' . $this->gettext('disable_lead') . '</p>';
            $out .= $this->__settingsForm('disable', $this->gettext('disable_button'), 'danger', !$forced);

            if ($forced) {
                $out .= '<p class="twofactor-2fa-hint">' . $this->gettext('forced_enrollment_hint') . '</p>';
            }
        } else {
            $pending = $this->__getPendingSecret(true);
            $secret = $pending['secret'];
            $otpauth = $this->__buildOtpAuthUrl($secret, $username);

            $out .= '<p class="twofactor-2fa-lead">' . $this->gettext('activate_lead') . '</p>';
            $out .= html::tag('div', [
                'class' => 'twofactor-2fa-qr',
                'id' => 'twofactor-qr',
                'data-otpauth' => $otpauth,
                'role' => 'img',
                'aria-label' => $this->gettext('qr_aria'),
            ], '');
            $out .= '<p class="twofactor-2fa-hint">' . $this->gettext('activate_hint') . '</p>';
            $out .= $this->__settingsForm('activate', $this->gettext('activate_button'), 'primary', true, $secret);
        }

        $out .= '</div>';

        return "<div class='formcontainer'><div class='formcontent'>" . $out . '</div></div>';
    }

    /**
     * One 6-digit code form (activate or disable).
     */
    private function __settingsForm(string $action, string $label, string $style, bool $enabled, ?string $secret = null): string
    {
        $actionUrl = './?_task=settings&_action=plugin.twofactor_gauthenticator-save';
        $inputId = 'twofactor-code-' . $action;

        $out = '<form class="twofactor-2fa-form" method="post" action="' . rcube::Q($actionUrl) . '">';
        $out .= '<input type="hidden" name="_2fa_action" value="' . rcube::Q($action) . '">';

        if ($secret !== null) {
            $out .= '<input type="hidden" name="_2fa_secret" value="' . rcube::Q($secret) . '">';
        }

        $out .= '<label class="twofactor-2fa-label" for="' . rcube::Q($inputId) . '">' . rcube::Q($this->gettext('code')) . '</label>';
        $out .= '<div class="twofactor-2fa-row">';
        $out .= '<input class="twofactor-2fa-input" id="' . rcube::Q($inputId) . '" name="_2fa_code" type="text"'
            . ' inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" spellcheck="false"'
            . ' required' . ($enabled ? ' autofocus' : ' disabled') . '>';
        $out .= '<button class="twofactor-2fa-btn is-' . rcube::Q($style) . '" type="submit"'
            . ($enabled ? '' : ' disabled') . '>' . rcube::Q($label) . '</button>';
        $out .= '</div>';
        $out .= '<p class="twofactor-2fa-hint">' . $this->gettext('code_hint') . '</p>';
        $out .= '</form>';

        return $out;
    }

    private function __redirectToSettings(): void
    {
        $rcmail = rcmail::get_instance();
        $rcmail->output->redirect(['_task' => 'settings', '_action' => 'plugin.twofactor_gauthenticator']);
    }

    private function __flash(string $message, string $type = 'confirmation', ?string $secret = null): void
    {
        $_SESSION['twofactor_gauthenticator_flash'] = [
            'message' => $message,
            'type' => $type,
            'secret' => $secret,
        ];
    }

    private function __consumeFlash(): ?array
    {
        $flash = $_SESSION['twofactor_gauthenticator_flash'] ?? null;
        unset($_SESSION['twofactor_gauthenticator_flash']);

        return is_array($flash) && isset($flash['message']) ? $flash : null;
    }

    /**
     * Enrollment secret reused across page reloads/retries until it expires.
     * Generated from the init action so the session write happens before rendering.
     */
    private function __getPendingSecret(bool $generate = false): ?array
    {
        $pending = $_SESSION['twofactor_gauthenticator_pending'] ?? null;
        $now = time();

        if (is_array($pending)
            && !empty($pending['secret'])
            && ($now - (int) ($pending['time'] ?? 0)) <= self::PENDING_TTL
        ) {
            return $pending;
        }

        if (!$generate) {
            return null;
        }

        $pending = [
            'secret' => $this->__createSecret(),
            'time' => $now,
        ];

        $_SESSION['twofactor_gauthenticator_pending'] = $pending;

        return $pending;
    }

    private function __clearPendingSecret(): void
    {
        unset($_SESSION['twofactor_gauthenticator_pending']);
    }

    private function __buildOtpAuthUrl(string $secret, string $username): string
    {
        $rcmail = rcmail::get_instance();
        $issuer = (string) $rcmail->config->get('product_name', 'Roundcube');
        $issuer = $issuer !== '' ? $issuer : 'Roundcube';
        $label = rawurlencode($issuer) . ':' . rawurlencode($username);

        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    private function __normalizeCode(string $code): ?string
    {
        $code = preg_replace('/\s+/', '', $code);

        return is_string($code) && preg_match('/^\d{6}$/', $code) ? $code : null;
    }

    /**
     * Verify a TOTP code and return the matched time slice, or null.
     */
    private function __verifyCode(string $code, ?string $secret = null, int $discrepancy = 2): ?int
    {
        $secret = $secret ?? $this->__getSecret();

        if (!$secret) {
            return null;
        }

        $ga = new PHPGangsta_GoogleAuthenticator();
        $slice = (int) floor(time() / 30);

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            if (hash_equals($ga->getCode($secret, $slice + $i), $code)) {
                return $slice + $i;
            }
        }

        return null;
    }

    private function __isReplay(int $slice): bool
    {
        $config = $this->__get2FAconfig();

        return $slice <= (int) ($config['last_slice'] ?? 0);
    }

    private function __rememberSlice(int $slice): void
    {
        $config = $this->__get2FAconfig();

        if (empty($config['activate']) || empty($config['secret'])) {
            return;
        }

        $config['last_slice'] = $slice;

        if (!$this->__set2FAconfig($config)) {
            rcube::write_log('twofactor_gauthenticator', 'WARN: could not persist last 2FA slice');
        }
    }

    private function __settingsThrottleRemaining(): int
    {
        $state = $_SESSION['twofactor_gauthenticator_settings_throttle'] ?? null;

        if (!is_array($state)) {
            return 0;
        }

        if (($state['last'] ?? 0) > 0 && (time() - (int) $state['last']) > self::SETTINGS_THROTTLE_WINDOW) {
            return 0;
        }

        return max(0, (int) ($state['blocked_until'] ?? 0) - time());
    }

    private function __registerSettingsFailure(): int
    {
        $state = $_SESSION['twofactor_gauthenticator_settings_throttle'] ?? ['count' => 0, 'last' => 0, 'blocked_until' => 0];

        if (($state['last'] ?? 0) > 0 && (time() - (int) $state['last']) > self::SETTINGS_THROTTLE_WINDOW) {
            $state['count'] = 0;
        }

        $state['count'] = (int) ($state['count'] ?? 0) + 1;
        $state['last'] = time();

        $delays = self::SETTINGS_THROTTLE_DELAYS;
        $index = min($state['count'], count($delays)) - 1;
        $state['blocked_until'] = time() + max(1, (int) $delays[$index]);

        $_SESSION['twofactor_gauthenticator_settings_throttle'] = $state;

        return max(0, $state['blocked_until'] - time());
    }

    private function __clearSettingsFailures(): void
    {
        unset($_SESSION['twofactor_gauthenticator_settings_throttle']);
    }

    //------------- hardened login helpers (kept from upstream)

    /**
     * Returns true if $ip matches any entry in the trusted-proxy list.
     */
    private function __isTrustedProxy($ip, array $trusted): bool
    {
        foreach ($trusted as $entry) {
            if (CIDR::match($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the client IP for the whitelist decision and the rate-limit key.
     */
    private function __getClientIP(): string
    {
        $rcmail = rcmail::get_instance();
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $trusted = $rcmail->config->get('twofactor_trusted_proxies', []);

        if (is_array($trusted) && count($trusted) > 0 && $this->__isTrustedProxy($remote, $trusted)) {
            $xff = filter_input(INPUT_SERVER, 'HTTP_X_FORWARDED_FOR');

            if (is_string($xff) && $xff !== '') {
                $hops = array_map('trim', explode(',', $xff));

                for ($i = count($hops) - 1; $i >= 0; $i--) {
                    if ($hops[$i] === '') {
                        continue;
                    }

                    if (!$this->__isTrustedProxy($hops[$i], $trusted)) {
                        return $hops[$i];
                    }
                }
            }
        }

        return $remote;
    }

    /**
     * 2FA is complete only when the 2FA login marker is set and >= the password login marker.
     */
    private function __is2FAFresh(): bool
    {
        if (!isset($_SESSION['twofactor_gauthenticator_login'])) {
            return false;
        }

        if (!isset($_SESSION['twofactor_gauthenticator_2FA_login'])) {
            return false;
        }

        return $_SESSION['twofactor_gauthenticator_2FA_login'] >= $_SESSION['twofactor_gauthenticator_login'];
    }

    private function __clientFailureKey(): string
    {
        $rcmail = rcmail::get_instance();
        $ip = $this->__getClientIP();
        $username = $rcmail->user->data['username'] ?? '';

        return md5($ip . '|' . $username);
    }

    private function __failureDelay(int $level): int
    {
        $rcmail = rcmail::get_instance();
        $delays = $rcmail->config->get('twofactor_lockout_delays', [1, 2, 5, 20, 60, 300, 600]);

        if (!is_array($delays) || empty($delays)) {
            $delays = [1, 2, 5, 20, 60, 300, 600];
        }

        $idx = min($level, count($delays)) - 1;

        return max(1, (int) $delays[$idx]);
    }

    private function __failStatePath(string $key): ?string
    {
        $rcmail = rcmail::get_instance();
        $log_dir = (string) $rcmail->config->get('log_dir');

        if ($log_dir === '' || !is_dir($log_dir) || !is_writable($log_dir)) {
            return null;
        }

        return rtrim($log_dir, '/') . '/twofactor_gauth_fail_' . $key . '.json';
    }

    private function __readFailState(string $key): ?array
    {
        $path = $this->__failStatePath($key);

        if (!$path || !is_file($path)) {
            return null;
        }

        $data = @file_get_contents($path);
        $state = $data !== false ? json_decode($data, true) : null;

        return is_array($state) ? $state : null;
    }

    private function __writeFailState(string $key, array $state): void
    {
        $path = $this->__failStatePath($key);

        if ($path) {
            @file_put_contents($path, json_encode($state), LOCK_EX);
        }
    }

    private function __removeFailState(string $key): void
    {
        $path = $this->__failStatePath($key);

        if ($path && is_file($path)) {
            @unlink($path);
        }
    }

    private function __registerFailedAttempt(): int
    {
        $rcmail = rcmail::get_instance();
        $key = $this->__clientFailureKey();
        $lockout = (int) $rcmail->config->get('twofactor_lockout', 900);
        $now = time();

        $state = $this->__readFailState($key);
        $level = (int) ($state['level'] ?? 0);

        if (($state['last'] ?? 0) > 0 && ($now - $state['last']) > $lockout) {
            $level = 0;
        }

        $level++;
        $blocked_until = $now + $this->__failureDelay($level);

        $this->__writeFailState($key, [
            'level' => $level,
            'last' => $now,
            'blocked_until' => $blocked_until,
        ]);

        return max(0, $blocked_until - $now);
    }

    private function __isLockedOut(): bool
    {
        return $this->__blockedRemaining() > 0;
    }

    private function __blockedRemaining(): int
    {
        $rcmail = rcmail::get_instance();
        $state = $this->__readFailState($this->__clientFailureKey());

        if (!is_array($state)) {
            return 0;
        }

        if (($state['last'] ?? 0) > 0 && (time() - $state['last']) > (int) $rcmail->config->get('twofactor_lockout', 900)) {
            return 0;
        }

        return max(0, (int) ($state['blocked_until'] ?? 0) - time());
    }

    private function __waitSeconds(int $seconds): void
    {
        $seconds = min(max(0, $seconds), 600);

        if ($seconds > 0) {
            usleep($seconds * 1000000);
        }
    }

    private function __clearFailedAttempts(): void
    {
        $this->__removeFailState($this->__clientFailureKey());
    }

    private function __clearRememberMeCookie(): void
    {
        $rcmail = rcmail::get_instance();
        $username = $rcmail->user->data['username'] ?? null;

        if (!$username) {
            return;
        }

        $name = hash_hmac('md5', $username, $rcmail->config->get('des_key'));
        rcube_utils::setcookie($name, '-del-', time() - 60);
    }

    private function __goingRoundcubeTask($task = 'mail', $action = null)
    {
        $_SESSION['twofactor_gauthenticator_2FA_login'] = time();
        header('Location: ?_task=' . $task . ($action ? '&_action=' . $action : ''));
        exit;
    }

    private function __exitSession()
    {
        $rcmail = rcmail::get_instance();

        $this->__clearRememberMeCookie();
        $rcmail->kill_session();

        unset($_SESSION['twofactor_gauthenticator_login']);
        unset($_SESSION['twofactor_gauthenticator_2FA_login']);

        header('Location: ?_task=login');
        exit;
    }

    private function __get2FAconfig(): array
    {
        $rcmail = rcmail::get_instance();
        $arr_prefs = $rcmail->user->get_prefs();
        $data = $arr_prefs['twofactor_gauthenticator'] ?? [];

        if (is_string($data) && $data !== '' && $rcmail->config->get('twofactor_pref_encrypt')) {
            $plain = $rcmail->decrypt($data);
            $decoded = is_string($plain) && $plain !== '' ? json_decode($plain, true) : null;

            if (!is_array($decoded)) {
                rcube::write_log('twofactor_gauthenticator', 'WARN: Broken 2FA data, clearing...');
                $decoded = [];
            }

            $data = $decoded;
        }

        if (!is_array($data)) {
            return [];
        }

        if (isset($data['activate'])) {
            $data['activate'] = (bool) $data['activate'];
        }

        if (isset($data['secret']) && !is_string($data['secret'])) {
            unset($data['secret']);
        }

        if (isset($data['last_slice'])) {
            $data['last_slice'] = (int) $data['last_slice'];
        }

        if (isset($data['recovery_codes']) && !is_array($data['recovery_codes'])) {
            unset($data['recovery_codes']);
        }

        return $data;
    }

    private function __set2FAconfig(array $data): bool
    {
        $rcmail = rcmail::get_instance();
        $arr_prefs = $rcmail->user->get_prefs();

        if (empty($data['activate'])) {
            $data = [];
        }

        if ($data && $rcmail->config->get('twofactor_pref_encrypt')) {
            $json = json_encode($data);

            if ($json === false) {
                return false;
            }

            $encrypted = $rcmail->encrypt($json);

            if ($encrypted === null || $encrypted === false) {
                rcube::write_log('twofactor_gauthenticator', 'ERROR: could not encrypt 2FA data');
                return false;
            }

            $data = $encrypted;
        }

        $arr_prefs['twofactor_gauthenticator'] = $data;

        return (bool) $rcmail->user->save_prefs($arr_prefs);
    }

    private function __isRecoveryCode($code): bool
    {
        $config = $this->__get2FAconfig();
        $codes = $config['recovery_codes'] ?? [];

        return is_array($codes) && in_array($code, $codes, true);
    }

    private function __consumeRecoveryCode($code): void
    {
        $config = $this->__get2FAconfig();

        if (empty($config['recovery_codes']) || !is_array($config['recovery_codes'])) {
            return;
        }

        $config['recovery_codes'] = array_values(array_diff($config['recovery_codes'], [$code]));

        $this->__set2FAconfig($config);
    }

    /**
     * Generate a cryptographically secure base32 secret.
     */
    private function __createSecret(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $max = strlen($alphabet) - 1;
        $secret = '';

        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, $max)];
        }

        return $secret;
    }

    private function __getSecret(): ?string
    {
        $config = $this->__get2FAconfig();
        $secret = $config['secret'] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * Remember-me cookie, kept compatible with the upstream plugin.
     */
    private function __cookie($set = true)
    {
        $rcmail = rcmail::get_instance();
        $user_agent = hash_hmac('md5', filter_input(INPUT_SERVER, 'USER_AGENT') ?: "\0\0\0\0\0", $rcmail->config->get('des_key'));
        $key = hash_hmac('sha256', implode("\2\1\2", [$rcmail->user->data['username'], $this->__getSecret()]), $rcmail->config->get('des_key'), true);
        $iv = hash_hmac('md5', implode("\3\2\3", [$rcmail->user->data['username'], $this->__getSecret()]), $rcmail->config->get('des_key'), true);
        $name = hash_hmac('md5', $rcmail->user->data['username'], $rcmail->config->get('des_key'));

        if ($set) {
            $expires = time() + 2592000;
            $rand = random_int(1, PHP_INT_MAX);
            $signature = hash_hmac('sha512', implode("\1\0\1", [$rcmail->user->data['username'], $this->__getSecret(), $user_agent, $rand, $expires]), $rcmail->config->get('des_key'), true);
            $plain_content = sprintf('%d:%d:%s', $expires, $rand, $signature);
            $encrypted_content = openssl_encrypt($plain_content, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

            if ($encrypted_content !== false) {
                $b64_encrypted_content = strtr(base64_encode($encrypted_content), '+/=', '-_,');
                rcube_utils::setcookie($name, $b64_encrypted_content, $expires);

                return true;
            }

            return false;
        }

        $b64_encrypted_content = filter_input(INPUT_COOKIE, $name, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/[a-zA-Z0-9_-]+,{0,3}/']]);

        if (is_string($b64_encrypted_content) && !empty($b64_encrypted_content) && strlen($b64_encrypted_content) % 4 === 0) {
            $encrypted_content = base64_decode(strtr($b64_encrypted_content, '-_,', '+/='), true);

            if ($encrypted_content !== false) {
                $plain_content = openssl_decrypt($encrypted_content, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

                if ($plain_content !== false) {
                    $now = time();
                    [$expires, $rand, $signature] = explode(':', $plain_content, 3);

                    if ($expires > $now && ($expires - $now) <= 2592000) {
                        $signature_verification = hash_hmac('sha512', implode("\1\0\1", [$rcmail->user->data['username'], $this->__getSecret(), $user_agent, $rand, $expires]), $rcmail->config->get('des_key'), true);
                        $cmp = strlen($signature) ^ strlen($signature_verification);
                        $signature = $signature ^ $signature_verification;

                        for ($i = 0; $i < strlen($signature); $i++) {
                            $cmp += ord($signature[$i]);
                        }

                        return $cmp === 0;
                    }
                }
            }
        }

        return false;
    }

    private function __logError(): void
    {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        rcube::write_log('twofactor_gauthenticator', 'ERROR: 2FA fail - rip:' . $xff . ' lip:' . $remote);
    }
}
