# The new development VM (Ubuntu 26.04, MySQL 8.4, PHP 8.5)

`ubuntu-26.04.sh` has **not been tested**; it was written on the old VM from `../ENVIRONMENT.md`. The old VM stays until the new one passes the same
tests on the same commit: compare with the files in `../baseline/<commit>/` (`../baseline/<commit>/COMMANDS.md` has the exact commands).

Read `../ENVIRONMENT.md` first. The main surprise there: the old VM runs **MariaDB 10.11**, so MySQL 8.4 is a different server, not an update. If a test fails only on the new
VM, look at these first: `sql_mode` (`ONLY_FULL_GROUP_BY`), the default collation (`utf8mb4_0900_ai_ci`; the script pins `utf8mb4_general_ci`), `JSON_EXTRACT` on JSON
columns (they are `longtext` on the old server), and the Python version for snmpsim.

## Order of the steps

1. **VM.** Install Ubuntu 26.04, make the user (the old one is `zhuk`, in the groups `sudo` and `vboxsf`). Install the VirtualBox Guest Additions (7.2.20 on the old VM) and
   add the two shared folders with auto-mount: `topology-specs` -> `/media/sf_topology-specs`, `topology-handoff` -> `/media/sf_topology-handoff`
   (`mount | grep vboxsf` on the old VM). The user needs the group `vboxsf`. The script cannot do this.
2. **System, database, PHP, Apache** (first call, no checkout yet):
   ```
   sudo env TARGET_USER=<login> ZABBIX_DB_PASSWORD='<password or empty>' ./ubuntu-26.04.sh
   ```
   It installs the packages, writes `/etc/mysql/mysql.conf.d/zz-topology.cnf`, creates the user `zabbix` (`caching_sha2_password`) with rights on `zabbix` and `topo_t_*`, and the empty database
   `zabbix` (`utf8mb4_bin`). A second call changes nothing. The password stays with you: the script puts it in no file.
3. **Clone** (the old VM: `/home/zhuk/work/branches/zabbix`):
   ```
   git clone https://git.zabbix.com/scm/zbx/zabbix.git ~/work/branches/zabbix    # or the remote you use; then:
   cd ~/work/branches/zabbix && git checkout <the commit of ../baseline/<commit>>   # same commit as the baseline
   ```
   Keep `branches/` as the parent: the Apache site serves the parent directory and the frontend is `http://localhost/zabbix/ui/`.
4. **Second call** with the checkout (loads the Zabbix schema, makes the Apache site, the two venvs and `~/work/logs`):
   ```
   sudo env TARGET_USER=<login> ZABBIX_DB_PASSWORD='<password or empty>' ZABBIX_REPO=$HOME/work/branches/zabbix ./ubuntu-26.04.sh
   ```
   About 3 minutes (`data.sql` is 27 MB). To start from the old data instead (hosts, templates, the lab hosts, the API token user), dump the old database and give the dump:
   `mysqldump --single-transaction --routines zabbix | gzip > zabbix.sql.gz` on the old VM, then `ZABBIX_DB_DUMP=/path/zabbix.sql.gz` in this call. A dump is only loaded into an empty database.
5. **Build Zabbix** (not done by the script; as the user, in the checkout). The `configure` line of the old VM, with the client library of MySQL 8.4 found by `mysql_config`:
   ```
   ./bootstrap.sh    # only if ./configure is missing
   ./configure --enable-proxy --enable-server --enable-agent --with-mysql --with-libcurl --with-net-snmp --with-libxml2 --prefix=$PWD
   make -j4
   ```
   The binaries stay in the tree (`sbin/zabbix_server`, `bin/zabbix_sender`, `bin/zabbix_js`). Then the standalone driver of the native step:
   `cd database/topology/discovery/step_golden && ./build.sh` (needs the built tree).
6. **Configuration files** (they hold the database password; the script does not write them):
   - `ui/conf/zabbix.conf.php`: copy `ui/conf/zabbix.conf.php.example`, set `$DB['TYPE']='MYSQL'`, `SERVER 'localhost'`, `PORT '0'`, `DATABASE 'zabbix'`, `USER 'zabbix'`, `PASSWORD`.
   - `etc/zabbix_server.conf`: the non-default parameters are listed in `../ENVIRONMENT.md` §4 (log file in `~/work/logs`, `DBName=zabbix`, `DBUser=zabbix`, `DBPassword=<yours>`, the `Start*` counts, `Timeout=4`, `StatsAllowedIP=127.0.0.1`).
   - `etc/zabbix_agentd.conf`: `LogFile`, `Server=0.0.0.0/0`, `Hostname=DevLinux`.
7. **Frontend first start.** Open `http://localhost/zabbix/ui/`. With a loaded schema (step 4) the frontend finds `zabbix.conf.php` and asks for the login. A new database has the default
   user `Admin` (password `zabbix` until you change it). Create an API token for the lab (User settings -> API tokens).
8. **Start the server by hand** (nothing is a service): `cd ~/work/branches/zabbix && sbin/zabbix_server -c etc/zabbix_server.conf`. It does not start by itself after a reboot.
9. **Environment variable:** `export ZBX_TOKEN=<the API token>` in `~/.bashrc` (never in a file of the repository). The lab reads the token only from there.
10. **Hosts and templates.** The lab needs the templates `Topology by SNMP`, `Topology by push` and `Network Generic Device by SNMP` and the host group `Test` (`../lab.yaml`). Import
    `database/topology/discovery/template_topology_by_snmp.yaml` and `template_topology_by_push.yaml` (Data collection -> Templates -> Import) and create the group `Test`. If you loaded a dump (step 4), they are in it.
    `provision` creates the lab hosts; it adopts `Router1`, `Switch1`, `Switch2`, `UPS1`, `AccessSwitch1` if they exist (`settings.adopt`).
11. **First lab run** (from `database/topology/lab`):
    ```
    ~/work/labenv/bin/python -m unittest discover -s tests
    ~/work/labenv/bin/python -m labsim provision S0
    ~/work/labenv/bin/python -m labsim run S0 --reset
    ```
    then the whole set from `../baseline/<commit>/COMMANDS.md` and compare the results with the baseline.

## What the script does not do (manual steps in short)
Guest Additions and shared folders; the clone and the checkout of the commit; the build; `zabbix.conf.php` and the two server configuration files; the API token and `ZBX_TOKEN`; the template import
and the host group (when no dump is used); the self-signed certificate and the HTTPS sites (`443`; the lab and the tests use port 80); starting `zabbix_server`; the `zbx` database (not moved).

## Known limits
- The lab's Python reads the database password only from `settings.db_password` of `lab.yaml` (default empty), not from an environment variable. With a non-empty password for `zabbix`, set it there locally
  (do not commit it) or keep the password empty on the new VM, as on the old one. `run_php_tests.sh` takes the password as its second argument.
- `snmpsim 1.2.2` / `pysnmp 7.1.29` were run on Python 3.12 only.
- The old `zabbix` database has an `oauth_profile` table from another branch that this branch does not use; a database built by the script does not have it.
