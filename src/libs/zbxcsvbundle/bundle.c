/*
** Copyright (C) 2001-2026 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
*/

#define _POSIX_C_SOURCE 200809L

#include "config.h"

#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>
#include <zlib.h>

#include "zbxcommon.h"
#include "zbxcsvbundle.h"

/* POSIX ustar tar header (512 bytes) */
typedef struct
{
	char	name[100];
	char	mode[8];
	char	uid[8];
	char	gid[8];
	char	size[12];
	char	mtime[12];
	char	chksum[8];
	char	typeflag[1];
	char	linkname[100];
	char	magic[6];		/* "ustar\0" */
	char	version[2];		/* "00" */
	char	uname[32];
	char	gname[32];
	char	devmajor[8];
	char	devminor[8];
	char	prefix[155];
	char	padding[12];		/* pad to 512 bytes */
}
zbx_ustar_header_t;

#define ZBX_USTAR_HEADER_SIZE 512
#define ZBX_USTAR_BLOCK_SIZE 512

static unsigned int zbx_tar_checksum(const unsigned char *header)
{
	unsigned int sum = 0;

	for (int i = 0; i < ZBX_USTAR_HEADER_SIZE; i++)
	{
		if (i >= 148 && i < 156)
			sum += ' ';	/* checksum field treated as spaces */
		else
			sum += header[i];
	}

	return sum;
}

static void zbx_tar_format_octal(char *buf, size_t len, unsigned long long value)
{
	snprintf(buf, len, "%.*llo", (int)len - 1, value);
}

static int zbx_bundle_writer_write_padding(zbx_bundle_writer_t *bw, size_t len)
{
	unsigned char padding[ZBX_USTAR_BLOCK_SIZE];

	if (len == 0)
		return SUCCEED;

	memset(padding, 0, sizeof(padding));
	len = (len > sizeof(padding)) ? sizeof(padding) : len;

	if (gzwrite((gzFile)bw->gz_file, padding, (unsigned int)len) != (int)len)
		return FAIL;

	return SUCCEED;
}

int zbx_bundle_writer_open(zbx_bundle_writer_t *bw, const char *path)
{
	memset(bw, 0, sizeof(zbx_bundle_writer_t));
	strncpy(bw->path, path, sizeof(bw->path) - 1);

	bw->gz_file = gzopen(path, "wb");
	if (NULL == bw->gz_file)
		return FAIL;

	return SUCCEED;
}

int zbx_bundle_write_member_begin(zbx_bundle_writer_t *bw, const char *name, size_t size)
{
	zbx_ustar_header_t header;
	unsigned char *header_bytes = (unsigned char *)&header;
	unsigned int checksum;

	memset(&header, 0, sizeof(header));

	strncpy(header.name, name, sizeof(header.name) - 1);
	strcpy(header.mode, "0000644");
	strcpy(header.uid, "0000000");
	strcpy(header.gid, "0000000");
	zbx_tar_format_octal(header.size, sizeof(header.size), size);
	zbx_tar_format_octal(header.mtime, sizeof(header.mtime), time(NULL));
	strcpy(header.typeflag, "0");
	strcpy(header.magic, "ustar");
	strcpy(header.version, "00");

	checksum = zbx_tar_checksum(header_bytes);
	snprintf(header.chksum, sizeof(header.chksum), "%06o", checksum);

	if (gzwrite((gzFile)bw->gz_file, &header, sizeof(header)) != (int)sizeof(header))
		return FAIL;

	return SUCCEED;
}

int zbx_bundle_write_member_data(zbx_bundle_writer_t *bw, const void *data, size_t len)
{
	if (len == 0)
		return SUCCEED;

	if (gzwrite((gzFile)bw->gz_file, data, (unsigned int)len) != (int)len)
		return FAIL;

	return SUCCEED;
}

int zbx_bundle_write_member_end(zbx_bundle_writer_t *bw)
{
	/* Padding to 512-byte boundary */
	size_t pos = (size_t)gztell((gzFile)bw->gz_file);
	size_t offset = pos % ZBX_USTAR_BLOCK_SIZE;
	size_t padding = (offset == 0) ? 0 : (ZBX_USTAR_BLOCK_SIZE - offset);

	if (padding == 0)
		return SUCCEED;

	return zbx_bundle_writer_write_padding(bw, padding);
}

int zbx_bundle_writer_close(zbx_bundle_writer_t *bw)
{
	if (NULL == bw->gz_file)
		return FAIL;

	/* Write two 512-byte zero blocks to mark end of archive */
	unsigned char end_block[ZBX_USTAR_BLOCK_SIZE * 2];
	memset(end_block, 0, sizeof(end_block));

	if (gzwrite((gzFile)bw->gz_file, end_block, sizeof(end_block)) != (int)sizeof(end_block))
	{
		gzclose((gzFile)bw->gz_file);
		bw->gz_file = NULL;
		return FAIL;
	}

	if (Z_OK != gzclose((gzFile)bw->gz_file))
		return FAIL;

	bw->gz_file = NULL;
	return SUCCEED;
}

int zbx_bundle_reader_open(zbx_bundle_reader_t *br, const char *path)
{
	memset(br, 0, sizeof(zbx_bundle_reader_t));

	br->gz_file = gzopen(path, "rb");
	if (NULL == br->gz_file)
		return FAIL;

	return SUCCEED;
}

int zbx_bundle_read_next_header(zbx_bundle_reader_t *br, char *name_out, size_t name_size, size_t *size_out, int *eof_out)
{
	unsigned char header_bytes[ZBX_USTAR_HEADER_SIZE];
	int bytes_read;

	*eof_out = 0;

	if (name_size > 0)
		name_out[0] = '\0';

	if (NULL == br->gz_file)
		return FAIL;

	bytes_read = gzread((gzFile)br->gz_file, header_bytes, ZBX_USTAR_HEADER_SIZE);

	if (bytes_read < (int)ZBX_USTAR_HEADER_SIZE)
	{
		if (bytes_read == 0)
			*eof_out = 1;
		return FAIL;
	}

	/* Check for end-of-archive marker (two zero blocks) */
	int is_zero = 1;
	for (int i = 0; i < ZBX_USTAR_HEADER_SIZE; i++)
	{
		if (header_bytes[i] != 0)
		{
			is_zero = 0;
			break;
		}
	}

	if (is_zero)
	{
		*eof_out = 1;
		return SUCCEED;
	}

	/* Parse header */
	zbx_ustar_header_t *header = (zbx_ustar_header_t *)header_bytes;

	if (name_size > 0)
	{
		strncpy(name_out, header->name, name_size - 1);
		name_out[name_size - 1] = '\0';
	}

	char size_str[12 + 1];
	strncpy(size_str, header->size, sizeof(size_str) - 1);
	size_str[sizeof(size_str) - 1] = '\0';
	*size_out = (size_t)strtoll(size_str, NULL, 8);

	br->member_pos = 0;
	br->member_size = *size_out;
	memcpy(br->header, header_bytes, sizeof(br->header));

	return SUCCEED;
}

int zbx_bundle_read_member_data(zbx_bundle_reader_t *br, void *buf, size_t want, size_t *got)
{
	int bytes_read;

	*got = 0;

	if (NULL == br->gz_file)
		return FAIL;

	if (br->member_pos >= br->member_size)
		return SUCCEED;	/* No more data in this member */

	size_t to_read = (br->member_size - br->member_pos < want) ?
		(br->member_size - br->member_pos) : want;

	bytes_read = gzread((gzFile)br->gz_file, buf, (unsigned int)to_read);

	if (bytes_read > 0)
	{
		*got = (size_t)bytes_read;
		br->member_pos += (size_t)bytes_read;
	}

	return (bytes_read >= 0) ? SUCCEED : FAIL;
}

int zbx_bundle_reader_close(zbx_bundle_reader_t *br)
{
	if (NULL == br->gz_file)
		return FAIL;

	if (Z_OK != gzclose((gzFile)br->gz_file))
		return FAIL;

	br->gz_file = NULL;
	return SUCCEED;
}
