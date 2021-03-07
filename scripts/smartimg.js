/**
 * smartimg admin javascript functions
 */

jQuery(document).ready(function($) {$(".fade").fadeTo(5000,1).fadeOut(3000);});

// Handle a manual resize from the media library.
jQuery(document).on('click', '.smartimg-manual-resize', function() {
	var post_id = jQuery(this).data('id');
	var smartimg_nonce = jQuery(this).data('nonce');
	jQuery('#smartimg-media-status-' + post_id ).html( smartimg_vars.resizing );
	jQuery.post(
		ajaxurl,
		{_wpnonce: smartimg_nonce, action: 'smartimg_resize_image', id: post_id},
		function(response) {
			var target = jQuery('#smartimg-media-status-' + post_id );
			try {
				var result = JSON.parse(response);
				target.html(result['message']);
			} catch(e) {
				target.html(smartimg_vars.invalid_response);
				if (console) {
					console.warn(post_id + ': '+ e.message);
					console.warn('Invalid JSON Response: ' + response);
				}
			}
		}
	);
	return false;
});

// Handle an original image removal request from the media library.
jQuery(document).on('click', '.smartimg-manual-remove-original', function() {
	var post_id = jQuery(this).data('id');
	var smartimg_nonce = jQuery(this).data('nonce');
	jQuery('#smartimg-media-status-' + post_id ).html( smartimg_vars.resizing );
	jQuery.post(
		ajaxurl,
		{_wpnonce: smartimg_nonce, action: 'smartimg_remove_original', id: post_id},
		function(response) {
			var target = jQuery('#smartimg-media-status-' + post_id );
			try {
				var result = JSON.parse(response);
				if (! result['success']) {
					target.html(smartimg_vars.removal_failed);
				} else {
					target.html(smartimg_vars.removal_succeeded);
				}
			} catch(e) {
				target.html(smartimg_vars.invalid_response);
				if (console) {
					console.warn(post_id + ': '+ e.message);
					console.warn('Invalid JSON Response: ' + response);
				}
			}
		}
	);
	return false;
});

jQuery(document).on('submit', '#smartimg-bulk-stop', function() {
	jQuery(this).hide();
	smartimg_vars.stopped = true;
	smartimg_vars.attachments = [];
	jQuery('#smartimg_loading').html(smartimg_vars.operation_stopped);
	jQuery('#smartimg_loading').show();
	return false;
});

/**
 * Begin the process of re-sizing all of the checked images
 */
function smartimg_resize_images() {
	// start the recursion
	smartimg_resize_next(0);
}

/**
 * recursive function for resizing images
 */
function smartimg_resize_next(next_index) {
	if (next_index >= smartimg_vars.attachments.length) return smartimg_resize_complete();
	var total_images = smartimg_vars.attachments.length;
	var target = jQuery('#resize_results');
	target.show();

	jQuery.post(
		ajaxurl, // (defined by wordpress - points to admin-ajax.php)
		{_wpnonce: smartimg_vars._wpnonce, action: 'smartimg_resize_image', id: smartimg_vars.attachments[next_index], resumable: 1},
		function (response) {
			var result;
			jQuery('#bulk-resize-beginning').hide();

			try {
				result = JSON.parse(response);
				target.append('<div>' + (next_index+1) + '/' + total_images + ' &gt;&gt; ' + result['message'] +'</div>');
			} catch(e) {
				target.append('<div>' + smartimg_vars.invalid_response + '</div>');
				if (console) {
					console.warn(smartimg_vars.attachments[next_index] + ': '+ e.message);
					console.warn('Invalid JSON Response: ' + response);
				}
			}

			target.animate({scrollTop: target.prop('scrollHeight')}, 200);
			// recurse
			smartimg_resize_next(next_index+1);
		}
	);
}

/**
 * fired when all images have been resized
 */
function smartimg_resize_complete() {
	var target = jQuery('#resize_results');
	if (! smartimg_vars.stopped) {
		jQuery('#smartimg-bulk-stop').hide();
		target.append('<div><strong>' + smartimg_vars.resizing_complete + '</strong></div>');
		jQuery.post(
			ajaxurl, // (global defined by wordpress - points to admin-ajax.php)
			{_wpnonce: smartimg_vars._wpnonce, action: 'smartimg_bulk_complete'}
		);
	}
	target.animate({scrollTop: target.prop('scrollHeight')});
}

/**
 * ajax post to return all images from the library
 * @param string the id of the html element into which results will be appended
 */
function smartimg_load_images() {
	var smartimg_really_resize_all = confirm(smartimg_vars.resize_all_prompt);
	if ( ! smartimg_really_resize_all ) {
		return;
	}
	jQuery('#smartimg-examine-button').hide();
	jQuery('.smartimg-bulk-text').hide();
	jQuery('#smartimg-bulk-reset').hide();
	jQuery('#smartimg_loading').show();

	jQuery.post(
		ajaxurl, // (global defined by wordpress - points to admin-ajax.php)
		{_wpnonce: smartimg_vars._wpnonce, action: 'smartimg_get_images', resume_id: smartimg_vars.resume_id},
		function(response) {
			var is_json = true;
			try {
				var images = jQuery.parseJSON(response);
			} catch ( err ) {
				is_json = false;
			}
			if ( ! is_json ) {
				console.log( response );
				return false;
			}

			jQuery('#smartimg_loading').hide();
			if (images.length > 0) {
				smartimg_vars.attachments = images;
				smartimg_vars.stopped = false;
				jQuery('#smartimg-bulk-stop').show();
				smartimg_resize_images();
			} else {
				jQuery('#smartimg_loading').html('<div>' + smartimg_vars.none_found + '</div>');
			}
		}
	);
}
