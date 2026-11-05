<?php

$rcDeployPlugins = ['libcalendaring', 'libkolab', 'calendar', 'twofactor_gauthenticator', 'user'];
$rcDeploySkins = ['carius', 'iguatu', 'canga'];

$config['plugins'] = isset($config['plugins']) && is_array($config['plugins']) ? $config['plugins'] : [];
foreach ($rcDeployPlugins as $rcDeployPlugin) {
    if (!in_array($rcDeployPlugin, $config['plugins'], true)) {
        $config['plugins'][] = $rcDeployPlugin;
    }
}

$config['skins_allowed'] = isset($config['skins_allowed']) && is_array($config['skins_allowed'])
    ? $config['skins_allowed']
    : ['elastic'];

foreach ($rcDeploySkins as $rcDeploySkin) {
    if (!in_array($rcDeploySkin, $config['skins_allowed'], true)) {
        $config['skins_allowed'][] = $rcDeploySkin;
    }
}

$config['skin'] = 'carius';
$config['devel_mode'] = false;
$config['user_enable_skin_colors'] = true;

$config['disabled_actions'] = isset($config['disabled_actions']) && is_array($config['disabled_actions'])
    ? $config['disabled_actions']
    : [];

if (!in_array('settings.preferences', $config['disabled_actions'], true)) {
    $config['disabled_actions'][] = 'settings.preferences';
}
$config['calendar_driver'] = $config['calendar_driver'] ?? 'database';

// Webmail com URL unica para todos os dominios: o login usa o endereco
// completo (usuario@dominio). Nunca fixe um dominio aqui, senao as contas
// dos outros dominios do servidor deixam de autenticar.
$config['username_domain'] = '';
$rcmail_config['username_domain'] = '';
