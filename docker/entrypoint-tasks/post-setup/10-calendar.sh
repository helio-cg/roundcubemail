#!/bin/sh
set -e

if [ -d /opt/roundcube-vendor ]; then
    cp -a /opt/roundcube-vendor/. /var/www/html/vendor/
fi

for file in _styles.less _variables.less; do
    touch "/var/www/html/skins/elastic/styles/$file"
done

if ! grep -q "'themes/'" /var/www/html/public_html/static.php; then
    sed -i "s/    'skins\/',/    'skins\/',\n    'themes\/',/" /var/www/html/public_html/static.php
fi

mkdir -p /var/www/html/themes
ln -sfn /var/www/html/plugins/jqueryui/themes/elastic /var/www/html/themes/elastic
