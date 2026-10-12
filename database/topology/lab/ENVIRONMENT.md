# The development VM — what the lab and the tests need

Facts read from the VM on 2026-10-12 (code commit `a108d89fa11`), each with the command used. No secret value is written here: a secret is named and
its place is given. Paths with `zhuk` are the user of this VM. Handoff task 022; input for `setup/ubuntu-26.04.sh`.

**One finding that changes the plan:** the database server here is **MariaDB 10.11**, not MySQL 8.0 (`mysqld --version`). The `zabbix` user uses
`mysql_native_password`. The new VM is planned with MySQL 8.4; see "Databases" and `setup/README.md`.

## 1. System

| What | Value | Command |
|---|---|---|
| OS | Ubuntu 24.04.5 LTS | `lsb_release -ds` |
| Kernel | 6.8.0-142-generic | `uname -r` |
| VirtualBox Guest Additions | 7.2.20 r175154 (kernel module `vboxguest`; packages `virtualbox-guest-*` not used) | `modinfo vboxguest \| grep -i ^version` |
| CPU / memory | 4 CPUs, 16 GB | `nproc`, `free -m` |
| User | `zhuk` (uid 1000), groups include `sudo`, `vboxsf` (999) | `id` |
| Shared folder `topology-specs` | mounted at `/media/sf_topology-specs`, `vboxsf`, `rw,nodev,uid=0,gid=999,dmode=0770,fmode=0770`, tag `VBoxAutomounter` (VirtualBox auto-mount; read only for the agent by rule) | `mount \| grep vboxsf` |
| Shared folder `topology-handoff` | `/media/sf_topology-handoff`, same options | same |
| fstab entries for them | none (auto-mount by the Guest Additions) | `grep -i vboxsf /etc/fstab` |

Access to the folders needs the group `vboxsf`.

## 2. Versions

| What | Value | Command |
|---|---|---|
| Database server | MariaDB 10.11.14 (Ubuntu 24.04 package) | `mysqld --version` |
| Client library of the Zabbix server | `libmariadb.so.3` (package `libmariadb-dev` 10.11.14, built with it) | `ldd sbin/zabbix_server` |
| PHP | 8.3.6 CLI and `libapache2-mod-php8.3` | `php -v` |
| PHP extensions loaded | bcmath calendar Core ctype curl date dom exif FFI fileinfo filter ftp gd gettext gmp hash iconv json libxml mbstring mysqli mysqlnd openssl pcntl pcre PDO pdo_mysql pdo_pgsql pgsql Phar posix random readline Reflection session shmop SimpleXML snmp sockets sodium SPL standard sysvmsg sysvsem sysvshm tokenizer xml xmlreader xmlwriter xsl OPcache zip zlib | `php -m` |
| Web server | Apache 2.4.58, mpm_prefork, mod_php, modules: rewrite ssl deflate status … | `apache2 -v`, `apache2ctl -M` |
| Python | 3.12.3 (system) | `python3 --version` |
| Node | 18.19.1 (npm 9.2.0); used for ad hoc scripts only, not by the lab or the tests | `node --version` |
| Compiler, build | gcc 13.3.0, GNU Make 4.3, autoconf 2.71, automake 1.16.5, libtool 2.4.7, gettext 0.21, pkg-config 1.8.1 | `gcc --version`, `dpkg -l autoconf automake libtool` |
| Net-SNMP | tools `snmpbulkwalk`, `snmpget`, `snmpset` (package `snmp`), `libsnmp-dev` 5.9.4 | `which snmpbulkwalk`, `dpkg -l libsnmp-dev` |
| Other dev packages the build used | `libcurl4-openssl-dev` 8.5.0, `libxml2-dev` 2.9.14, `libpcre2-dev` 10.42, `libevent-dev` 2.1.12, `libssl-dev` 3.0.13, `libopenipmi-dev`, `libsqlite3-dev`, `libpq-dev` 16 | `dpkg -l \| grep -- -dev` |
| PostgreSQL | a server listens on 5432 and 5433; **not used by the lab or the tests** (PostgreSQL work is known debt, model §18) | `ss -ltn` |

**Python venvs** (the lab uses two):
- `/home/zhuk/work/labenv` — made with `python3 -m venv --system-site-packages ~/work/labenv`; the lab runs with `~/work/labenv/bin/python`. PyYAML comes from the
  system package (`python3-yaml`); `pip install -r database/topology/lab/requirements.txt` adds `snmpsim==1.2.2` and `mysql-connector-python`
  (`~/work/labenv/bin/pip list`).
- `/home/zhuk/work/branches/zabbix/.venv` — stock venv for the simulator: `snmpsim 1.2.2`, `pysnmp 7.1.29`, `pysmi 2.0.0`, `lark 1.3.1`, `mysql-connector-python 9.7.0`,
  `psycopg2-binary 2.9.12`, `requests 2.34.2`, `Jinja2 3.1.6`, `pyasn1 0.6.4`, `certifi`, `charset-normalizer`, `idna`, `MarkupSafe`, `urllib3`, `pip 24.0`
  (`.venv/bin/pip list`). `labsim` starts `.venv/bin/snmpsim-command-responder` (`settings.snmpsim_bin` overrides it). The corpus converter `corpus_walk.py verify` takes the responder path as an argument.

## 3. Web server and PHP configuration

Enabled sites (`ls /etc/apache2/sites-enabled`): `zabbix.conf`, `zabbix-ssl.conf`, `localhost-ssl.conf`; non-default lines:

- `/etc/apache2/sites-available/zabbix.conf` — `<VirtualHost *:80>`: `RewriteEngine On`, `RewriteRule ^/zabbix/oauth\.authorize$ /zabbix/zabbix.php?action=oauth.authorize [QSA,L,PT]`,
  `DocumentRoot /home/zhuk/work/branches`, `<Directory /home/zhuk/work/branches>` with `Options Indexes FollowSymLinks`, `AllowOverride All`, `Require all granted`.
  So the frontend is at `http://localhost/zabbix/ui/` (the repository directory is `…/branches/zabbix`).
- `zabbix-ssl.conf` — `*:443`, same `DocumentRoot`, `SSLEngine on`, `SSLCertificateFile /etc/ssl/certs/selfsigned.crt`, `SSLCertificateKeyFile /etc/ssl/private/selfsigned.key` (a self-signed pair; the key is a secret and is not copied).
- `localhost-ssl.conf` — `*:443` `ServerName localhost`, `DocumentRoot /var/www/html`, same certificate pair.
- `/home/zhuk/work/branches/.htaccess` (95 bytes: `RewriteEngine On` and the `oauth.authorize` rewrite) is read because of `AllowOverride All`.

PHP (`/etc/php/8.3/apache2/php.ini`; the CLI `php.ini` is `/etc/php/8.3/cli/php.ini`), values that differ from the defaults:
`max_execution_time = 300`, `max_input_time = 300`, `memory_limit = 512M`, `post_max_size = 16M`; `date.timezone` is `UTC`; OPcache on for Apache, off for the CLI.
Command: `grep -E '^(memory_limit|post_max_size|max_execution_time|max_input_time|date.timezone|upload_max_filesize)' /etc/php/8.3/apache2/php.ini`.

The frontend configuration is `ui/conf/zabbix.conf.php` (not in git): `TYPE MYSQL`, `SERVER localhost`, `PORT 0`, `DATABASE zabbix`, `USER zabbix`, `PASSWORD` = **secret** (named `$DB['PASSWORD']`, in that file),
the other keys are the defaults. The lab PHP helpers read the same file (`lab/php/bootstrap.php`).

## 4. Zabbix build, install, start

| What | Value | Command |
|---|---|---|
| Repository path | `/home/zhuk/work/branches/zabbix` | |
| Remote | `origin https://git.zabbix.com/scm/zbx/zabbix.git` | `git remote -v` |
| Branch | `topology_cloude` (main branch `master`) | `git branch --show-current` |
| Commit of this inventory | `a108d89fa11…` | `git log -1 --format=%H` |
| `configure` line | `./configure --enable-proxy --enable-server --enable-agent --with-mysql --with-libcurl --with-net-snmp --with-libxml2 --prefix=/home/zhuk/work/branches/zabbix` | `grep -m1 '^  \$ ' config.log` |
| Build | `./bootstrap.sh` (if `configure` is missing), `./configure …`, `make -j4` | |
| Install | no `make install`: the binaries stay in the tree — `sbin/zabbix_server`, `sbin/zabbix_proxy`, `sbin/zabbix_agentd`, `bin/zabbix_sender`, `bin/zabbix_get`, `bin/zabbix_js` (`ls sbin bin`) | |
| Rebuilt after C changes | the native step (`src/libs/zbxpreproc/preproc_topology.c`) and `lld_topology.c` are part of `sbin/zabbix_server`; the step test program is `database/topology/discovery/step_golden/topo_step` (built by `build.sh` in that folder, linked against the built tree: `make` the server first) | `ls database/topology/discovery/step_golden` |

Server configuration `etc/zabbix_server.conf` (non-default parameters): `LogFile=/home/zhuk/work/logs/zabbix_server.log`, `DebugLevel=3`, `DBName=zabbix`, `DBUser=zabbix`, `DBPassword=` (**empty**: the `zabbix` database user has no password; a password would go here — secret),
`StartPollers=1`, `StartAgentPollers=1`, `StartHTTPAgentPollers=2`, `StartSNMPPollers=2`, `StartIPMIPollers=0`, `StartPreprocessors=1`, `StartConnectors=3`, `StartPollersUnreachable=1`, `StartHistoryPollers=1`, `StartTrappers=1`,
`StartPingers=1`, `StartDiscoverers=1`, `StartHTTPPollers=2`, `StartTimers=1`, `StartEscalators=1`, `StartAlerters=1`, `StartJavaPollers=0`, `StartVMwareCollectors=0`, `StartSNMPTrapper=1`, `Timeout=4`, `LogSlowQueries=3000`,
`StartProxyPollers=1`, `StartLLDProcessors=1`, `StatsAllowedIP=127.0.0.1`, `StartODBCPollers=0`, `StartBrowserPollers=0`. Command: `grep -vE '^\s*(#|$)' etc/zabbix_server.conf`.
Agent `etc/zabbix_agentd.conf`: `LogFile=/home/zhuk/work/logs/zabbix_agent.log`, `Server=0.0.0.0/0`, `Hostname=DevLinux`. The log directory `/home/zhuk/work/logs/` must exist and be writable.

Start (manual, nothing is a systemd unit): `cd /home/zhuk/work/branches/zabbix && sbin/zabbix_server -c etc/zabbix_server.conf` (and `sbin/zabbix_agentd -c etc/zabbix_agentd.conf`). The frontend is started with Apache (`systemctl is-enabled apache2` = enabled).
Ports: 10051 (server trapper), 80 and 443 (Apache), 3306 (database, listens on 0.0.0.0 here: `bind-address = 0.0.0.0` in `/etc/mysql/mariadb.conf.d/50-server.cnf` — the new VM does not need that), 161 (local snmpd), lab simulators on `127.0.0.2-6:1611`, corpus devices `127.0.10.N:1611`.
After a reboot the server is **not** running until it is started by hand (this was true on 2026-10-12).

API token for the lab: environment variable `ZBX_TOKEN` (set in `~/.bashrc` or `~/.profile`; the value is a secret and is never written to a file by the tools).

## 5. Databases

`mysql -uzabbix` (no password here). Read only queries:

| Database | Size | Charset / collation | Used by |
|---|---|---|---|
| `zabbix` | 93.7 MB | utf8mb4 / **utf8mb4_bin** | the frontend, the server, the lab (the only database the lab may write) |
| `topo_t_*` (about 14: `cat`, `cat2`, `shared`, `ppi`, `si`, `join`, `dup`, `lr`, `fpi`, `old`, `char`, `busy_port`, `ingest_lock`, `corpus`) | 0.4 MB each | utf8mb4 / utf8mb4_general_ci | `run_php_tests.sh` (drops and recreates them), the harness, the corpus runner; throwaway |
| `zbx` | — | utf8mb4 / utf8mb4_general_ci | **not moved** (Dima) |
| `librenms`, `netxms`, `zx` | — | — | other work, not moved |

Server defaults: `character-set-server = utf8mb4`, `collation-server = utf8mb4_general_ci` (`/etc/mysql/mariadb.conf.d/50-server.cnf`, lines 91-92).
Users (`mysql -uzabbix -e "SELECT user,host,plugin FROM mysql.user"`): `root@localhost`, `mysql@localhost`, `mariadb.sys@localhost`, **`zabbix@localhost`**, `zhuk@%`, `zhuk18@%`, `librenms@localhost`; all with `mysql_native_password`.
`zabbix@localhost` has `ALL PRIVILEGES ON *.*` (the tests create and drop `topo_t_*`). The tests, the lab and the frontend connect as `zabbix` over `localhost`. No password is stored anywhere for it.

How the `zabbix` database is built (verified by loading a scratch database with the same files and comparing, see `setup/README.md`): in this order, all from the repository,
1. `database/mysql/schema.sql` — the Zabbix schema including `items.topology_role`, `topo_nodes`, `topo_edges`, `topo_lld_snapshot` (generated from `create/src/schema.tmpl`);
2. `database/mysql/images.sql`;
3. `database/mysql/data.sql` — also sets `dbversion` (7050195 here);
4. `database/topology/mysql_schema.sql` — idempotent (`CREATE TABLE IF NOT EXISTS`), adds `topo_observations` and `topo_identity_keys`, which `schema.sql` does not have yet.
The `mysql_migrate_*.sql` files are for databases that were built before a change; a new database does not need them.
The test harness builds its throwaway databases itself from stand-in tables plus `database/topology/mysql_schema.sql`.

## 6. The lab and the tests

- `labsim`: `database/topology/lab/` (`python -m labsim …` from that directory, `~/work/labenv/bin/python`). Data: `lab/data/` (generated snmprec, git-ignored), `lab/run/` (pids, logs; git-ignored), `lab/reports/` (S0-S5 reports are tracked; `SC*.json` is ignored),
  `lab/variation/` (the snmpsim module `labmultiplex`), `lab/php/` (read-only PHP helpers, they read `ui/conf/zabbix.conf.php`), `lab/lab.yaml`, `lab/scenarios/`.
  The simulators run on `127.0.0.0/8` addresses; no IP alias is needed. `ctl.py` would create a `dummy` interface with `sudo -n ip` for an address outside 127/8 (not used).
- Lab settings that name this VM (`lab.yaml`): `url: http://localhost/zabbix/ui/api_jsonrpc.php`, `group: Test`, templates `Topology by SNMP`, `Topology by push`, `Network Generic Device by SNMP`, `adopt: [Router1, Switch1, Switch2, UPS1, AccessSwitch1]` (hosts that already exist in the `zabbix` database).
  `export_dir` default `~/work/lab-backup`.
- Environment variables read (names only): `ZBX_TOKEN` (lab API), `TMPDIR` (status file location), `TOPOLOGY_PDO_DSN`, `TOPOLOGY_PDO_USER`, `TOPOLOGY_PDO_PASSWORD` (ingest), `ZABBIX_API_URL`, `ZABBIX_API_TOKEN` (ingest, optional API),
  `ZABBIX_SENDER_SERVER`, `ZABBIX_SENDER_PORT`, `ZABBIX_SENDER_BIN` (push.py), `TOPOLOGY_CORPUS` (corpus path), `CATALOG_CASES`, `CATALOG_DUMP`, `CATALOG_ECHO`, `X01_DEBUG` (harness).
- Tools the tests call: `php`, `mysql`, `python3`, `snmpbulkwalk`, `snmpget`, `snmpset`, `zabbix_js` (`bin/`), `bin/zabbix_sender`, `step_golden/topo_step`.
- Corpus (read only, GPLv3): `/media/sf_topology-specs/corpus/librenms/*.snmprec`; generated files go to `database/topology/corpus/out/` (git-ignored).

## 7. The agent

- `CLAUDE.md` files: only `/home/zhuk/work/branches/zabbix/CLAUDE.md` — specs are `/media/sf_topology-specs` (read only), handoff is `/media/sf_topology-handoff`, report the spec hash, propose spec changes in the report.
- Claude Code settings: `/home/zhuk/.claude/settings.json`; project memory: `/home/zhuk/.claude/projects/-home-zhuk-work-branches-zabbix/memory/`.
- Nothing in the repository reads these.

## 8. Things tied to this VM (file:line)

Paths and users: the lab and the tests hard-code very little; this is the list (`grep -rnE '/home/zhuk|/media/sf_|~/work' database/topology`):

| Where | What |
|---|---|
| `database/topology/lab/labsim/corpus.py:18` | `CORPUS_DEFAULT = '/media/sf_topology-specs/corpus'` |
| `database/topology/lab/labsim/corpus.py:28` | `SERVER_LOG = ~/work/logs/zabbix_server.log` |
| `database/topology/lab/labsim/zbx.py:55` | `export_dir` default `~/work/lab-backup` |
| `database/topology/corpus/corpus_walk.py:22` | default corpus `/media/sf_topology-specs/corpus` (`TOPOLOGY_CORPUS`) |
| `database/topology/lab/README.md:15-16, 47` | `~/work/labenv`, `~/work/lab-backup` |
| `database/topology/lab/lab.yaml:12` | API url `http://localhost/zabbix/ui/api_jsonrpc.php` (the path `/zabbix/` comes from the Apache DocumentRoot above) |
| `database/topology/lab/lab.yaml:5, 20-56` | `127.0.0.0/24`, hosts `127.0.0.2-6`, `snmp_port: 1611`, community `zbxlab` |
| `database/topology/lab/labsim/corpus.py:20` | corpus devices on `127.0.10.N` |
| `database/topology/run_php_tests.sh:22, 26, 51` | `localhost` database host, `/tmp/topo_t_*.log` |
| `database/topology/discovery/*` tests | `localhost` in the DSNs and `SERVER => 'localhost'` (`test_scenario_catalog.php:215`, `test_topology_model.php:165`, `test_duplicate_candidates.php:201`, `test_manual_link_busy_port.php:38, 78`) |
| `database/topology/discovery/push.py:76` | `ZABBIX_SENDER_BIN = <repo>/bin/zabbix_sender` (relative to the checkout) |
| `database/topology/discovery/push.py:890-892` | sender defaults `127.0.0.1:10051` |
| `database/topology/discovery/test_snapshot_clock.py:7, 25` | `127.0.0.1`, port `10151`, db `zabbix` |
| `database/topology/lab/php/bootstrap.php:37, 68` | `REMOTE_ADDR 127.0.0.1`, `localhost` from `ui/conf/zabbix.conf.php` |
| `etc/zabbix_server.conf`, `etc/zabbix_agentd.conf` | `/home/zhuk/work/logs/…` |
| `/etc/apache2/sites-available/zabbix*.conf` | `/home/zhuk/work/branches`, the certificate files |
| `/home/zhuk/.bashrc` or `~/.profile` | `ZBX_TOKEN` |
| `database/topology/lab/lab.yaml:10` | `adopt` list: the five hosts must exist, or `provision` creates them |

User names: `zhuk` (OS and database user `zhuk@%`), database user `zabbix`. IP addresses: `127.0.0.1/2-6/10.N`; no external address is used by the lab.
