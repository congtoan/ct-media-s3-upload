<?php
/**
 * Plugin Name: Cong Toan Media S3 Upload
 * Description: Upload file media WordPress lên Linode Object Storage (S3 compatible). Không thay đổi database.
 * Version: 1.5
 * Author: congtoan
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/config-s3.php';
require_once __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class CT_Media_S3_Upload {
    private $s3;
    private $files_to_delete = []; // Cache file info trước khi delete

    public function __construct() {
        $this->s3 = new S3Client([
            'version'     => 'latest',
            'region'      => S3_REGION,
            'endpoint'    => S3_ENDPOINT,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key'    => S3_ACCESS_KEY,
                'secret' => S3_SECRET_KEY,
            ],
        ]);

        // Upload khi tạo/cập nhật attachment
        add_filter('wp_update_attachment_metadata', [$this, 'upload_to_s3'], 20, 2);
        
        // Cache thông tin file trước khi xóa
        add_action('before_delete_post', [$this, 'cache_files_before_delete'], 10);
        
        // Xóa khỏi S3 khi attachment bị xóa
        add_action('delete_attachment', [$this, 'delete_from_s3'], 20);
    }

    public function upload_to_s3($metadata, $attachment_id) {
        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];

        // File gốc
        $file = get_attached_file($attachment_id);
        if (file_exists($file)) {
            $this->send_file($file, $base_dir);
        }

        // Các size khác
        if (!empty($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                $size_file = path_join(dirname($file), $size['file']);
                if (file_exists($size_file)) {
                    $this->send_file($size_file, $base_dir);
                }
            }
        }

        return $metadata;
    }

    /**
     * Cache thông tin file trước khi attachment bị xóa
     */
    public function cache_files_before_delete($post_id) {
        // Chỉ xử lý attachment
        if (get_post_type($post_id) !== 'attachment') {
            return;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];
        
        $file = get_attached_file($post_id);
        $metadata = wp_get_attachment_metadata($post_id);

        $keys_to_delete = [];

        // File gốc
        if ($file) {
            $key = str_replace($base_dir, '', $file);
            $keys_to_delete[] = ltrim($key, '/\\');
        }

        // Thumbnails
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                if (!isset($size['file'])) continue;
                
                $size_file = path_join(dirname($file), $size['file']);
                $key = str_replace($base_dir, '', $size_file);
                $keys_to_delete[] = ltrim($key, '/\\');
            }
        }

        // Cache lại để dùng trong delete_from_s3
        if (!empty($keys_to_delete)) {
            $this->files_to_delete[$post_id] = $keys_to_delete;
        }
    }

    public function delete_from_s3($attachment_id) {
        $keys_to_delete = [];

        // Lấy từ cache trước
        if (isset($this->files_to_delete[$attachment_id])) {
            $keys_to_delete = $this->files_to_delete[$attachment_id];
            unset($this->files_to_delete[$attachment_id]); // Clear cache
        } else {
            // Fallback: thử lấy từ database (có thể không còn)
            $keys_to_delete = $this->get_files_from_database($attachment_id);
        }

        // Xóa từng file trên S3
        foreach ($keys_to_delete as $key) {
            if (empty($key)) continue;
            
            try {
                $this->s3->deleteObject([
                    'Bucket' => S3_BUCKET,
                    'Key'    => str_replace('\\', '/', $key), // Normalize path cho S3
                ]);
                error_log('S3 Delete Success: ' . $key);
            } catch (AwsException $e) {
                error_log('S3 Delete Error: ' . $e->getMessage() . ' - Key: ' . $key);
            }
        }
    }

    /**
     * Fallback: Lấy thông tin file từ database
     */
    private function get_files_from_database($attachment_id) {
        global $wpdb;
        
        $keys_to_delete = [];
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        // Lấy file path từ postmeta
        $file_path = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attached_file'",
            $attachment_id
        ));

        if ($file_path) {
            $keys_to_delete[] = ltrim($file_path, '/\\');
        }

        // Lấy metadata từ postmeta
        $metadata = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attachment_metadata'",
            $attachment_id
        ));

        if ($metadata) {
            $metadata = maybe_unserialize($metadata);
            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                $file_dir = dirname($file_path);
                
                foreach ($metadata['sizes'] as $size) {
                    if (!isset($size['file'])) continue;
                    
                    $size_key = $file_dir . '/' . $size['file'];
                    $keys_to_delete[] = ltrim($size_key, '/\\');
                }
            }
        }

        return $keys_to_delete;
    }

    private function send_file($file, $base_dir) {
        $key = str_replace($base_dir, '', $file);
        $key = ltrim($key, '/\\'); // Remove leading slashes
        $key = str_replace('\\', '/', $key); // Normalize path cho S3

        try {
            $this->s3->putObject([
                'Bucket'      => S3_BUCKET,
                'Key'         => $key,
                'SourceFile'  => $file,
                'ACL'         => 'public-read',
                'ContentType' => mime_content_type($file),
            ]);
            error_log('S3 Upload Success: ' . $key);
        } catch (AwsException $e) {
            error_log('S3 Upload Error: ' . $e->getMessage() . ' - Key: ' . $key);
        }
    }
}

new CT_Media_S3_Upload();