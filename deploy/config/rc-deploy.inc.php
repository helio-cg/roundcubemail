<?php

$rcDeployPlugins = ['libcalendaring', 'libkolab', 'calendar', 'twofactor_gauthenticator', 'user'];
$rcDeploySkins = ['carius', 'aurora', 'iguatu', 'canga'];

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
$config['calendar_driver'] = $config['calendar_driver'] ?? 'database';
