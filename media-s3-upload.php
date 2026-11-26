<?php
/**
 * Plugin Name: CT Media S3 Upload
 * Plugin URI:  https://github.com/congtoan/ct-media-s3-upload
 * Description: Mirrors every WordPress media upload (original + all thumbnail sizes) to an extra S3-compatible bucket (e.g. Linode Object Storage) and deletes it there when the attachment is deleted. Works alongside Media Cloud or on its own. Does not change the database.
 * Version:     1.7.0
 * Author:      Cong Toan
 * Author URI:  https://github.com/congtoan
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 7.4
 * Text Domain: ct-media-s3-upload
 * Domain Path: /languages
 *
 * Configuration (recommended): add to wp-config.php
 *   define( 'CT_MEDIA_S3_ENDPOINT',   'https://us-ord-1.linodeobjects.com' );
 *   define( 'CT_MEDIA_S3_REGION',     'us-ord-1' );
 *   define( 'CT_MEDIA_S3_ACCESS_KEY', '...' );
 *   define( 'CT_MEDIA_S3_SECRET_KEY', '...' );
 *   define( 'CT_MEDIA_S3_BUCKET',     'your-bucket' );
 * Legacy: a config-s3.php file next to this file defining S3_ENDPOINT, S3_REGION,
 * S3_ACCESS_KEY, S3_SECRET_KEY, S3_BUCKET is still supported (see config-s3.sample.php).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load configuration: wp-config.php constants first, then legacy config-s3.php.
 * Returns an array of settings, or a string describing what is missing.
 *
 * @return array|string
 */
function ct_media_s3_upload_config() {
	$legacy_file = __DIR__ . '/config-s3.php';
	if ( ! defined( 'CT_MEDIA_S3_ACCESS_KEY' ) && file_exists( $legacy_file ) ) {
		require_once $legacy_file;
	}

	$keys     = [ 'ENDPOINT', 'REGION', 'ACCESS_KEY', 'SECRET_KEY', 'BUCKET' ];
	$settings = [];
	$missing  = [];
	foreach ( $keys as $k ) {
		if ( defined( "CT_MEDIA_S3_{$k}" ) ) {
			$settings[ $k ] = constant( "CT_MEDIA_S3_{$k}" );
		} elseif ( defined( "S3_{$k}" ) ) { // legacy config-s3.php
			$settings[ $k ] = constant( "S3_{$k}" );
		} else {
			$missing[] = "CT_MEDIA_S3_{$k}";
		}
	}
	if ( $missing ) {
		return implode( ', ', $missing );
	}

	$settings['ACL']        = defined( 'CT_MEDIA_S3_ACL' ) ? CT_MEDIA_S3_ACL : 'public-read';
	$settings['PATH_STYLE'] = defined( 'CT_MEDIA_S3_PATH_STYLE' ) ? (bool) CT_MEDIA_S3_PATH_STYLE : true;
	return $settings;
}

add_action( 'init', function () {
	load_plugin_textdomain( 'ct-media-s3-upload', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * Show a notice in wp-admin instead of breaking the site when something is missing.
 * Strings are translated when the notice is displayed (after init), not at load time.
 *
 * @param string $problem 'vendor' or 'config'
 * @param string $detail  e.g. the list of missing constants
 */
function ct_media_s3_upload_admin_notice( $problem, $detail = '' ) {
	add_action( 'admin_notices', function () use ( $problem, $detail ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( $problem === 'vendor' ) {
			$message = __( 'vendor/autoload.php not found. Run "composer install" in the plugin folder, or install the release zip that includes vendor/.', 'ct-media-s3-upload' );
		} else {
			/* translators: %s: comma-separated list of missing constants, e.g. CT_MEDIA_S3_BUCKET. */
			$message = sprintf( __( 'Configuration incomplete, missing: %s. Define the CT_MEDIA_S3_* constants in wp-config.php (see README).', 'ct-media-s3-upload' ), $detail );
		}
		echo '<div class="notice notice-error"><p><strong>CT Media S3 Upload:</strong> ' . esc_html( $message ) . '</p></div>';
	} );
}

$ct_media_s3_autoload = __DIR__ . '/vendor/autoload.php';
if ( ! file_exists( $ct_media_s3_autoload ) ) {
	ct_media_s3_upload_admin_notice( 'vendor' );
	return;
}
require_once $ct_media_s3_autoload;

$ct_media_s3_config = ct_media_s3_upload_config();
if ( ! is_array( $ct_media_s3_config ) ) {
	ct_media_s3_upload_admin_notice( 'config', $ct_media_s3_config );
	return;
}

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class CT_Media_S3_Upload {
	private $s3;
	private $bucket;
	private $acl;
	private $files_to_delete = []; // keys collected before an attachment is deleted

	public function __construct( array $config ) {
		$this->bucket = $config['BUCKET'];
		$this->acl    = $config['ACL'];
		$this->s3     = new S3Client( [
			'version'                 => 'latest',
			'region'                  => $config['REGION'],
			'endpoint'                => $config['ENDPOINT'],
			'use_path_style_endpoint' => $config['PATH_STYLE'],
			'credentials'             => [
				'key'    => $config['ACCESS_KEY'],
				'secret' => $config['SECRET_KEY'],
			],
		] );

		// Upload when an attachment is created/updated.
		// Priority 20: runs BEFORE Media Cloud (priority 1000), which may delete the local file after offloading.
		add_filter( 'wp_update_attachment_metadata', [ $this, 'upload_to_s3' ], 20, 2 );

		// Remember the keys before the attachment (and its metadata) is deleted.
		add_action( 'before_delete_post', [ $this, 'cache_files_before_delete' ], 10 );

		// Delete from the bucket when the attachment is deleted.
		add_action( 'delete_attachment', [ $this, 'delete_from_s3' ], 20 );
	}

	public function upload_to_s3( $metadata, $attachment_id ) {
		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		// Original file
		$file = get_attached_file( $attachment_id );
		if ( file_exists( $file ) ) {
			$this->send_file( $file, $base_dir );
		}

		// Every generated size
		if ( ! empty( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				$size_file = path_join( dirname( $file ), $size['file'] );
				if ( file_exists( $size_file ) ) {
					$this->send_file( $size_file, $base_dir );
				}
			}
		}

		return $metadata;
	}

	/**
	 * Collect the object keys of an attachment before it is deleted.
	 */
	public function cache_files_before_delete( $post_id ) {
		if ( get_post_type( $post_id ) !== 'attachment' ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		$file     = get_attached_file( $post_id );
		$metadata = wp_get_attachment_metadata( $post_id );

		$keys_to_delete = [];

		if ( $file ) {
			$key              = str_replace( $base_dir, '', $file );
			$keys_to_delete[] = ltrim( $key, '/\\' );
		}

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! isset( $size['file'] ) ) {
					continue;
				}
				$size_file        = path_join( dirname( $file ), $size['file'] );
				$key              = str_replace( $base_dir, '', $size_file );
				$keys_to_delete[] = ltrim( $key, '/\\' );
			}
		}

		if ( ! empty( $keys_to_delete ) ) {
			$this->files_to_delete[ $post_id ] = $keys_to_delete;
		}
	}

	/**
	 * Delete the attachment's objects from the bucket.
	 * "Fake delete" (filter media_overwrite_is_fake_delete): only thumbnails are deleted, the main file is kept.
	 */
	public function delete_from_s3( $attachment_id ) {
		$is_fake_delete = apply_filters( 'media_overwrite_is_fake_delete', false );

		if ( $is_fake_delete ) {
			error_log( 'S3 Delete: Fake delete detected for attachment ' . $attachment_id . ' - will preserve main file' );
		}

		if ( isset( $this->files_to_delete[ $attachment_id ] ) ) {
			$keys_to_delete = $this->files_to_delete[ $attachment_id ];
			unset( $this->files_to_delete[ $attachment_id ] );
		} else {
			$keys_to_delete = $this->get_files_from_database( $attachment_id );
		}

		$deleted_count = 0;
		$skipped_count = 0;

		foreach ( $keys_to_delete as $key ) {
			if ( empty( $key ) ) {
				continue;
			}

			$normalized_key = str_replace( '\\', '/', $key );

			if ( $is_fake_delete && ! $this->is_thumbnail_key( $normalized_key ) ) {
				error_log( 'S3 Delete: Preserving main file - ' . $normalized_key );
				$skipped_count++;
				continue;
			}

			try {
				$this->s3->deleteObject( [
					'Bucket' => $this->bucket,
					'Key'    => $normalized_key,
				] );
				error_log( 'S3 Delete Success: ' . $normalized_key );
				$deleted_count++;
			} catch ( AwsException $e ) {
				error_log( 'S3 Delete Error: ' . $e->getMessage() . ' - Key: ' . $normalized_key );
			}
		}

		if ( $is_fake_delete ) {
			error_log( "S3 Delete: Fake delete completed - Deleted: {$deleted_count}, Skipped (main file): {$skipped_count}" );
		} else {
			error_log( "S3 Delete: Real delete completed - Deleted: {$deleted_count}" );
		}
	}

	/**
	 * Thumbnails contain "-{width}x{height}." in the file name, e.g. image-300x200.jpg
	 */
	private function is_thumbnail_key( string $key ): bool {
		return (bool) preg_match( '/-\d+x\d+\./', $key );
	}

	/**
	 * Fallback: read the keys straight from the database.
	 */
	private function get_files_from_database( $attachment_id ) {
		global $wpdb;

		$keys_to_delete = [];

		$file_path = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attached_file'",
			$attachment_id
		) );

		if ( $file_path ) {
			$keys_to_delete[] = ltrim( $file_path, '/\\' );
		}

		$metadata = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attachment_metadata'",
			$attachment_id
		) );

		if ( $metadata ) {
			$metadata = maybe_unserialize( $metadata );
			if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				$file_dir = dirname( $file_path );
				foreach ( $metadata['sizes'] as $size ) {
					if ( ! isset( $size['file'] ) ) {
						continue;
					}
					$keys_to_delete[] = ltrim( $file_dir . '/' . $size['file'], '/\\' );
				}
			}
		}

		return $keys_to_delete;
	}

	private function send_file( $file, $base_dir ) {
		$key = str_replace( $base_dir, '', $file );
		$key = ltrim( $key, '/\\' );
		$key = str_replace( '\\', '/', $key );

		try {
			$this->s3->putObject( [
				'Bucket'      => $this->bucket,
				'Key'         => $key,
				'SourceFile'  => $file,
				'ACL'         => $this->acl,
				'ContentType' => mime_content_type( $file ),
			] );
			error_log( 'S3 Upload Success: ' . $key );
		} catch ( AwsException $e ) {
			error_log( 'S3 Upload Error: ' . $e->getMessage() . ' - Key: ' . $key );
		}
	}
}

new CT_Media_S3_Upload( $ct_media_s3_config );
