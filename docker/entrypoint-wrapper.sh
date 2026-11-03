#!/bin/sh
set -e

# A imagem oficial reexecuta `bin/installto.sh` + `composer` a cada start quando
# ja existe uma instalacao em /var/www/html. Esse composer desinstala o pacote
# kolab/calendar e apaga o conteudo do bind mount src/plugins/calendar.
#
# Em um restart do mesmo container (o /var/www/html ja foi inicializado) pulamos
# esse caminho de update e subimos o Apache direto. Um container recriado tem
# /var/www/html vazio, entao o entrypoint original roda a instalacao inicial.
[ "$#" -eq 0 ] && set -- apache2-foreground

if [ -f /var/www/html/.entrypoint-initialized ]; then
    exec "$@"
fi

touch /var/www/html/.entrypoint-initialized
exec /docker-entrypoint.sh "$@"
