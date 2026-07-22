## Process this file with automake to produce Makefile.in

check_PROGRAMS = csv_codec_test bundle_test

csv_codec_test_SOURCES = csv_codec_test.c

csv_codec_test_LDADD = $(top_builddir)/src/libs/zbxcsvbundle/libzbxcsvbundle.a \
	$(top_builddir)/src/libs/zbxcommon/libzbxcommon.a

csv_codec_test_CFLAGS = $(ZLIB_CFLAGS)

bundle_test_SOURCES = bundle_test.c

bundle_test_LDADD = $(top_builddir)/src/libs/zbxcsvbundle/libzbxcsvbundle.a \
	$(top_builddir)/src/libs/zbxcommon/libzbxcommon.a

bundle_test_CFLAGS = $(ZLIB_CFLAGS)

bundle_test_LDFLAGS = $(ZLIB_LDFLAGS)

TESTS = $(check_PROGRAMS)
