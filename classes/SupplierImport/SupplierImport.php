<?php
/**
 * Supplier CSV Import functionality
 *
 * @package     SereniSoft\AtumEnhancer\SupplierImport
 * @author      SereniSoft
 * @copyright   2025 SereniSoft
 *
 * @since 1.0.0
 */

namespace SereniSoft\AtumEnhancer\SupplierImport;

defined( 'ABSPATH' ) || die;

use Atum\Suppliers\Supplier;
use Atum\Suppliers\Suppliers;

class SupplierImport {

	/**
	 * The singleton instance holder
	 *
	 * @var SupplierImport
	 */
	private static $instance;

	/**
	 * CSV delimiter
	 */
	const CSV_DELIMITER = ',';

	/**
	 * Column mapping from CSV to ATUM supplier fields
	 * Supports both Norwegian (legacy) and English (export format) column names
	 *
	 * @var array
	 */
	private $column_mapping = array(
		// Code.
		'Leverandørnummer'       => 'code',
		'Code'                   => 'code',
		// Name.
		'Navn'                   => 'name',
		'Name'                   => 'name',
		// Tax Number.
		'Organisasjonsnummer'    => 'tax_number',
		'Tax Number'             => 'tax_number',
		// Phone.
		'Telefonnummer'          => 'phone',
		'Phone'                  => 'phone',
		// Fax.
		'Faksnummer'             => 'fax',
		'Fax'                    => 'fax',
		// General Email.
		'E-postadresse'          => 'general_email',
		'General Email'          => 'general_email',
		// Ordering Email.
		'Ordering Email'         => 'ordering_email',
		// Website.
		'Website'                => 'website',
		// Ordering URL.
		'Ordering URL'           => 'ordering_url',
		// Address.
		'Address'                => 'address',
		'Postadresse'            => 'address',
		// Address 2.
		'Address 2'              => 'address_2',
		// City.
		'Postadresse Sted'       => 'city',
		'City'                   => 'city',
		// State.
		'State'                  => 'state',
		// Zip Code.
		'Postadresse Postnr.'    => 'zip_code',
		'Zip Code'               => 'zip_code',
		// Country.
		'Postadresse Land'       => 'country',
		'Country'                => 'country',
		// Currency.
		'Currency'               => 'currency',
		// Lead Time.
		'Lead Time (days)'       => 'lead_time',
		'Lead Time'              => 'lead_time',
		// Discount.
		'Discount (%)'           => 'discount',
		'Discount'               => 'discount',
		// Tax Rate.
		'Tax Rate (%)'           => 'tax_rate',
		'Tax Rate'               => 'tax_rate',
		// Location.
		'Location'               => 'location',
		// Description.
		'Description'            => 'description',
	);

	/**
	 * SupplierImport constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() {

		// Register AJAX handlers.
		add_action( 'wp_ajax_sae_preview_suppliers', array( $this, 'ajax_preview_suppliers' ) );
		add_action( 'wp_ajax_sae_import_suppliers', array( $this, 'ajax_import_suppliers' ) );

	}

	/**
	 * AJAX handler for supplier preview
	 *
	 * @since 1.0.0
	 */
	public function ajax_preview_suppliers() {

		// Check nonce.
		if ( ! check_ajax_referer( 'sae_import_suppliers', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import suppliers.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Check if file was uploaded.
		if ( empty( $_FILES['csv_file'] ) || empty( $_FILES['csv_file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'serenisoft-atum-enhancer' ) ) );
		}

		$file = $_FILES['csv_file']['tmp_name'];

		// Validate file type.
		$file_type = wp_check_filetype( $_FILES['csv_file']['name'] );
		if ( 'csv' !== $file_type['ext'] && 'text/csv' !== $file_type['type'] ) {
			wp_send_json_error( array( 'message' => __( 'Invalid file type. Please upload a CSV file.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Analyze the CSV file without importing.
		$result = $this->analyze_csv( $file );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );

	}

	/**
	 * AJAX handler for supplier import
	 *
	 * @since 1.0.0
	 */
	public function ajax_import_suppliers() {

		// Check nonce.
		if ( ! check_ajax_referer( 'sae_import_suppliers', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import suppliers.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Check if file was uploaded.
		if ( empty( $_FILES['csv_file'] ) || empty( $_FILES['csv_file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'serenisoft-atum-enhancer' ) ) );
		}

		$file = $_FILES['csv_file']['tmp_name'];

		// Validate file type.
		$file_type = wp_check_filetype( $_FILES['csv_file']['name'] );
		if ( 'csv' !== $file_type['ext'] && 'text/csv' !== $file_type['type'] ) {
			wp_send_json_error( array( 'message' => __( 'Invalid file type. Please upload a CSV file.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Process the CSV file.
		$result = $this->process_csv( $file );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );

	}

	/**
	 * Process CSV file and import suppliers
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Path to CSV file.
	 *
	 * @return array|WP_Error Result array or error.
	 */
	public function process_csv( $file ) {

		$handle = fopen( $file, 'r' );
		if ( false === $handle ) {
			return new \WP_Error( 'file_read_error', __( 'Could not read the CSV file.', 'serenisoft-atum-enhancer' ) );
		}

		// Read header row.
		$header = fgetcsv( $handle, 0, self::CSV_DELIMITER );
		if ( false === $header ) {
			fclose( $handle );
			return new \WP_Error( 'invalid_csv', __( 'Invalid CSV file format.', 'serenisoft-atum-enhancer' ) );
		}

		// Clean BOM from first column if present.
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );

		// Build column index map.
		$column_indices = $this->build_column_indices( $header );

		$imported = 0;
		$skipped  = 0;
		$errors   = array();
		$row_num  = 1;

		while ( ( $row = fgetcsv( $handle, 0, self::CSV_DELIMITER ) ) !== false ) {
			$row_num++;

			// Skip empty rows.
			if ( empty( array_filter( $row ) ) ) {
				continue;
			}

			$result = $this->import_supplier_row( $row, $column_indices, $row_num );

			if ( is_wp_error( $result ) ) {
				if ( 'duplicate_supplier' === $result->get_error_code() ) {
					$skipped++;
				} else {
					$errors[] = sprintf(
						/* translators: 1: row number, 2: error message */
						__( 'Row %1$d: %2$s', 'serenisoft-atum-enhancer' ),
						$row_num,
						$result->get_error_message()
					);
				}
			} else {
				$imported++;
			}
		}

		fclose( $handle );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
			'message'  => sprintf(
				/* translators: 1: imported count, 2: skipped count */
				__( 'Import complete. %1$d suppliers imported, %2$d skipped (duplicates).', 'serenisoft-atum-enhancer' ),
				$imported,
				$skipped
			),
		);

	}

	/**
	 * Analyze CSV file without importing (for preview)
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Path to CSV file.
	 *
	 * @return array|WP_Error Analysis result or error.
	 */
	public function analyze_csv( $file ) {

		$handle = fopen( $file, 'r' );
		if ( false === $handle ) {
			return new \WP_Error( 'file_read_error', __( 'Could not read the CSV file.', 'serenisoft-atum-enhancer' ) );
		}

		// Read header row.
		$header = fgetcsv( $handle, 0, self::CSV_DELIMITER );
		if ( false === $header ) {
			fclose( $handle );
			return new \WP_Error( 'invalid_csv', __( 'Invalid CSV file format.', 'serenisoft-atum-enhancer' ) );
		}

		// Clean BOM from first column if present.
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );

		// Build column index map.
		$column_indices = $this->build_column_indices( $header );

		$rows        = array();
		$will_import = 0;
		$will_skip   = 0;
		$row_num     = 1;

		while ( ( $row = fgetcsv( $handle, 0, self::CSV_DELIMITER ) ) !== false ) {
			$row_num++;

			// Skip empty rows.
			if ( empty( array_filter( $row ) ) ) {
				continue;
			}

			$row_data = $this->analyze_supplier_row( $row, $column_indices );

			if ( 'import' === $row_data['status'] ) {
				$will_import++;
			} else {
				$will_skip++;
			}

			$rows[] = $row_data;
		}

		fclose( $handle );

		return array(
			'rows'        => $rows,
			'will_import' => $will_import,
			'will_skip'   => $will_skip,
			'total'       => count( $rows ),
		);

	}

	/**
	 * Analyze a single supplier row (without importing)
	 *
	 * @since 1.0.0
	 *
	 * @param array $row            CSV row data.
	 * @param array $column_indices Column indices.
	 *
	 * @return array Row analysis with status.
	 */
	private function analyze_supplier_row( $row, $column_indices ) {

		// Get supplier name.
		$name = isset( $column_indices['name'] ) && isset( $row[ $column_indices['name'] ] )
			? trim( $row[ $column_indices['name'] ] )
			: '';

		// Get supplier code.
		$code = isset( $column_indices['code'] ) && isset( $row[ $column_indices['code'] ] )
			? trim( $row[ $column_indices['code'] ] )
			: '';

		// Determine status.
		$status = 'import';
		$reason = '';

		if ( empty( $name ) ) {
			$status = 'error';
			$reason = __( 'Missing name', 'serenisoft-atum-enhancer' );
		} elseif ( $this->supplier_exists( $code, $name ) ) {
			$status = 'skip';
			$reason = __( 'Duplicate', 'serenisoft-atum-enhancer' );
		}

		return array(
			'code'   => $code,
			'name'   => $name,
			'status' => $status,
			'reason' => $reason,
		);

	}

	/**
	 * Build column indices from header row
	 *
	 * @since 1.0.0
	 *
	 * @param array $header Header row.
	 *
	 * @return array Column indices.
	 */
	private function build_column_indices( $header ) {

		$indices = array();

		foreach ( $header as $index => $column_name ) {
			$column_name = trim( $column_name );

			// Check standard mapping (supports both Norwegian and English column names).
			if ( isset( $this->column_mapping[ $column_name ] ) ) {
				$indices[ $this->column_mapping[ $column_name ] ] = $index;
			}
		}

		return $indices;

	}

	/**
	 * Import a single supplier row
	 *
	 * @since 1.0.0
	 *
	 * @param array $row            CSV row data.
	 * @param array $column_indices Column indices.
	 * @param int   $row_num        Row number for error reporting.
	 *
	 * @return int|WP_Error Supplier ID on success, WP_Error on failure.
	 */
	private function import_supplier_row( $row, $column_indices, $row_num ) {

		// Get supplier name (required).
		$name = isset( $column_indices['name'] ) && isset( $row[ $column_indices['name'] ] )
			? trim( $row[ $column_indices['name'] ] )
			: '';

		if ( empty( $name ) ) {
			return new \WP_Error( 'missing_name', __( 'Supplier name is required.', 'serenisoft-atum-enhancer' ) );
		}

		// Get supplier code.
		$code = isset( $column_indices['code'] ) && isset( $row[ $column_indices['code'] ] )
			? trim( $row[ $column_indices['code'] ] )
			: '';

		// Check for duplicates.
		if ( $this->supplier_exists( $code, $name ) ) {
			return new \WP_Error( 'duplicate_supplier', __( 'Supplier already exists.', 'serenisoft-atum-enhancer' ) );
		}

		// Create new supplier.
		$supplier = new Supplier();
		$supplier->set_name( $name );

		// Set all other fields.
		$field_setters = array(
			'code'           => 'set_code',
			'tax_number'     => 'set_tax_number',
			'phone'          => 'set_phone',
			'fax'            => 'set_fax',
			'general_email'  => 'set_general_email',
			'ordering_email' => 'set_ordering_email',
			'website'        => 'set_website',
			'ordering_url'   => 'set_ordering_url',
			'address'        => 'set_address',
			'address_2'      => 'set_address_2',
			'city'           => 'set_city',
			'state'          => 'set_state',
			'zip_code'       => 'set_zip_code',
			'country'        => 'set_country',
			'currency'       => 'set_currency',
			'lead_time'      => 'set_lead_time',
			'discount'       => 'set_discount',
			'tax_rate'       => 'set_tax_rate',
			'location'       => 'set_location',
		);

		foreach ( $field_setters as $field => $setter ) {
			if ( isset( $column_indices[ $field ] ) && isset( $row[ $column_indices[ $field ] ] ) ) {
				$value = trim( $row[ $column_indices[ $field ] ] );
				if ( ! empty( $value ) ) {
					$supplier->$setter( $value );
				}
			}
		}

		// Save supplier.
		$supplier_id = $supplier->save();

		if ( is_wp_error( $supplier_id ) ) {
			return $supplier_id;
		}

		return $supplier_id;

	}

	/**
	 * Check if a supplier already exists by code or name
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Supplier code.
	 * @param string $name Supplier name.
	 *
	 * @return bool True if supplier exists.
	 */
	private function supplier_exists( $code, $name ) {

		global $wpdb;

		// Check by code if provided.
		if ( ! empty( $code ) ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = %s
				AND p.post_status = 'publish'
				AND pm.meta_key = '_code'
				AND pm.meta_value = %s
				LIMIT 1",
				Suppliers::POST_TYPE,
				$code
			) );

			if ( $exists ) {
				return true;
			}
		}

		// Check by name.
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type = %s
			AND post_status = 'publish'
			AND post_title = %s
			LIMIT 1",
			Suppliers::POST_TYPE,
			$name
		) );

		return ! empty( $exists );

	}


	/*******************
	 * Instance methods
	 *******************/

	/**
	 * Cannot be cloned
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Cloning is not allowed.', 'serenisoft-atum-enhancer' ), '1.0.0' );
	}

	/**
	 * Cannot be serialized
	 */
	public function __sleep() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Serialization is not allowed.', 'serenisoft-atum-enhancer' ), '1.0.0' );
	}

	/**
	 * Get Singleton instance
	 *
	 * @return SupplierImport instance
	 */
	public static function get_instance() {

		if ( ! ( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;

	}

}
