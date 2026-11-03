<?php
// Labels used for different portions of the plugin
$labels = array();
$labels['activate'] = 'Activate';
$labels['twofactor_gauthenticator'] = '2-Factor Authentication';
$labels['code'] = 'Authentication Code';

$labels['two_step_verification_form'] = '2-Factor Authentication Code:';

$labels['secret'] = 'Secret';
$labels['qr_code'] = 'QR Code';
$labels['msg_infor'] = 'You can scan this QR code containing the 2-Factor settings using a TOTP compatible app such as <a href="https://github.com/Skyost/OpenAuthenticator" target="_blank">OpenAuthenticator</a> (<a href="https://play.google.com/store/apps/details?id=app.openauthenticator" target="_blank">Play Store</a> | <a href="https://apps.apple.com/us/app/open-authenticator-by-skyost/id6479272927" target="_blank">Istore</a>) or <a href="https://github.com/google/google-authenticator" target="_blank">google-authenticator</a>';

$labels['msg_help'] = '<a href="https://github.com/alexandregz/twofactor_gauthenticator/tree/master?tab=readme-ov-file#usage" target="_blank">User manual</a>';

$labels['show_secret'] = 'Show secret';
$labels['hide_secret'] = 'Hide secret';
$labels['create_secret'] = 'Create secret';

$labels['show_qr_code'] = 'Show QR Code';
$labels['hide_qr_code'] = 'Hide QR Code';

$labels['recovery_codes'] = 'Recovery codes';
$labels['show_recovery_codes'] = 'Show recovery codes';
$labels['hide_recovery_codes'] = 'Hide recovery codes';

$labels['setup_all_fields'] = 'Fill all fields (make sure you click save to store your settings)';

$labels['enrollment_dialog_title'] = '2-Factor authentication enrollment';
$labels['enrollment_dialog_msg'] = '<strong>2-Factor authentication codes</strong> are required for increased security, please configure them now.';

$labels['check_code'] = 'Check code';
$labels['code_ok'] = 'Code OK';
$labels['code_ko'] = 'Incorrect code';

$labels['dont_ask_me_30days'] = 'Don&#39;t ask me codes again on this computer for 30 days';

$labels['check_code_to_activate'] = 'To save, please scan the QR Code and enter the current 2-Factor code below.';

$labels['status_on'] = 'Active';
$labels['status_off'] = 'Inactive';
$labels['activate_lead'] = 'Scan the QR code below with your authenticator app, then enter the 6-digit code to enable two-step verification.';
$labels['activate_hint'] = 'The code changes every 30 seconds.';
$labels['activate_button'] = 'Enable 2FA';
$labels['disable_lead'] = 'Two-step verification is enabled for this account. To disable it, enter the current code from your authenticator app.';
$labels['disable_button'] = 'Disable 2FA';
$labels['code_hint'] = 'Enter the 6-digit code shown in your authenticator app.';
$labels['qr_aria'] = 'QR code to set up the authenticator app';
$labels['forced_enrollment_notice'] = 'Two-step verification is required for your account. Scan the QR code and enable it to continue.';
$labels['forced_enrollment_hint'] = 'Your administrator requires two-step verification, so it cannot be disabled.';
$labels['successfully_activated'] = 'Two-step verification has been enabled.';
$labels['successfully_disabled'] = 'Two-step verification has been disabled.';
$labels['error_invalid_code'] = 'Invalid code. Check your authenticator app and try again.';
$labels['error_too_many_attempts'] = 'Too many attempts. Please wait a moment and try again.';
$labels['error_pending_expired'] = 'The setup expired. Scan the new QR code and try again.';
$labels['error_already_active'] = 'Two-step verification is already enabled.';
$labels['error_not_active'] = 'Two-step verification is not enabled.';
$labels['error_forced_enrollment'] = 'Two-step verification is required and cannot be disabled.';
$labels['error_csrf'] = 'The request could not be verified. Please try again.';
$labels['error_invalid_action'] = 'Invalid action.';
$labels['error_not_allowed'] = 'Two-step verification is not available for this account.';
$labels['error_saving'] = 'Could not save the settings. Please try again.';

// Messages used for the different portions of the plugin
$messages = array();
$messages['successfully_saved'] = '2-Factor authentication settings saved successfully.';

