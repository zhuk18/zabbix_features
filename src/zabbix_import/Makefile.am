## Process this file with automake to produce Makefile.in

bin_PROGRAMS = zabbix_import

zabbix_import_SOURCES = zabbix_import.c

zabbix_import_LDADD = \
	$(top_builddir)/src/libs/zbxcsvbundle/libzbxcsvbundle.a \
	$(top_builddir)/src/libs/zbxdbschema/libzbxdbschema.a \
	$(top_builddir)/src/libs/zbxhash/libzbxhash.a \
	$(ZLIB_LIBS)

zabbix_import_LDFLAGS = $(ZLIB_LDFLAGS)

zabbix_import_CFLAGS = $(ZLIB_CFLAGS)
