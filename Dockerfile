FROM docker.io/roundcube/roundcubemail:1.7.5-apache

ARG ROUNDCUBE_SKIN=canga

COPY ./src/skins/${ROUNDCUBE_SKIN}/ /usr/src/roundcubemail/skins/${ROUNDCUBE_SKIN}/
COPY ./src/plugins/ /usr/src/roundcubemail/plugins/

RUN chown -R www-data:www-data /usr/src/roundcubemail/skins /usr/src/roundcubemail/plugins
