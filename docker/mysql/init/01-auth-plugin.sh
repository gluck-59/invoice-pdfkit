#!/bin/sh
# Runs once at first init (fresh volume). Forces password accounts onto
# mysql_native_password so plain TCP clients (Sequel Ace etc.) can connect
# without SSL/RSA handshake. Mirrors the reference cabinet setup.
set -eu
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<EOSQL
ALTER USER IF EXISTS '$MYSQL_USER'@'%' IDENTIFIED WITH mysql_native_password BY '$MYSQL_PASSWORD';
ALTER USER IF EXISTS 'root'@'%' IDENTIFIED WITH mysql_native_password BY '$MYSQL_ROOT_PASSWORD';
ALTER USER IF EXISTS 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$MYSQL_ROOT_PASSWORD';
FLUSH PRIVILEGES;
EOSQL
