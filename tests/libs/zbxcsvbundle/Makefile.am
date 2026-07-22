## Process this file with automake to produce Makefile.in

check_PROGRAMS = csv_codec_test

csv_codec_test_SOURCES = csv_codec_test.c

csv_codec_test_LDADD = $(top_builddir)/src/libs/zbxcsvbundle/libzbxcsvbundle.a \
	$(top_builddir)/src/libs/zbxcommon/libzbxcommon.a

csv_codec_test_CFLAGS = $(ZLIB_CFLAGS)

TESTS = $(check_PROGRAMS)
