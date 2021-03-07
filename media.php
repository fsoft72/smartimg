<?php
/**
 * SmartImg Media Library functions.
 *
 * @package SmartImg
 */

/**
 * Add column header for SmartImg info/actions in the media library listing.
 *
 * @param array $columns A list of columns in the media library.
 * @return array The new list of columns.
 */
function smartimg_media_columns( $columns ) {
	$columns['smartimg'] = esc_html__( 'SmartImg', 'smartimg' );
	return $columns;
}

/**
 * Print SmartImg info/actions in the media library.
 *
 * @param string $column_name The name of the column being displayed.
 * @param int    $id The attachment ID number.
 * @param array  $meta Optional. The attachment metadata. Default null.
 */
function smartimg_custom_column( $column_name, $id, $meta = null ) {
	// Once we get to the EWWW IO custom column.
	if ( 'smartimg' === $column_name ) {
		$id = (int) $id;
		if ( is_null( $meta ) ) {
			// Retrieve the metadata.
			$meta = wp_get_attachment_metadata( $id );
		}
		echo '<div id="smartimg-media-status-' . (int) $id . '" class="smartimg-media-status" data-id="' . (int) $id . '">';
		if ( false && function_exists( 'print_r' ) ) {
			$print_meta = print_r( $meta, true );
			$print_meta = preg_replace( array( '/ /', '/\n+/' ), array( '&nbsp;', '<br />' ), $print_meta );
			echo "<div id='smartimg-debug-meta-" . (int) $id . "' style='font-size: 10px;padding: 10px;margin:3px -10px 10px;line-height: 1.1em;'>" . wp_kses_post( $print_meta ) . '</div>';
		}
		if ( is_array( $meta ) && ! empty( $meta['file'] ) && false !== strpos( $meta['file'], 'https://images-na.ssl-images-amazon.com' ) ) {
			echo esc_html__( 'Amazon-hosted image', 'smartimg' ) . '</div>';
			return;
		}
		if ( is_array( $meta ) && ! empty( $meta['cloudinary'] ) ) {
			echo esc_html__( 'Cloudinary image', 'smartimg' ) . '</div>';
			return;
		}
		if ( is_array( $meta ) & class_exists( 'WindowsAzureStorageUtil' ) && ! empty( $meta['url'] ) ) {
			echo '<div>' . esc_html__( 'Azure Storage image', 'smartimg' ) . '</div>';
			return;
		}
		if ( is_array( $meta ) && class_exists( 'Amazon_S3_And_CloudFront' ) && preg_match( '/^(http|s3|gs)\w*:/', get_attached_file( $id ) ) ) {
			echo '<div>' . esc_html__( 'Offloaded Media', 'smartimg' ) . '</div>';
			return;
		}
		if ( is_array( $meta ) && class_exists( 'S3_Uploads' ) && preg_match( '/^(http|s3|gs)\w*:/', get_attached_file( $id ) ) ) {
			echo '<div>' . esc_html__( 'Amazon S3 image', 'smartimg' ) . '</div>';
			return;
		}
		if ( is_array( $meta ) & class_exists( 'wpCloud\StatelessMedia' ) && ! empty( $meta['gs_link'] ) ) {
			echo '<div>' . esc_html__( 'WP Stateless image', 'smartimg' ) . '</div>';
			return;
		}
		$file_path = smartimg_attachment_path( $meta, $id );
		if ( is_array( $meta ) & function_exists( 'ilab_get_image_sizes' ) && ! empty( $meta['s3'] ) && empty( $file_path ) ) {
			echo esc_html__( 'Media Cloud image', 'smartimg' ) . '</div>';
			return;
		}
		// If the file does not exist.
		if ( empty( $file_path ) ) {
			echo esc_html__( 'Could not retrieve file path.', 'smartimg' ) . '</div>';
			return;
		}
		// Let folks filter the allowed mime-types for resizing.
		$allowed_types = apply_filters( 'smartimg_allowed_mimes', array( 'image/png', 'image/gif', 'image/jpeg' ), $file_path );
		if ( is_string( $allowed_types ) ) {
			$allowed_types = array( $allowed_types );
		} elseif ( ! is_array( $allowed_types ) ) {
			$allowed_types = array();
		}
		$ftype = smartimg_quick_mimetype( $file_path );
		if ( ! in_array( $ftype, $allowed_types, true ) ) {
			echo '</div>';
			return;
		}

		list( $imagew, $imageh ) = getimagesize( $file_path );
		if ( empty( $imagew ) || empty( $imageh ) ) {
			$imagew = $meta['width'];
			$imageh = $meta['height'];
		}

		if ( empty( $imagew ) || empty( $imageh ) ) {
			echo esc_html( 'Unknown dimensions', 'smartimg' );
			return;
		}
		echo '<div>' . (int) $imagew . 'w x ' . (int) $imageh . 'h</div>';

		$maxw = smartimg_get_option( 'smartimg_max_width', SMARTIMG_DEFAULT_MAX_WIDTH );
		$maxh = smartimg_get_option( 'smartimg_max_height', SMARTIMG_DEFAULT_MAX_HEIGHT );
		$always_jpg = smartimg_get_option( 'smartimg_always_resize_jpg', false );
		if ( $imagew > $maxw || $imageh > $maxh || $always_jpg ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				$manual_nonce = wp_create_nonce( 'smartimg-manual-resize' );
				// Give the user the option to optimize the image right now.
				printf(
					'<div><button class="smartimg-manual-resize button button-secondary" data-id="%1$d" data-nonce="%2$s">%3$s</button>',
					$id,
					esc_attr( $manual_nonce ),
					esc_html__( 'Re-Compress Image', 'smartimg' )
				);
			}
		} elseif ( current_user_can( 'activate_plugins' ) && smartimg_get_option( 'smartimg_delete_originals', false ) && ! empty( $meta['original_image'] ) && function_exists( 'wp_get_original_image_path' ) ) {
			$original_image = wp_get_original_image_path( $id );
			if ( empty( $original_image ) || ! is_file( $original_image ) ) {
				$original_image = wp_get_original_image_path( $id, true );
			}
			if ( ! empty( $original_image ) && is_file( $original_image ) && is_writable( $original_image ) ) {
				$link_text = __( 'Remove Original', 'smartimg' );
			} else {
				$link_text = __( 'Remove Original Link', 'smartimg' );
			}
			$manual_nonce = wp_create_nonce( 'smartimg-manual-resize' );
			// Give the user the option to optimize the image right now.
			printf(
				'<div><button class="smartimg-manual-remove-original button button-secondary" data-id="%1$d" data-nonce="%2$s">%3$s</button>',
				$id,
				esc_attr( $manual_nonce ),
				esc_html( $link_text )
			);
		}
		echo '</div>';
	}
}
