<?php
/**
 * SmartImg AJAX functions.
 *
 * @package SmartImg
 */

add_action( 'wp_ajax_smartimg_get_images', 'smartimg_get_images' );
add_action( 'wp_ajax_smartimg_resize_image', 'smartimg_ajax_resize' );
add_action( 'wp_ajax_smartimg_remove_original', 'smartimg_ajax_remove_original' );
add_action( 'wp_ajax_smartimg_bulk_complete', 'smartimg_ajax_finish' );

/**
 * Verifies that the current user has administrator permission and, if not,
 * renders a json warning and dies
 */
function smartimg_verify_permission() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		die(
			json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Administrator permission is required', 'smartimg' ),
				)
			)
		);
	}
	if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'smartimg-bulk' ) && ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'smartimg-manual-resize' ) ) {
		die(
			json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Access token has expired, please reload the page.', 'smartimg' ),
				)
			)
		);
	}
}


/**
 * Searches for up to 250 images that are candidates for resize and renders them
 * to the browser as a json array, then dies
 */
function smartimg_get_images() {
	smartimg_verify_permission();

	$resume_id = ! empty( $_POST['resume_id'] ) ? (int) $_POST['resume_id'] : PHP_INT_MAX;
	global $wpdb;
	// Load up all the image attachments we can find.
	$attachments = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE ID < %d AND post_type = 'attachment' AND post_mime_type LIKE %s ORDER BY ID DESC", $resume_id, '%%image%%' ) );
	array_walk( $attachments, 'intval' );
	die( json_encode( $attachments ) );

	// TODO: that's all, get rid of the rest.
	$offset  = 0;
	$limit   = apply_filters( 'smartimg_attachment_query_limit', 3000 );
	$results = array();
	$maxw    = smartimg_get_option( 'smartimg_max_width', SMARTIMG_DEFAULT_MAX_WIDTH );
	$maxh    = smartimg_get_option( 'smartimg_max_height', SMARTIMG_DEFAULT_MAX_HEIGHT );
	$count   = 0;

	$images = $wpdb->get_results( $wpdb->prepare( "SELECT metas.meta_value as file_meta,metas.post_id as ID FROM $wpdb->postmeta metas INNER JOIN $wpdb->posts posts ON posts.ID = metas.post_id WHERE posts.post_type = 'attachment' AND posts.post_mime_type LIKE %s AND posts.post_mime_type != 'image/bmp' AND metas.meta_key = '_wp_attachment_metadata' ORDER BY ID DESC LIMIT %d,%d", '%image%', $offset, $limit ) );
	while ( $images ) {

		foreach ( $images as $image ) {
			$imagew = false;
			$imageh = false;

			$meta = unserialize( $image->file_meta );

			// If "noresize" is included in the filename then we will bypass smartimg scaling.
			if ( ! empty( $meta['file'] ) && strpos( $meta['file'], 'noresize' ) !== false ) {
				continue;
			}

			// Let folks filter the allowed mime-types for resizing.
			$allowed_types = apply_filters( 'smartimg_allowed_mimes', array( 'image/png', 'image/gif', 'image/jpeg' ), $meta['file'] );
			if ( is_string( $allowed_types ) ) {
				$allowed_types = array( $allowed_types );
			} elseif ( ! is_array( $allowed_types ) ) {
				$allowed_types = array();
			}
			$ftype = smartimg_quick_mimetype( $meta['file'] );
			if ( ! in_array( $ftype, $allowed_types, true ) ) {
				continue;
			}

			if ( smartimg_get_option( 'smartimg_deep_scan', false ) ) {
				$file_path = smartimg_attachment_path( $meta, $image->ID, '', false );
				if ( $file_path ) {
					list( $imagew, $imageh ) = getimagesize( $file_path );
				}
			}
			if ( empty( $imagew ) || empty( $imageh ) ) {
				$imagew = $meta['width'];
				$imageh = $meta['height'];
			}

			if ( $imagew > $maxw || $imageh > $maxh ) {
				$count++;

				$results[] = array(
					'id'     => $image->ID,
					'width'  => $imagew,
					'height' => $imageh,
					'file'   => $meta['file'],
				);
			}

			// Make sure we only return a limited number of records so we don't overload the ajax features.
			if ( $count >= SMARTIMG_AJAX_MAX_RECORDS ) {
				break 2;
			}
		}
		$offset += $limit;
		$images  = $wpdb->get_results( $wpdb->prepare( "SELECT metas.meta_value as file_meta,metas.post_id as ID FROM $wpdb->postmeta metas INNER JOIN $wpdb->posts posts ON posts.ID = metas.post_id WHERE posts.post_type = 'attachment' AND posts.post_mime_type LIKE %s AND posts.post_mime_type != 'image/bmp' AND metas.meta_key = '_wp_attachment_metadata' ORDER BY ID DESC LIMIT %d,%d", '%image%', $offset, $limit ) );
	} // endwhile
	die( json_encode( $results ) );
}

/**
 * Resizes the image with the given id according to the configured max width and height settings
 * renders a json response indicating success/failure and dies
 */
function smartimg_ajax_resize() {
	smartimg_verify_permission();

	$id = (int) $_POST['id'];
	if ( ! $id ) {
		die(
			json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Missing ID Parameter', 'smartimg' ),
				)
			)
		);
	}
	$results = smartimg_resize_from_id( $id );
	if ( ! empty( $_POST['resumable'] ) ) {
		update_option( 'smartimg_resume_id', $id, false );
		sleep( 1 );
	}

	die( json_encode( $results ) );
}

/**
 * Resizes the image with the given id according to the configured max width and height settings
 * renders a json response indicating success/failure and dies
 */
function smartimg_ajax_remove_original() {
	smartimg_verify_permission();

	$id = (int) $_POST['id'];
	if ( ! $id ) {
		die(
			json_encode(
				array(
					'success' => false,
					'message' => esc_html__( 'Missing ID Parameter', 'smartimg' ),
				)
			)
		);
	}
	$remove_original = smartimg_remove_original_image( $id );
	if ( $remove_original && is_array( $remove_original ) ) {
		wp_update_attachment_metadata( $id, $remove_original );
		die( json_encode( array( 'success' => true ) ) );
	}

	die( json_encode( array( 'success' => false ) ) );
}

/**
 * Finalizes the resizing process.
 */
function smartimg_ajax_finish() {
	smartimg_verify_permission();

	update_option( 'smartimg_resume_id', 0, false );

	die();
}
