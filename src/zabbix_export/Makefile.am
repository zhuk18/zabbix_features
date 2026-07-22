## Process this file with automake to produce Makefile.in

bin_PROGRAMS = zabbix_export

zabbix_export_SOURCES = zabbix_export.c

zabbix_export_LDADD = \
	$(top_builddir)/src/libs/zbxcsvbundle/libzbxcsvbundle.a \
	$(top_builddir)/src/libs/zbxdbschema/libzbxdbschema.a \
	$(top_builddir)/src/libs/zbxhash/libzbxhash.a \
	$(ZLIB_LIBS)

zabbix_export_LDFLAGS = $(ZLIB_LDFLAGS)

zabbix_export_CFLAGS = $(ZLIB_CFLAGS)
