#!/usr/bin/env bash

set -euo pipefail

usage() {
	cat <<'EOF'
Usage: install_mysql.sh --database <name> --user <name> [options]

Creates the topology prototype tables if they do not already exist.

Options:
  -h, --host <host>          MySQL host (default: localhost)
  -P, --port <port>          MySQL port (default: 3306)
  -d, --database <name>      Database name (required)
  -u, --user <name>          Database user (required)
  -p, --password <password>  Database password
      --password-file <path> Read database password from a file
      --help                 Show this help text
EOF
}

host=localhost
port=3306
database=
user=
password=
password_file=

while (($#)); do
	case $1 in
		-h|--host) host=$2; shift 2 ;;
		-P|--port) port=$2; shift 2 ;;
		-d|--database) database=$2; shift 2 ;;
		-u|--user) user=$2; shift 2 ;;
		-p|--password) password=$2; shift 2 ;;
		--password-file) password_file=$2; shift 2 ;;
		--help) usage; exit 0 ;;
		*) usage >&2; exit 1 ;;
	esac
done

if [[ -z $database || -z $user ]]; then
	usage >&2
	exit 1
fi

if [[ -n $password && -n $password_file ]]; then
	echo 'Use either --password or --password-file, not both.' >&2
	exit 1
fi

if [[ -n $password_file ]]; then
	password=$(<"$password_file")
fi

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
mysql_args=(--host="$host" --port="$port" --user="$user" --database="$database" --protocol=TCP)

if [[ -n $password ]]; then
	MYSQL_PWD=$password mysql "${mysql_args[@]}" < "$script_dir/mysql_schema.sql"
	MYSQL_PWD=$password mysql "${mysql_args[@]}" < "$script_dir/mysql_migrate_host_ref.sql"
	MYSQL_PWD=$password mysql "${mysql_args[@]}" < "$script_dir/mysql_migrate_proxy_ref.sql"
	MYSQL_PWD=$password mysql "${mysql_args[@]}" < "$script_dir/mysql_rename_interface_to_port.sql"
	MYSQL_PWD=$password mysql "${mysql_args[@]}" < "$script_dir/mysql_migrate_uniqueness.sql"
else
	mysql "${mysql_args[@]}" < "$script_dir/mysql_schema.sql"
	mysql "${mysql_args[@]}" < "$script_dir/mysql_migrate_host_ref.sql"
	mysql "${mysql_args[@]}" < "$script_dir/mysql_migrate_proxy_ref.sql"
	mysql "${mysql_args[@]}" < "$script_dir/mysql_rename_interface_to_port.sql"
	mysql "${mysql_args[@]}" < "$script_dir/mysql_migrate_uniqueness.sql"
fi

echo "Topology tables are installed in MySQL database '$database'."