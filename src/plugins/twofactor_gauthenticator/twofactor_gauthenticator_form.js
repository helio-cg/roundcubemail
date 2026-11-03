/**
 * Second step of the login: replace the password form with the 6-digit code field.
 * The original request token stays in the form, so the POST remains CSRF protected.
 */
if (window.rcmail) {
    rcmail.addEventListener('init', function () {
        // Remove the username/password rows, keep hidden fields (including _token).
        $('form > table > tbody > tr').each(function () {
            $(this).remove();
        });

        $('form').attr('action', './');
        $('input[name=_task]').attr('value', 'mail');
        $('input[name=_action]').attr('value', '');

        var fieldType = rcmail.env.twofactor_formfield_as_password ? 'password' : 'text';
        var html = '';

        html += '<tr class="form-group row twofactor-login-row">';
        html += '<td class="input input-group input-group-lg">';
        html += '<span class="input-group-prepend"><i class="input-group-text icon key"></i></span>';
        html += '<input name="_code_2FA" id="2FA_code" size="40" maxlength="10" class="form-control"';
        html += ' autocapitalize="off" autocomplete="one-time-code" spellcheck="false"';
        html += ' type="' + fieldType + '" placeholder="' + rcmail.gettext('code', 'twofactor_gauthenticator') + '">';
        html += '</td>';
        html += '</tr>';

        if (rcmail.env.allow_save_device_30days) {
            html += '<tr>';
            html += '<td class="title" colspan="2">';
            html += '<input type="checkbox" id="remember_2FA" name="_remember_2FA" value="yes">';
            html += '<label for="remember_2FA">' + rcmail.gettext('dont_ask_me_30days', 'twofactor_gauthenticator') + '</label>';
            html += '</td>';
            html += '</tr>';
        }

        $('form > table > tbody:last').append(html);
        $('#2FA_code').focus();
    });
}
