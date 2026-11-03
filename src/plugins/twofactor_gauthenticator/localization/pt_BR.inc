<?php
// Labels used for different portions of the plugin
$labels = array();
$labels['activate'] = 'Ativar';
$labels['twofactor_gauthenticator'] = 'Verificação em Duas Etapas';
$labels['code'] = 'Código';

$labels['two_step_verification_form'] = 'Código da Verificação em Duas Etapas [2FA]:';

$labels['secret'] = 'Chave secreta';
$labels['qr_code'] = 'QR Code';
$labels['msg_infor'] = 'Escaneie o QR code abaixo com um aplicativo TOTP, como o Authy na sua versão <a href="https://github.com/Skyost/OpenAuthenticator" target="_blank">OpenAuthenticator</a> (<a href="https://play.google.com/store/apps/details?id=app.openauthenticator" target="_blank">Play Store</a> | <a href="https://apps.apple.com/us/app/open-authenticator-by-skyost/id6479272927" target="_blank">Istore</a>) ou <a href="https://play.google.com/store/apps/details?id=com.authy.authy" target="_blank">Android - Play Store</a> ou  em sua versão <a href="https://itunes.apple.com/br/app/authy/id494168017" target="_blank">Apple iOS - AppStore</a>.';

$labels['msg_help'] = '<a href="https://github.com/alexandregz/twofactor_gauthenticator/tree/master?tab=readme-ov-file#usage" target="_blank">Manual do usuário</a>';

$labels['show_secret'] = 'Mostrar chave secreta';
$labels['hide_secret'] = 'Esconder chave secreta';
$labels['create_secret'] = 'Criar chave secreta';

$labels['show_qr_code'] = 'Mostrar QR Code';
$labels['hide_qr_code'] = 'Esconder QR Code';

$labels['recovery_codes'] = 'Códigos de Recuperação';
$labels['show_recovery_codes'] = 'Mostrar códigos de recuperação';
$labels['hide_recovery_codes'] = 'Esconder códigos de recuperação';

$labels['setup_all_fields'] = 'Preencher campos';

$labels['enrollment_dialog_title'] = 'Habilitar Verificação em Duas Etapas [2FA]';
$labels['enrollment_dialog_msg'] = 'Por questões de segurança é obrigatório o cadastro e uso da <strong>Verificação em Duas Etapas [2FA]</strong>. Por favor efetue a configuração neste momento.';

$labels['check_code'] = 'Validar QR Code';
$labels['code_ok'] = 'QR Code Válido';
$labels['code_ko'] = 'QR Code Incorreto';

$labels['dont_ask_me_30days'] = 'Não perguntar por 30 dias';

$labels['check_code_to_activate'] = 'Para salvar, escaneie o QR code e introduza o código da Verificação em Duas Etapas [2FA] abaixo.';

$labels['status_on'] = 'Ativado';
$labels['status_off'] = 'Desativado';
$labels['activate_lead'] = 'Escaneie o QR code abaixo com seu aplicativo autenticador e informe o código de 6 dígitos para ativar a verificação em duas etapas.';
$labels['activate_hint'] = 'O código muda a cada 30 segundos.';
$labels['activate_button'] = 'Ativar 2FA';
$labels['disable_lead'] = 'A verificação em duas etapas está ativada nesta conta. Para desativar, informe o código atual do aplicativo autenticador.';
$labels['disable_button'] = 'Desativar 2FA';
$labels['code_hint'] = 'Digite o código de 6 dígitos exibido no aplicativo autenticador.';
$labels['qr_aria'] = 'QR code para configurar o aplicativo autenticador';
$labels['forced_enrollment_notice'] = 'A verificação em duas etapas é obrigatória na sua conta. Escaneie o QR code e ative para continuar.';
$labels['forced_enrollment_hint'] = 'O administrador exige a verificação em duas etapas, por isso ela não pode ser desativada.';
$labels['successfully_activated'] = 'Verificação em duas etapas ativada com sucesso.';
$labels['successfully_disabled'] = 'Verificação em duas etapas desativada com sucesso.';
$labels['error_invalid_code'] = 'Código inválido. Confira o aplicativo autenticador e tente novamente.';
$labels['error_too_many_attempts'] = 'Muitas tentativas. Aguarde um momento e tente novamente.';
$labels['error_pending_expired'] = 'A configuração expirou. Escaneie o novo QR code e tente novamente.';
$labels['error_already_active'] = 'A verificação em duas etapas já está ativada.';
$labels['error_not_active'] = 'A verificação em duas etapas não está ativada.';
$labels['error_forced_enrollment'] = 'A verificação em duas etapas é obrigatória e não pode ser desativada.';
$labels['error_csrf'] = 'Não foi possível validar a solicitação. Tente novamente.';
$labels['error_invalid_action'] = 'Ação inválida.';
$labels['error_not_allowed'] = 'A verificação em duas etapas não está disponível para esta conta.';
$labels['error_saving'] = 'Não foi possível salvar as configurações. Tente novamente.';

// Messages used for the different portions of the plugin
$messages = array();
$messages['successfully_saved'] = 'Configuração da Verificação em Duas Etapas [2FA] foi salva com sucesso.';
