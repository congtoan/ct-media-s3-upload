<?php
/**
 * LEGACY configuration file (still supported).
 * Recommended: define the CT_MEDIA_S3_* constants in wp-config.php instead (see README.md),
 * so your keys are not stored inside the plugin folder and survive plugin updates.
 *
 * To use this file: copy it to config-s3.php (which is git-ignored) and fill in your values.
 */

define( 'S3_ENDPOINT',   'https://us-ord-1.linodeobjects.com' ); // S3-compatible endpoint
define( 'S3_REGION',     'us-ord-1' );                           // region name expected by the endpoint
define( 'S3_ACCESS_KEY', 'YOUR_ACCESS_KEY' );
define( 'S3_SECRET_KEY', 'YOUR_SECRET_KEY' );
define( 'S3_BUCKET',     'your-bucket-name' );
