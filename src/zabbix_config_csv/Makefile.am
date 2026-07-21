## Process this file with automake to produce Makefile.in

bin_PROGRAMS = zabbix_config_csv

zabbix_config_csv_SOURCES = zabbix_config_csv.c

zabbix_config_csv_LDADD = \
	$(top_builddir)/src/libs/zbxdbupgrade/libzbxdbupgrade.a \
	$(top_builddir)/src/libs/zbxdbhigh/libzbxdbhigh.a \
	$(top_builddir)/src/libs/zbxdbwrap/libzbxdbwrap.a \
	$(top_builddir)/src/libs/zbxdb/libzbxdb.a \
	$(top_builddir)/src/libs/zbxdbschema/libzbxdbschema.a \
	$(top_builddir)/src/libs/zbxhash/libzbxhash.a \
	$(top_builddir)/src/libs/zbxcrypto/libzbxcrypto.a \
	$(top_builddir)/src/libs/zbxcommon/libzbxcommon.a \
	$(top_builddir)/src/libs/zbxcomms/libzbxcomms.a \
	$(top_builddir)/src/libs/zbxcommshigh/libzbxcommshigh.a \
	$(top_builddir)/src/libs/zbxjson/libzbxjson.a \
	$(top_builddir)/src/libs/zbxcfg/libzbxcfg.a \
	$(top_builddir)/src/libs/zbxlog/libzbxlog.a \
	$(top_builddir)/src/libs/zbxnix/libzbxnix.a \
	$(top_builddir)/src/libs/zbxthreads/libzbxthreads.a \
	$(top_builddir)/src/libs/zbxalgo/libzbxalgo.a \
	$(top_builddir)/src/libs/zbxstr/libzbxstr.a \
	$(top_builddir)/src/libs/zbxversion/libzbxversion.a \
	$(top_builddir)/src/libs/zbxgetopt/libzbxgetopt.a

zabbix_config_csv_LDADD += $(ZLIB_LIBS)

zabbix_config_csv_LDFLAGS = $(ZLIB_LDFLAGS) $(TLS_LDFLAGS)

zabbix_config_csv_CFLAGS = $(ZLIB_CFLAGS) $(TLS_CFLAGS)
