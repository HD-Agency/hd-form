<?php
/**
 * Form Entries Exporter — openspout-based XLSX/CSV streaming.
 *
 * Exports entries matching current list view filters.
 * Registered as an admin-post action to avoid memory issues with large datasets.
 *
 * @package HDForm\Admin
 */

declare(strict_types=1);

namespace HDForm\Admin;

use HDForm\FormConfig;
use HDForm\Repository\FormEntryRepository;
use HDForm\Compat\Helper;
use HDForm\Plugin;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Cell;

defined( 'ABSPATH' ) || exit;

final class FormExporter {
	private const EXPORT_BATCH_SIZE = 500;

	/**
	 * Register export handler.
	 */
	public static function register(): void {
		add_action( 'admin_post_hd_export_form_entries', [ self::class, 'handleExport' ] );
	}

	/**
	 * Handle export request.
	 */
	public static function handleExport(): void {
		if ( ! current_user_can( Plugin::CAP_EXPORT_ENTRIES ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'hd-form' ), 403 );
		}

		check_admin_referer( 'hd_export_entries' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$format = sanitize_key( $_GET['export_format'] ?? 'xlsx' );

		$filters = [];
		if ( ! empty( $_GET['status'] ) ) {
			$filters['status'] = sanitize_text_field( wp_unslash( $_GET['status'] ) );
		}
		if ( ! empty( $_GET['form_type'] ) ) {
			$filters['form_type'] = sanitize_text_field( wp_unslash( $_GET['form_type'] ) );
		}
		if ( ! empty( $_GET['workflow_status'] ) && FormConfig::hasWorkflowStatuses() ) {
			$filters['workflow_status'] = sanitize_key( wp_unslash( $_GET['workflow_status'] ) );
		}
		if ( ! empty( $_GET['s'] ) ) {
			$filters['search'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
		}
		// phpcs:enable

		$repo = new FormEntryRepository();

		$filename = 'form-entries-' . gmdate( 'Y-m-d-His' );

		if ( 'csv' === $format ) {
			self::exportCsv( $repo, $filters, $filename . '.csv' );
		} else {
			self::exportXlsx( $repo, $filters, $filename . '.xlsx' );
		}

		exit;
	}

	/**
	 * Export as XLSX.
	 *
	 * openToBrowser() sets Content-Type and Content-Disposition headers automatically.
	 *
	 * @param FormEntryRepository $repo     Entry repository.
	 * @param array               $filters  Export filters.
	 * @param string              $filename Full filename with extension.
	 */
	private static function exportXlsx( FormEntryRepository $repo, array $filters, string $filename ): void {
		$writer = new XlsxWriter();

		$writer->openToBrowser( $filename );
		self::writeRowsFromRepository( $writer, $repo, $filters );
		$writer->close();
	}

	/**
	 * Export as CSV.
	 *
	 * @param FormEntryRepository $repo     Entry repository.
	 * @param array               $filters  Export filters.
	 * @param string              $filename Full filename with extension.
	 */
	private static function exportCsv( FormEntryRepository $repo, array $filters, string $filename ): void {
		$writer = new CsvWriter();

		$writer->openToBrowser( $filename );
		self::writeRowsFromRepository( $writer, $repo, $filters );
		$writer->close();
	}

	private static function writeRowsFromRepository( XlsxWriter|CsvWriter $writer, FormEntryRepository $repo, array $filters ): void {
		self::writeHeaderRow( $writer );

		// Keyset cursor: highest ID already exported (start above everything).
		$lastId = PHP_INT_MAX;

		do {
			// Streaming exports run long — keep extending the time budget
			// instead of dying at max_execution_time mid-download.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$entries = $repo->findAllBeforeId( $filters, $lastId, self::EXPORT_BATCH_SIZE );
			if ( is_wp_error( $entries ) || ! is_array( $entries ) || [] === $entries ) {
				break;
			}

			self::writeDataRows( $writer, $entries );

			$last   = end( $entries );
			$lastId = (int) ( $last['id'] ?? 0 );
		} while ( $lastId > 0 && ! connection_aborted() );
	}

	private static function writeHeaderRow( XlsxWriter|CsvWriter $writer ): void {
		$headers = [ 'ID', 'Form Type', 'Form ID', 'Status' ];
		if ( FormConfig::hasWorkflowStatuses() ) {
			$headers[] = 'Workflow Status';
		}
		$headers = array_merge(
			$headers,
			[ 'Name', 'Email', 'Phone', 'IP Address', 'Page URL', 'UTM Source', 'UTM Medium', 'UTM Campaign', 'Notes', 'Date', 'Extra Fields' ]
		);
		$writer->addRow( new Row( array_map( static fn( $h ) => Cell::fromValue( $h ), $headers ) ) );
	}

	private static function writeDataRows( XlsxWriter|CsvWriter $writer, array $entries ): void {
		$isCsv       = $writer instanceof CsvWriter;
		$hasWorkflow = FormConfig::hasWorkflowStatuses();

		foreach ( $entries as $entry ) {
			$dataFields = self::exportDataFields( $entry['data'] ?? [] );

			$cells = [
				Cell::fromValue( (int) $entry['id'] ),
				Cell::fromValue( self::exportCellValue( $entry['form_type'], $isCsv ) ),
				Cell::fromValue( self::exportCellValue( $entry['form_id'], $isCsv ) ),
				Cell::fromValue( self::exportCellValue( $entry['status'], $isCsv ) ),
			];

			if ( $hasWorkflow ) {
				$cells[] = Cell::fromValue( self::exportCellValue( self::resolveWorkflowLabel( (string) ( $entry['workflow_status'] ?? '' ) ), $isCsv ) );
			}

			$cells = array_merge(
				$cells,
				[
					Cell::fromValue( self::exportCellValue( $entry['name'], $isCsv ) ),
					Cell::fromValue( self::exportCellValue( $entry['email'], $isCsv ) ),
					Cell::fromValue( self::exportCellValue( $entry['phone'], $isCsv ) ),
					Cell::fromValue( self::exportCellValue( $entry['ip_address'], $isCsv ) ),
					Cell::fromValue( self::exportCellValue( $entry['page_url'], $isCsv ) ),
					Cell::fromValue( self::exportCellValue( $entry['utm_source'], $isCsv ) ),
					Cell::fromValue( self::exportCellValue( $entry['utm_medium'], $isCsv ) ),
					Cell::fromValue( self::exportCellValue( $entry['utm_campaign'], $isCsv ) ),
					Cell::fromValue( self::exportCellValue( $entry['notes'] ?? '', $isCsv ) ),
					Cell::fromValue( self::exportCellValue( Helper::formatDate( $entry['created_at'] ), $isCsv ) ),
					Cell::fromValue( self::exportCellValue( $dataFields, $isCsv ) ),
				]
			);

			$writer->addRow( new Row( $cells ) );
		}
	}

	/**
	 * Resolve workflow status slug to human-readable label for export.
	 *
	 * @param string $slug Workflow status slug.
	 *
	 * @return string
	 */
	private static function resolveWorkflowLabel( string $slug ): string {
		if ( '' === $slug ) {
			return '';
		}

		$status = FormConfig::getWorkflowStatusBySlug( $slug );

		return $status['label'] ?? $slug;
	}

	/**
	 * Prefix formula-like CSV cells so spreadsheet apps treat them as text.
	 */
	private static function exportCellValue( mixed $value, bool $forCsv ): mixed {
		if ( ! $forCsv || ! is_string( $value ) ) {
			return $value;
		}

		return preg_match( '/^\s*[=+\-@]/', $value ) ? "'" . $value : $value;
	}

	private static function exportDataFields( mixed $data ): string {
		if ( is_string( $data ) ) {
			$decoded = json_decode( $data, true );
			$data    = is_array( $decoded ) ? $decoded : $data;
		}

		if ( is_array( $data ) ) {
			unset( $data['__labels'], $data['__files'], $data['__geo'] );

			return wp_json_encode( $data ) ?: '';
		}

		return is_scalar( $data ) || null === $data ? (string) $data : '';
	}
}
