#!/usr/bin/env bash
# Setup of the topology development VM on Ubuntu 26.04 (MySQL 8.4, PHP 8.5). Handoff task 022. Read the whole file first, then run it with sudo.
#
# *** THIS SCRIPT HAS NOT BEEN RUN. It was written on the old VM (Ubuntu 24.04, MariaDB 10.11, PHP 8.3) from the facts in lab/ENVIRONMENT.md and cannot
# be tested there. Package names, the MySQL 8.4 statements and the Python versions are the places most likely to need a fix. Read the output; every step prints
# what it does or "already done". ***
#
# What changes between the old VM and the new one (nothing here was tested on the new versions):
#   database   MariaDB 10.11 -> MySQL 8.4. The old `zabbix` user uses mysql_native_password; here it uses caching_sha2_password (the default of 8.4;
#              mysql_native_password is NOT enabled: nothing on the old VM needs it - the Zabbix server is linked to a client library that speaks
#              caching_sha2_password: libmysqlclient-dev below, or MariaDB Connector/C >= 3.2). Not the same server: `sql_mode` (8.4 adds ONLY_FULL_GROUP_BY, the
#              old one had NO_AUTO_CREATE_USER which 8.4 does not know), the default collation (utf8mb4_0900_ai_ci, the old VM had utf8mb4_general_ci),
#              `JSON_EXTRACT` on JSON columns (the old `longtext`). This script pins the character set, the collation and the sql_mode of the old VM
#              (override with TOPOLOGY_SQL_MODE, set it empty to keep the 8.4 default). A test that fails only on the new VM is most likely one of these.
#   PHP        8.3 -> 8.5 (same extensions, see below). OPcache is built in.
#   Python     3.12 -> the system Python of 26.04 (3.13 or newer). snmpsim 1.2.2 and pysnmp 7.1.29 were used on 3.12; they may not work on a newer Python.
#   Apache     2.4.58 -> the 26.04 package; same modules (rewrite, ssl, php).
#
# Variables (set them before the call; `sudo` drops the environment, so use `sudo env VAR=... ./ubuntu-26.04.sh` or `sudo -E`):
#   TARGET_USER          required. The login of the developer (owns the checkout, the lab venv and the logs).
#   ZABBIX_DB_PASSWORD   required, may be empty on purpose (`ZABBIX_DB_PASSWORD=`). The password of the database user `zabbix`. The lab reads the database
#                        password only from `settings.db_password` of lab.yaml (default empty): a non-empty password needs that setting, which is a change
#                        of the lab outside this task. The old VM used an empty password.
#   MYSQL_ROOT_ACCESS    optional. How the script talks to the server as an administrator; default `sudo mysql` (root with auth_socket, the Ubuntu default).
#   ZABBIX_REPO          optional. Absolute path of the checkout (e.g. /home/<user>/work/branches/zabbix). Without it the script installs the system and
#                        creates the empty database; with it (second call, after the clone) it also loads the Zabbix schema, makes the Apache site, the venvs
#                        and the log directory. Safe to run again.
#   ZABBIX_DB_DUMP       optional. A dump (.sql or .sql.gz) of the old `zabbix` database to load instead of building the schema from the files
#                        (only when the database has no tables yet). Made on the old VM with: mysqldump --single-transaction --routines zabbix | gzip > zabbix.sql.gz
#   TOPOLOGY_SQL_MODE    optional. Default: STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION (the old one without NO_AUTO_CREATE_USER).
#
# Not done by this script (see README.md): VirtualBox Guest Additions and the shared folders, the clone, the build of Zabbix, etc/zabbix_server.conf and
# ui/conf/zabbix.conf.php (they hold the database password), ZBX_TOKEN, the self-signed certificate for 443 (the lab uses http://localhost/zabbix/ui/).
#
# Idempotent: each step checks the state first; a second run changes nothing and says so.
set -euo pipefail

: "${TARGET_USER:?set TARGET_USER to the login of the developer}"
: "${ZABBIX_DB_PASSWORD?set ZABBIX_DB_PASSWORD (it may be empty: ZABBIX_DB_PASSWORD=)}"
SQL_MODE=${TOPOLOGY_SQL_MODE-STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION}
ZABBIX_REPO=${ZABBIX_REPO:-}
ZABBIX_DB_DUMP=${ZABBIX_DB_DUMP:-}
MYSQL_ROOT_ACCESS=${MYSQL_ROOT_ACCESS:-sudo mysql}
DB_NAME=zabbix
DB_USER=zabbix

[ "$(id -u)" -eq 0 ] || { echo "run it with sudo" >&2; exit 1; }
id "$TARGET_USER" >/dev/null 2>&1 || { echo "no user $TARGET_USER" >&2; exit 1; }
HOME_DIR=$(getent passwd "$TARGET_USER" | cut -d: -f6)
step() { printf '\n== %s\n' "$*"; }
as_user() { sudo -u "$TARGET_USER" -H "$@"; }
sql() { $MYSQL_ROOT_ACCESS -N -B -e "$1"; }

# write a file only when its content changes
put_file() {      # put_file <path> <mode>   (content on stdin)
	local path=$1 mode=$2 tmp
	tmp=$(mktemp)
	cat >"$tmp"
	if [ -f "$path" ] && cmp -s "$tmp" "$path"; then
		echo "  $path: already done"
		rm -f "$tmp"
	else
		install -D -m "$mode" "$tmp" "$path"
		rm -f "$tmp"
		echo "  $path: written"
		CHANGED=1
	fi
}
CHANGED=0

step "packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
# Everything §1 of ENVIRONMENT.md found. The meta packages (php-*, libapache2-mod-php) follow the PHP of the release (8.5 on 26.04).
PACKAGES=(
	mysql-server mysql-client libmysqlclient-dev            # the old VM linked the server to libmariadb-dev; with MySQL 8.4 use its own client library
	apache2 libapache2-mod-php php-cli php-mysql php-xml php-gd php-bcmath php-mbstring php-curl php-snmp php-zip php-gmp
	python3 python3-venv python3-pip python3-yaml
	snmp libsnmp-dev                                        # snmpbulkwalk, snmpget, snmpset; headers for --with-net-snmp
	build-essential autoconf automake libtool gettext pkg-config perl git
	libcurl4-openssl-dev libxml2-dev libpcre2-dev libevent-dev libssl-dev zlib1g-dev
)
apt-get install -y -qq "${PACKAGES[@]}"
echo "  installed: ${PACKAGES[*]}"
# optional on the old VM (loaded there, not used by the lab or the tests): php-pgsql, PostgreSQL server, nodejs 18, aws cli

step "group vboxsf (shared folders /media/sf_*)"
if getent group vboxsf >/dev/null; then
	if id -nG "$TARGET_USER" | tr ' ' '\n' | grep -qx vboxsf; then echo "  $TARGET_USER is in vboxsf: already done"; else usermod -aG vboxsf "$TARGET_USER"; echo "  added $TARGET_USER to vboxsf (log in again)"; fi
else
	echo "  no group vboxsf: install the VirtualBox Guest Additions first (README.md, step 1)"
fi

step "MySQL server settings"
put_file /etc/mysql/mysql.conf.d/zz-topology.cnf 644 <<EOF
# written by lab/setup/ubuntu-26.04.sh - the settings of the old VM that the tests may depend on
[mysqld]
character-set-server = utf8mb4
collation-server     = utf8mb4_general_ci
bind-address         = 127.0.0.1
$( [ -n "$SQL_MODE" ] && echo "sql_mode             = $SQL_MODE" )
EOF
if [ "$CHANGED" = 1 ]; then systemctl restart mysql; echo "  mysql restarted"; fi
systemctl enable --now mysql >/dev/null 2>&1 || true

step "database user and databases"
# caching_sha2_password, the 8.4 default. The password is passed to the server through the statement, never put into a file.
ESC_PASS=${ZABBIX_DB_PASSWORD//\\/\\\\}; ESC_PASS=${ESC_PASS//\'/\'\'}
if [ "$(sql "SELECT COUNT(*) FROM mysql.user WHERE user='$DB_USER' AND host='localhost'")" = 0 ]; then
	sql "CREATE USER '$DB_USER'@'localhost' IDENTIFIED WITH caching_sha2_password BY '$ESC_PASS'"
	echo "  user $DB_USER@localhost created (caching_sha2_password)"
else
	sql "ALTER USER '$DB_USER'@'localhost' IDENTIFIED WITH caching_sha2_password BY '$ESC_PASS'"
	echo "  user $DB_USER@localhost: password set again (the same value changes nothing)"
fi
# the tests create and drop topo_t_* databases; the lab writes only `zabbix` (the old user had ALL ON *.*, not needed)
sql "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'"
sql "GRANT ALL PRIVILEGES ON \`topo\\_t\\_%\`.* TO '$DB_USER'@'localhost'"
# the old VM had no zbx database on purpose: Dima does not move it; other test databases are created by hand
if [ "$(sql "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$DB_NAME'")" = 0 ]; then
	sql "CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin"
	echo "  database $DB_NAME created (utf8mb4_bin, as Zabbix wants it)"
else
	echo "  database $DB_NAME: already done"
fi

mysql_zabbix() { MYSQL_PWD=$ZABBIX_DB_PASSWORD mysql -h localhost -u "$DB_USER" "$@"; }

if [ -n "$ZABBIX_REPO" ]; then
	[ -d "$ZABBIX_REPO/create/src" ] || { echo "ZABBIX_REPO=$ZABBIX_REPO is not a Zabbix checkout" >&2; exit 1; }
	step "Zabbix schema in $DB_NAME"
	if [ "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'")" != 0 ]; then
		echo "  $DB_NAME has tables: already done (nothing is loaded into a database that is not empty)"
	elif [ -n "$ZABBIX_DB_DUMP" ]; then
		echo "  loading the dump $ZABBIX_DB_DUMP (a few minutes)"
		if [[ $ZABBIX_DB_DUMP == *.gz ]]; then gunzip -c "$ZABBIX_DB_DUMP" | mysql_zabbix "$DB_NAME"; else mysql_zabbix "$DB_NAME" <"$ZABBIX_DB_DUMP"; fi
	else
		# The order of the old VM (lab/ENVIRONMENT.md §5). schema.sql and data.sql are generated by perl from create/src/*.tmpl.
		work=$(mktemp -d)
		trap 'rm -rf "$work"' EXIT
		( cd "$ZABBIX_REPO"
		  create/bin/gen_schema.pl mysql >"$work/schema.sql"
		  cat create/src/data.tmpl create/src/templates-*.tmpl create/src/dashboards.tmpl | create/bin/gen_data.pl mysql >"$work/data.sql" )
		echo "  1/5 schema.sql";  mysql_zabbix "$DB_NAME" <"$work/schema.sql"
		echo "  2/5 images.sql";  mysql_zabbix "$DB_NAME" <"$ZABBIX_REPO/database/mysql/images.sql"
		echo "  3/5 data.sql (about 2 minutes)"; mysql_zabbix "$DB_NAME" <"$work/data.sql"
		# schema.sql has the old shape of topo_nodes and topo_edges (empty here): they are created again, in the current shape, with the two tables it lacks
		echo "  4/5 drop the old topo_edges, topo_nodes"
		mysql_zabbix "$DB_NAME" -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS topo_observations, topo_identity_keys, topo_edges, topo_nodes; SET FOREIGN_KEY_CHECKS=1"
		echo "  5/5 database/topology/mysql_schema.sql"; mysql_zabbix "$DB_NAME" <"$ZABBIX_REPO/database/topology/mysql_schema.sql"
	fi

	step "Apache site for the checkout"
	SITE_ROOT=$(dirname "$ZABBIX_REPO")      # the old VM serves /home/<user>/work/branches, so the frontend is http://localhost/zabbix/ui/
	a2enmod rewrite ssl >/dev/null
	CHANGED=0
	put_file /etc/apache2/sites-available/zabbix.conf 644 <<EOF
<VirtualHost *:80>
    RewriteEngine On
    RewriteRule ^/zabbix/oauth\.authorize\$ /zabbix/zabbix.php?action=oauth.authorize [QSA,L,PT]
    DocumentRoot $SITE_ROOT
    <Directory $SITE_ROOT>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF
	[ -e /etc/apache2/sites-enabled/zabbix.conf ] || { a2ensite zabbix >/dev/null; CHANGED=1; }
	[ -e /etc/apache2/sites-enabled/000-default.conf ] && { a2dissite 000-default >/dev/null; CHANGED=1; }
	PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
	put_file "/etc/php/$PHPV/apache2/conf.d/99-zabbix-topology.ini" 644 <<EOF
; written by lab/setup/ubuntu-26.04.sh - the values of the old VM that differ from the defaults
max_execution_time = 300
max_input_time = 300
memory_limit = 512M
post_max_size = 16M
date.timezone = UTC
EOF
	put_file "/etc/php/$PHPV/cli/conf.d/99-zabbix-topology.ini" 644 <<EOF
; written by lab/setup/ubuntu-26.04.sh
date.timezone = UTC
EOF
	if [ "$CHANGED" = 1 ]; then systemctl reload apache2 || systemctl restart apache2; fi
	systemctl enable --now apache2 >/dev/null 2>&1 || true

	step "log directory and Python venvs (as $TARGET_USER)"
	as_user mkdir -p "$HOME_DIR/work/logs"
	echo "  $HOME_DIR/work/logs: present"
	# the lab venv (README of the lab): PyYAML from the system, snmpsim and the MySQL connector from requirements.txt
	if [ ! -x "$HOME_DIR/work/labenv/bin/python" ]; then as_user python3 -m venv --system-site-packages "$HOME_DIR/work/labenv"; echo "  labenv created"; else echo "  labenv: already done"; fi
	as_user "$HOME_DIR/work/labenv/bin/pip" install -q -r "$ZABBIX_REPO/database/topology/lab/requirements.txt"
	# the stock venv in the checkout that runs the simulator: the packages and versions of the old VM (lab/ENVIRONMENT.md §2)
	if [ ! -x "$ZABBIX_REPO/.venv/bin/python" ]; then as_user python3 -m venv "$ZABBIX_REPO/.venv"; echo "  .venv created"; else echo "  .venv: already done"; fi
	as_user "$ZABBIX_REPO/.venv/bin/pip" install -q snmpsim==1.2.2 pysnmp==7.1.29 pysmi==2.0.0 lark==1.3.1 mysql-connector-python==9.7.0 psycopg2-binary==2.9.12 \
		requests==2.34.2 Jinja2==3.1.6 pyasn1==0.6.4
else
	echo
	echo "ZABBIX_REPO is not set: the Zabbix schema, the Apache site and the Python venvs are skipped. Clone the repository, then run this script again with ZABBIX_REPO=<path>."
fi

step "done"
echo "Next: lab/setup/README.md (build Zabbix, configuration files, ZBX_TOKEN, first lab run)."
