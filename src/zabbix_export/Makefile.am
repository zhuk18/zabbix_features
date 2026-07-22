## Process this file with automake to produce Makefile.in

bin_PROGRAMS = zabbix_export

zabbix_export_SOURCES = zabbix_export.c

zabbix_export_LDADD = \
	$(top_builddir)/src/libs/zbxcsvbundle/libzbxcsvbundle.a \
	$(top_builddir)/src/libs/zbxdbschema/libzbxdbschema.a \
	$(top_builddir)/src/libs/zbxhash/libzbxhash.a \
	$(top_builddir)/src/libs/zbxregexp/libzbxregexp.a \
	$(top_builddir)/src/libs/zbxnix/libzbxnix.a \
	$(top_builddir)/src/libs/zbxalgo/libzbxalgo.a \
	$(top_builddir)/src/libs/zbxstr/libzbxstr.a \
	$(top_builddir)/src/libs/zbxnum/libzbxnum.a \
	$(top_builddir)/src/libs/zbxtime/libzbxtime.a \
	$(top_builddir)/src/libs/zbxcommon/libzbxcommon.a \
	$(ZLIB_LIBS)

zabbix_export_LDFLAGS = $(ZLIB_LDFLAGS)

zabbix_export_CFLAGS = $(ZLIB_CFLAGS)
