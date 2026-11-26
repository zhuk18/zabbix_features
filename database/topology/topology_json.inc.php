<?php
/**
 * SQL conditions on the JSON `attrs` of the topology tables that give the same result on MariaDB and on MySQL 8.4 (topology-1-model.md §18, 2.111; task 133).
 *
 * The difference: `JSON_EXTRACT(attrs, '$.if_index') = ?` with a string parameter (PDO sends every bound value as a string) is true on MariaDB (`JSON_EXTRACT` gives text) and false on
 * MySQL (a JSON number is not equal to a string). `JSON_EXTRACT(attrs, '$.pseudo') = false` has the same problem with a JSON boolean. **Rule: a JSON value is never compared with a value of
 * another type.** The JSON value is read with the type that the comparison needs, and the other side is a value of that type:
 *   - a number: `CAST(JSON_UNQUOTE(JSON_EXTRACT(col, path)) AS SIGNED)` (a number, whatever the type of the parameter that it is compared with);
 *   - a text: `JSON_UNQUOTE(JSON_EXTRACT(col, path))` (an unquoted string);
 *   - a boolean: the text `true` or `false` of the unquoted value;
 *   - exists: `JSON_EXTRACT(col, path) IS [NOT] NULL` (no comparison).
 * Every SQL condition on a JSON value in the topology code is made here (`test_json_conditions.php` fails for a raw `JSON_EXTRACT` outside this file). The column and the path are checked:
 * they are constants of the code, never input.
 */
final class TopologyJson {

	private static function expression(string $column, string $path): string {
		if (preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/D', $column) !== 1) {
			throw new InvalidArgumentException('not a column: '.$column);
		}
		if (preg_match('/^\$(\.[A-Za-z_][A-Za-z0-9_]*)+$/D', $path) !== 1) {
			throw new InvalidArgumentException('not a JSON path of keys: '.$path);
		}

		return 'JSON_EXTRACT('.$column.", '".$path."')";
	}

	/** The JSON value as a number (NULL when the key is missing or is not a number). */
	public static function number(string $column, string $path): string {
		return 'CAST(JSON_UNQUOTE('.self::expression($column, $path).') AS SIGNED)';
	}

	/** The JSON value as an unquoted string (NULL when the key is missing). */
	public static function text(string $column, string $path): string {
		return 'JSON_UNQUOTE('.self::expression($column, $path).')';
	}

	/** The JSON boolean is true (false when it is false or missing). */
	public static function isTrue(string $column, string $path): string {
		return '('.self::text($column, $path)." = 'true')";
	}

	/** The JSON boolean is false (false when it is true or missing). */
	public static function isFalse(string $column, string $path): string {
		return '('.self::text($column, $path)." = 'false')";
	}

	/** The key is present (a JSON null counts as present). */
	public static function exists(string $column, string $path): string {
		return '('.self::expression($column, $path).' IS NOT NULL)';
	}

	/** The key is missing. */
	public static function missing(string $column, string $path): string {
		return '('.self::expression($column, $path).' IS NULL)';
	}

	/**
	 * The condition "the JSON value equals $value" by the type of the value, and its parameter: an integer is compared as a number, a string as an unquoted string, a boolean as a boolean, null
	 * as a missing key. A float is refused (its text differs between the servers).
	 *
	 * @return array  [the SQL with one `?` (none for a boolean or null), [the parameters]]
	 */
	public static function equals(string $column, string $path, $value): array {
		if ($value === null) {
			return [self::missing($column, $path), []];
		}
		if (is_bool($value)) {
			return [$value ? self::isTrue($column, $path) : self::isFalse($column, $path), []];
		}
		if (is_int($value)) {
			return [self::number($column, $path).' = ?', [$value]];
		}
		if (is_string($value)) {
			return [self::text($column, $path).' = ?', [$value]];
		}

		throw new InvalidArgumentException('a JSON value is compared with an integer, a string, a boolean or null, not with '.gettype($value));
	}
}
