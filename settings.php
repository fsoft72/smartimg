<?php
/**
 * SmartImg settings and admin UI.
 *
 * @package SmartImg
 */

// Setup custom $wpdb attribute for our image-tracking table.
global $wpdb;
if ( ! isset( $wpdb->smartimg_ms ) ) {
	$wpdb->smartimg_ms = $wpdb->get_blog_prefix( 0 ) . 'smartimg';
}

// Register the plugin settings menu.
add_action( 'admin_menu', 'smartimg_create_menu' );
add_action( 'network_admin_menu', 'smartimg_register_network' );
add_filter( 'plugin_action_links_' . SMARTIMG_PLUGIN_FILE_REL, 'smartimg_settings_link' );
add_filter( 'network_admin_plugin_action_links_' . SMARTIMG_PLUGIN_FILE_REL, 'smartimg_settings_link' );
add_action( 'admin_enqueue_scripts', 'smartimg_queue_script' );
add_action( 'admin_init', 'smartimg_register_settings' );
add_filter( 'big_image_size_threshold', 'smartimg_adjust_default_threshold', 10, 3 );

register_activation_hook( SMARTIMG_PLUGIN_FILE_REL, 'smartimg_maybe_created_custom_table' );

// settings cache.
$_smartimg_multisite_settings = null;

/**
 * Create the settings menu item in the WordPress admin navigation and
 * link it to the plugin settings page
 */
function smartimg_create_menu() {
	$permissions = apply_filters( 'smartimg_admin_permissions', 'manage_options' );
	// Create new menu for site configuration.
	add_options_page(
		esc_html__( 'SmartImg Plugin Settings', 'smartimg' ), // Page Title.
		esc_html__( 'SmartImg', 'smartimg' ),                 // Menu Title.
		$permissions,                                         // Required permissions.
		SMARTIMG_PLUGIN_FILE_REL,                             // Slug.
		'smartimg_settings_page'                              // Function to call.
	);
}

/**
 * Register the network settings page
 */
function smartimg_register_network() {
	if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
		// Need to include the plugin library for the is_plugin_active function.
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}
	if ( is_multisite() ) {
		$permissions = apply_filters( 'smartimg_superadmin_permissions', 'manage_network_options' );
		add_submenu_page(
			'settings.php',
			esc_html__( 'SmartImg Network Settings', 'smartimg' ),
			esc_html__( 'SmartImg', 'smartimg' ),
			$permissions,
			SMARTIMG_PLUGIN_FILE_REL,
			'smartimg_network_settings'
		);
	}
}

/**
 * Settings link that appears on the plugins overview page
 *
 * @param array $links The plugin action links.
 * @return array The action links, with a settings link pre-pended.
 */
function smartimg_settings_link( $links ) {
	if ( ! is_array( $links ) ) {
		$links = array();
	}
	if ( is_multisite() && is_network_admin() ) {
		$settings_link = '<a href="' . network_admin_url( 'settings.php?page=' . SMARTIMG_PLUGIN_FILE_REL ) . '">' . esc_html__( 'Settings', 'smartimg' ) . '</a>';
	} else {
		$settings_link = '<a href="' . admin_url( 'options-general.php?page=' . SMARTIMG_PLUGIN_FILE_REL ) . '">' . esc_html__( 'Settings', 'smartimg' ) . '</a>';
	}
	array_unshift( $links, $settings_link );
	return $links;
}

/**
 * Queues up the AJAX script and any localized JS vars we need.
 *
 * @param string $hook The hook name for the current page.
 */
function smartimg_queue_script( $hook ) {
	// Make sure we are being called from the settings page.
	if ( strpos( $hook, 'settings_page_smartimg' ) !== 0 && 'upload.php' !== $hook ) {
		return;
	}
	if ( ! empty( $_REQUEST['smartimg_reset'] ) && ! empty( $_REQUEST['smartimg_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_REQUEST['smartimg_wpnonce'] ), 'smartimg-bulk-reset' ) ) {
		update_option( 'smartimg_resume_id', 0, false );
	}
	$resume_id     = (int) get_option( 'smartimg_resume_id' );
	$loading_image = plugins_url( '/images/ajax-loader.gif', __FILE__ );
	// Register the scripts that are used by the bulk resizer.
	wp_enqueue_script( 'smartimg_script', plugins_url( '/scripts/smartimg.js', __FILE__ ), array( 'jquery' ), SMARTIMG_VERSION );
	wp_localize_script(
		'smartimg_script',
		'smartimg_vars',
		array(
			'_wpnonce'          => wp_create_nonce( 'smartimg-bulk' ),
			'resize_all_prompt' => esc_html__( 'You are about to resize all your existing images. Please be sure your site is backed up before proceeding. Do you wish to continue?', 'smartimg' ),
			'resizing_complete' => esc_html__( 'Resizing Complete', 'smartimg' ) . ' - <a target="_blank" href="https://wordpress.org/support/plugin/smartimg/reviews/#new-post">' . esc_html__( 'Leave a Review', 'smartimg' ) . '</a>',
			'resize_selected'   => esc_html__( 'Resize Selected Images', 'smartimg' ),
			'resizing'          => '<p>' . esc_html__( 'Please wait...', 'smartimg' ) . "&nbsp;<img src='$loading_image' /></p>",
			'removal_failed'    => esc_html__( 'Removal Failed', 'smartimg' ),
			'removal_succeeded' => esc_html__( 'Removal Complete', 'smartimg' ),
			'operation_stopped' => esc_html__( 'Resizing stopped, reload page to resume.', 'smartimg' ),
			'image'             => esc_html__( 'Image', 'smartimg' ),
			'invalid_response'  => esc_html__( 'Received an invalid response, please check for errors in the Developer Tools console of your browser.', 'smartimg' ),
			'none_found'        => esc_html__( 'There are no images that need to be resized.', 'smartimg' ),
			'resume_id'         => $resume_id,
		)
	);
	add_action( 'admin_notices', 'smartimg_missing_gd_admin_notice' );
	add_action( 'network_admin_notices', 'smartimg_missing_gd_admin_notice' );
	add_action( 'admin_print_scripts', 'smartimg_settings_css' );
}

/**
 * Return true if the multi-site settings table exists
 *
 * @return bool True if the SmartImg table exists.
 */
function smartimg_multisite_table_exists() {
	global $wpdb;
	return $wpdb->get_var( "SHOW TABLES LIKE '$wpdb->smartimg_ms'" ) === $wpdb->smartimg_ms;
}

/**
 * Checks the schema version for the SmartImg table.
 *
 * @return string The version identifier for the schema.
 */
function smartimg_multisite_table_schema_version() {
	// If the table doesn't exist then there is no schema to report.
	if ( ! smartimg_multisite_table_exists() ) {
		return '0';
	}

	global $wpdb;
	$version = $wpdb->get_var( "SELECT data FROM $wpdb->smartimg_ms WHERE setting = 'schema'" );

	if ( ! $version ) {
		$version = '1.0'; // This is a legacy version 1.0 installation.
	}

	return $version;
}

/**
 * Returns the default network settings in the case where they are not
 * defined in the database, or multi-site is not enabled.
 *
 * @return stdClass
 */
function smartimg_get_default_multisite_settings() {
	$data = new stdClass();

	$data->smartimg_override_site      = false;
	$data->smartimg_max_height         = SMARTIMG_DEFAULT_MAX_HEIGHT;
	$data->smartimg_max_width          = SMARTIMG_DEFAULT_MAX_WIDTH;
	$data->smartimg_max_height_library = SMARTIMG_DEFAULT_MAX_HEIGHT;
	$data->smartimg_max_width_library  = SMARTIMG_DEFAULT_MAX_WIDTH;
	$data->smartimg_max_height_other   = SMARTIMG_DEFAULT_MAX_HEIGHT;
	$data->smartimg_max_width_other    = SMARTIMG_DEFAULT_MAX_WIDTH;
	$data->smartimg_bmp_to_jpg         = SMARTIMG_DEFAULT_BMP_TO_JPG;
	$data->smartimg_png_to_jpg         = SMARTIMG_DEFAULT_PNG_TO_JPG;
	$data->smartimg_quality            = SMARTIMG_DEFAULT_QUALITY;
	$data->smartimg_delete_originals   = false;
	$data->smartimg_always_resize_jpg  = true;
	return $data;
}


/**
 * On activation create the multisite database table if necessary.  this is
 * called when the plugin is activated as well as when it is automatically
 * updated.
 */
function smartimg_maybe_created_custom_table() {
	// If not a multi-site no need to do any custom table lookups.
	if ( ! function_exists( 'is_multisite' ) || ( ! is_multisite() ) ) {
		return;
	}

	global $wpdb;

	$schema = smartimg_multisite_table_schema_version();

	if ( '0' === $schema ) {
		// This is an initial database setup.
		$sql = 'CREATE TABLE IF NOT EXISTS ' . $wpdb->smartimg_ms . ' (
					  setting varchar(55),
					  data text NOT NULL,
					  PRIMARY KEY (setting)
					);';

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		// Add the rows to the database.
		$data = smartimg_get_default_multisite_settings();
		$wpdb->insert(
			$wpdb->smartimg_ms,
			array(
				'setting' => 'multisite',
				'data'    => maybe_serialize( $data ),
			)
		);
		$wpdb->insert(
			$wpdb->smartimg_ms,
			array(
				'setting' => 'schema',
				'data'    => SMARTIMG_SCHEMA_VERSION,
			)
		);
	}

	if ( SMARTIMG_SCHEMA_VERSION !== $schema ) {
		// This is a schema update.  for the moment there is only one schema update available, from 1.0 to 1.1.
		if ( '1.0' === $schema ) {
			// Update from version 1.0 to 1.1.
			$wpdb->insert(
				$wpdb->smartimg_ms,
				array(
					'setting' => 'schema',
					'data'    => SMARTIMG_SCHEMA_VERSION,
				)
			);
			$wpdb->query( "ALTER TABLE $wpdb->smartimg_ms CHANGE COLUMN data data TEXT NOT NULL;" );
		} else {
			// @todo we don't have this yet
			$wpdb->update(
				$wpdb->smartimg_ms,
				array( 'data' => SMARTIMG_SCHEMA_VERSION ),
				array( 'setting' => 'schema' )
			);
		}
	}
}

/**
 * Display the form for the multi-site settings page.
 */
function smartimg_network_settings() {
	$settings = smartimg_get_multisite_settings(); ?>
<div class="wrap">
	<h1><?php esc_html_e( 'SmartImg Network Settings', 'smartimg' ); ?></h1>

	<form method="post" action="">
	<input type="hidden" name="update_smartimg_settings" value="1" />
	<?php wp_nonce_field( 'smartimg_network_options' ); ?>
	<table class="form-table">
		<tr>
			<th scope="row"><label for="smartimg_override_site"><?php esc_html_e( 'Global Settings Override', 'smartimg' ); ?></label></th>
			<td>
				<select name="smartimg_override_site">
					<option value="0" <?php selected( $settings->smartimg_override_site, '0' ); ?> ><?php esc_html_e( 'Allow each site to configure SmartImg settings', 'smartimg' ); ?></option>
					<option value="1" <?php selected( $settings->smartimg_override_site, '1' ); ?> ><?php esc_html_e( 'Use global SmartImg settings (below) for all sites', 'smartimg' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'If you allow per-site configuration, the settings below will be used as the defaults. Single-site defaults will be set the first time you visit the site admin after activating SmartImg.', 'smartimg' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Images uploaded within a Page/Post', 'smartimg' ); ?></th>
			<td>
				<label for="smartimg_max_width"><?php esc_html_e( 'Max Width', 'smartimg' ); ?></label> <input type="number" step="1" min="0" class='small-text' name="smartimg_max_width" value="<?php echo (int) $settings->smartimg_max_width; ?>" />
				<label for="smartimg_max_height"><?php esc_html_e( 'Max Height', 'smartimg' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="smartimg_max_height" value="<?php echo (int) $settings->smartimg_max_height; ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'smartimg' ); ?>
				<p class="description"><?php esc_html_e( 'These dimensions are used for Bulk Resizing also.', 'smartimg' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Images uploaded directly to the Media Library', 'smartimg' ); ?></th>
			<td>
				<label for="smartimg_max_width_library"><?php esc_html_e( 'Max Width', 'smartimg' ); ?></label> <input type="number" step="1" min="0" class='small-text' name="smartimg_max_width_library" value="<?php echo (int) $settings->smartimg_max_width_library; ?>" />
				<label for="smartimg_max_height_library"><?php esc_html_e( 'Max Height', 'smartimg' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="smartimg_max_height_library" value="<?php echo (int) $settings->smartimg_max_height_library; ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'smartimg' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Images uploaded elsewhere (Theme headers, backgrounds, logos, etc)', 'smartimg' ); ?></th>
			<td>
				<label for="smartimg_max_width_other"><?php esc_html_e( 'Max Width', 'smartimg' ); ?></label> <input type="number" step="1" min="0" class='small-text' name="smartimg_max_width_other" value="<?php echo (int) $settings->smartimg_max_width_other; ?>" />
				<label for="smartimg_max_height_other"><?php esc_html_e( 'Max Height', 'smartimg' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="smartimg_max_height_other" value="<?php echo (int) $settings->smartimg_max_height_other; ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'smartimg' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for='smartimg_quality'><?php esc_html_e( 'JPG image quality', 'smartimg' ); ?>
			</th>
			<td>
				<input type='text' id='smartimg_quality' name='smartimg_quality' class='small-text' value='<?php echo (int) $settings->smartimg_quality; ?>' />
				<?php esc_html_e( 'Valid values are 1-100.', 'smartimg' ); ?>
				<p class='description'><?php esc_html_e( 'Only used when resizing images, does not affect thumbnails.', 'smartimg' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for"smartimg_bmp_to_jpg"><?php esc_html_e( 'Convert BMP to JPG', 'smartimg' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="smartimg_bmp_to_jpg" name="smartimg_bmp_to_jpg" value="true" <?php checked( $settings->smartimg_bmp_to_jpg ); ?> />
				<?php esc_html_e( 'Only applies to new image uploads, existing BMP images cannot be converted or resized.', 'smartimg' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="smartimg_png_to_jpg"><?php esc_html_e( 'Convert PNG to JPG', 'smartimg' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="smartimg_png_to_jpg" name="smartimg_png_to_jpg" value="true" <?php checked( $settings->smartimg_png_to_jpg ); ?> />
				<?php
				printf(
					/* translators: %s: link to install EWWW Image Optimizer plugin */
					esc_html__( 'Only applies to new image uploads, existing images may be converted with %s.', 'smartimg' ),
					'<a href="' . admin_url( 'plugin-install.php?s=ewww+image+optimizer&tab=search&type=term' ) . '">EWWW Image Optimizer</a>'
				);
				?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="smartimg_delete_originals"><?php esc_html_e( 'Delete Originals', 'smartimg' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="smartimg_delete_originals" name="smartimg_delete_originals" value="true" <?php checked( $settings->smartimg_delete_originals ); ?> />
				<?php esc_html_e( 'Remove the large pre-scaled originals that WordPress retains for thumbnail generation.', 'smartimg' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="smartimg_always_resize_jpg"><?php esc_html_e( 'Always Resize JPG', 'smartimg' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="smartimg_always_resize_jpg" name="smartimg_always_resize_jpg" value="true" <?php checked( $settings->smartimg_always_resize_jpg ); ?> />
				<?php esc_html_e( 'Always resize JPG on upload even if they are not bigger than max size', 'smartimg' ); ?>
			</td>
		</tr>
	</table>

	<p class="submit"><input type="submit" class="button-primary" value="<?php esc_attr_e( 'Update Settings', 'smartimg' ); ?>" /></p>

	</form>

</div>
	<?php
}

/**
 * Process the form, update the network settings
 * and clear the cached settings
 */
function smartimg_network_settings_update() {
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'smartimg_network_options' ) ) {
		return;
	}
	global $wpdb;
	global $_smartimg_multisite_settings;

	// ensure that the custom table is created when the user updates network settings
	// this is not ideal but it's better than checking for this table existance
	// on every page load.
	smartimg_maybe_created_custom_table();

	$data = new stdClass();

	$data->smartimg_override_site      = (bool) $_POST['smartimg_override_site'];
	$data->smartimg_max_height         = sanitize_text_field( $_POST['smartimg_max_height'] );
	$data->smartimg_max_width          = sanitize_text_field( $_POST['smartimg_max_width'] );
	$data->smartimg_max_height_library = sanitize_text_field( $_POST['smartimg_max_height_library'] );
	$data->smartimg_max_width_library  = sanitize_text_field( $_POST['smartimg_max_width_library'] );
	$data->smartimg_max_height_other   = sanitize_text_field( $_POST['smartimg_max_height_other'] );
	$data->smartimg_max_width_other    = sanitize_text_field( $_POST['smartimg_max_width_other'] );
	$data->smartimg_bmp_to_jpg         = ! empty( $_POST['smartimg_bmp_to_jpg'] );
	$data->smartimg_png_to_jpg         = ! empty( $_POST['smartimg_png_to_jpg'] );
	$data->smartimg_quality            = smartimg_jpg_quality( $_POST['smartimg_quality'] );
	$data->smartimg_delete_originals   = ! empty( $_POST['smartimg_delete_originals'] );
	$data->smartimg_always_resize_jpg  = ! empty( $_POST['smartimg_always_resize_jpg'] );

	$success = $wpdb->update(
		$wpdb->smartimg_ms,
		array( 'data' => maybe_serialize( $data ) ),
		array( 'setting' => 'multisite' )
	);

	// Clear the cache.
	$_smartimg_multisite_settings = null;
	add_action( 'network_admin_notices', 'smartimg_network_settings_saved' );
}

/**
 * Display a message to inform the user the multi-site setting have been saved.
 */
function smartimg_network_settings_saved() {
	echo "<div id='smartimg-network-settings-saved' class='updated fade'><p><strong>" . esc_html__( 'SmartImg network settings saved.', 'smartimg' ) . '</strong></p></div>';
}

/**
 * Return the multi-site settings as a standard class.  If the settings are not
 * defined in the database or multi-site is not enabled then the default settings
 * are returned.  This is cached so it only loads once per page load, unless
 * smartimg_network_settings_update is called.
 *
 * @return stdClass
 */
function smartimg_get_multisite_settings() {
	global $_smartimg_multisite_settings;
	$result = null;

	if ( ! $_smartimg_multisite_settings ) {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			global $wpdb;
			$result = $wpdb->get_var( "SELECT data FROM $wpdb->smartimg_ms WHERE setting = 'multisite'" );
		}

		// if there's no results, return the defaults instead.
		$_smartimg_multisite_settings = $result
			? unserialize( $result )
			: smartimg_get_default_multisite_settings();

		// this is for backwards compatibility.
		if ( ! isset( $_smartimg_multisite_settings->smartimg_max_height_library ) ) {
			$_smartimg_multisite_settings->smartimg_max_height_library = $_smartimg_multisite_settings->smartimg_max_height;
			$_smartimg_multisite_settings->smartimg_max_width_library  = $_smartimg_multisite_settings->smartimg_max_width;
			$_smartimg_multisite_settings->smartimg_max_height_other   = $_smartimg_multisite_settings->smartimg_max_height;
			$_smartimg_multisite_settings->smartimg_max_width_other    = $_smartimg_multisite_settings->smartimg_max_width;
		}
		$_smartimg_multisite_settings->smartimg_override_site = ! empty( $_smartimg_multisite_settings->smartimg_override_site ) ? '1' : '0';
		$_smartimg_multisite_settings->smartimg_bmp_to_jpg    = ! empty( $_smartimg_multisite_settings->smartimg_bmp_to_jpg ) ? true : false;
		$_smartimg_multisite_settings->smartimg_png_to_jpg    = ! empty( $_smartimg_multisite_settings->smartimg_png_to_jpg ) ? true : false;
		if ( ! property_exists( $_smartimg_multisite_settings, 'smartimg_delete_originals' ) ) {
			$_smartimg_multisite_settings->smartimg_delete_originals = false;
		}
		if ( ! property_exists( $_smartimg_multisite_settings, 'smartimg_always_resize_jpg' ) ) {
			$_smartimg_multisite_settings->smartimg_always_resize_jpg = false;
		}
	}
	return $_smartimg_multisite_settings;
}

/**
 * Gets the option setting for the given key, first checking to see if it has been
 * set globally for multi-site.  Otherwise checking the site options.
 *
 * @param string $key The name of the option to retrieve.
 * @param string $ifnull Value to use if the requested option returns null.
 */
function smartimg_get_option( $key, $ifnull ) {
	$result = null;

	$settings = smartimg_get_multisite_settings();

	if ( $settings->smartimg_override_site ) {
		$result = $settings->$key;
		if ( is_null( $result ) ) {
			$result = $ifnull;
		}
	} else {
		$result = get_option( $key, $ifnull );
	}

	return $result;
}

/**
 * Run upgrade check for new version.
 */
function smartimg_upgrade() {
	if ( is_network_admin() ) {
		return;
	}
	if ( -1 === version_compare( get_option( 'smartimg_version' ), SMARTIMG_VERSION ) ) {
		if ( wp_doing_ajax() ) {
			return;
		}
		smartimg_set_defaults();
		update_option( 'smartimg_version', SMARTIMG_VERSION );
	}
}

/**
 * Set default options on multi-site.
 */
function smartimg_set_defaults() {
	$settings = smartimg_get_multisite_settings();
	add_option( 'smartimg_max_width', $settings->smartimg_max_width, '', false );
	add_option( 'smartimg_max_height', $settings->smartimg_max_height, '', false );
	add_option( 'smartimg_max_width_library', $settings->smartimg_max_width_library, '', false );
	add_option( 'smartimg_max_height_library', $settings->smartimg_max_height_library, '', false );
	add_option( 'smartimg_max_width_other', $settings->smartimg_max_width_other, '', false );
	add_option( 'smartimg_max_height_other', $settings->smartimg_max_height_other, '', false );
	add_option( 'smartimg_bmp_to_jpg', $settings->smartimg_bmp_to_jpg, '', false );
	add_option( 'smartimg_png_to_jpg', $settings->smartimg_png_to_jpg, '', false );
	add_option( 'smartimg_quality', $settings->smartimg_quality, '', false );
	add_option( 'smartimg_delete_originals', $settings->smartimg_delete_originals, '', false );
	add_option( 'smartimg_always_resize_jpg', $settings->smartimg_always_resize_jpg, '', false );
	if ( ! get_option( 'smartimg_version' ) ) {
		global $wpdb;
		$wpdb->query( "UPDATE $wpdb->options SET autoload='no' WHERE option_name LIKE 'smartimg_%'" );
	}
}

/**
 * Register the configuration settings that the plugin will use
 */
function smartimg_register_settings() {
	smartimg_upgrade();
	// We only want to update if the form has been submitted.
	if ( isset( $_POST['update_smartimg_settings'] ) && is_multisite() && is_network_admin() ) {
		smartimg_network_settings_update();
	}
	// Register our settings.
	register_setting( 'smartimg-settings-group', 'smartimg_max_height', 'intval' );
	register_setting( 'smartimg-settings-group', 'smartimg_max_width', 'intval' );
	register_setting( 'smartimg-settings-group', 'smartimg_max_height_library', 'intval' );
	register_setting( 'smartimg-settings-group', 'smartimg_max_width_library', 'intval' );
	register_setting( 'smartimg-settings-group', 'smartimg_max_height_other', 'intval' );
	register_setting( 'smartimg-settings-group', 'smartimg_max_width_other', 'intval' );
	register_setting( 'smartimg-settings-group', 'smartimg_bmp_to_jpg', 'boolval' );
	register_setting( 'smartimg-settings-group', 'smartimg_png_to_jpg', 'boolval' );
	register_setting( 'smartimg-settings-group', 'smartimg_quality', 'smartimg_jpg_quality' );
	register_setting( 'smartimg-settings-group', 'smartimg_delete_originals', 'boolval' );
	register_setting( 'smartimg-settings-group', 'smartimg_always_resize_jpg', 'boolval' );
}

/**
 * Validate and return the JPG quality setting.
 *
 * @param int $quality The JPG quality currently set.
 * @return int The (potentially) adjusted quality level.
 */
function smartimg_jpg_quality( $quality = null ) {
	if ( is_null( $quality ) ) {
		$quality = get_option( 'smartimg_quality' );
	}
	if ( preg_match( '/^(100|[1-9][0-9]?)$/', $quality ) ) {
		return (int) $quality;
	} else {
		return SMARTIMG_DEFAULT_QUALITY;
	}
}

/**
 * Check default WP threshold and adjust to comply with normal SmartImg behavior.
 *
 * @param int    $size The default WP scaling size, or whatever has been filtered by other plugins.
 * @param array  $imagesize     {
 *     Indexed array of the image width and height in pixels.
 *
 *     @type int $0 The image width.
 *     @type int $1 The image height.
 * }
 * @param string $file Full path to the uploaded image file.
 * @return int The proper size to use for scaling originals.
 */
function smartimg_adjust_default_threshold( $size, $imagesize, $file ) {
	if ( false !== strpos( $file, 'noresize' ) ) {
		return false;
	}
	$max_size = max(
		smartimg_get_option( 'smartimg_max_width', SMARTIMG_DEFAULT_MAX_WIDTH ),
		smartimg_get_option( 'smartimg_max_height', SMARTIMG_DEFAULT_MAX_HEIGHT ),
		smartimg_get_option( 'smartimg_max_width_library', SMARTIMG_DEFAULT_MAX_WIDTH ),
		smartimg_get_option( 'smartimg_max_height_library', SMARTIMG_DEFAULT_MAX_HEIGHT ),
		smartimg_get_option( 'smartimg_max_width_other', SMARTIMG_DEFAULT_MAX_WIDTH ),
		smartimg_get_option( 'smartimg_max_height_other', SMARTIMG_DEFAULT_MAX_HEIGHT ),
		(int) $size
	);
	return $max_size;
}

/**
 * Helper function to render css styles for the settings forms
 * for both site and network settings page
 */
function smartimg_settings_css() {
	?>
<style>
	#smartimg_header {
		border: solid 1px #c6c6c6;
		margin: 10px 0px;
		padding: 0px 10px;
		background-color: #e1e1e1;
	}
	#smartimg_header p {
		margin: .5em 0;
	}
	#ewwwio-promo {
		display: none;
		float: right;
	}
	#ewwwio-promo a, #ewwwio-promo a:visited {
		color: #3eadc9;
	}
	#ewwwio-promo ul {
		list-style: disc;
		padding-left: 13px;
	}
	@media screen and (min-width: 850px) {
		.form-table {
			clear: left;
			width: calc(100% - 210px);
		}
		#ewwwio-promo {
			display: block;
			border: 1px solid #7e8993;
			padding: 13px;
			margin: 13px;
			width: 150px;
		}
	}
</style>
	<?php
}

/**
 * Render the settings page by writing directly to stdout.  if multi-site is enabled
 * and smartimg_override_site is true, then display a notice message that settings
 * are not editable instead of the settings form
 */
function smartimg_settings_page() {
	?>
	<div class="wrap">
	<h1><?php esc_html_e( 'SmartImg Settings', 'smartimg' ); ?></h1>
	<p>
		<a target="_blank" href="https://wordpress.org/plugins/smartimg/#faq-header"><?php esc_html_e( 'FAQ', 'smartimg' ); ?></a> |
		<a target="_blank" href="https://wordpress.org/support/plugin/smartimg/"><?php esc_html_e( 'Support', 'smartimg' ); ?></a> |
		<a target="_blank" href="https://wordpress.org/support/plugin/smartimg/reviews/#new-post"><?php esc_html_e( 'Leave a Review', 'smartimg' ); ?></a>
	</p>

	<?php

	$settings = smartimg_get_multisite_settings();

	if ( $settings->smartimg_override_site ) {
		smartimg_settings_page_notice();
	} else {
		smartimg_settings_page_form();
	}

	?>

	<h2 style="margin-top: 0px;"><?php esc_html_e( 'Bulk Resize Images', 'smartimg' ); ?></h2>

	<div id="smartimg_header">
		<p><?php esc_html_e( 'If you have existing images that were uploaded prior to installing SmartImg, you may resize them all in bulk to recover disk space (below).', 'smartimg' ); ?></p>
		<p>
			<?php
			printf(
				/* translators: 1: List View in the Media Library 2: the WP-CLI command */
				esc_html__( 'You may also use %1$s to selectively resize images or WP-CLI to resize your images in bulk: %2$s', 'smartimg' ),
				'<a href="' . esc_url( admin_url( 'upload.php?mode=list' ) ) . '">' . esc_html__( 'List View in the Media Library', 'smartimg' ) . '</a>',
				'<code>wp help smartimg resize</code>'
			);
			?>
		</p>
	</div>

	<div style="border: solid 1px #ff6666; background-color: #ffbbbb; padding: 0 10px;margin-bottom:1em;">
		<h4><?php esc_html_e( 'WARNING: Bulk Resize will alter your original images and cannot be undone!', 'smartimg' ); ?></h4>
		<p>
			<?php esc_html_e( 'It is HIGHLY recommended that you backup your images before proceeding.', 'smartimg' ); ?><br>
			<?php
			printf(
				/* translators: %s: List View in the Media Library */
				esc_html__( 'You may also resize 1 or 2 images using %s to verify that everything is working properly before processing your entire library.', 'smartimg' ),
				'<a href="' . esc_url( admin_url( 'upload.php?mode=list' ) ) . '">' . esc_html__( 'List View in the Media Library', 'smartimg' ) . '</a>'
			);
			?>
		</p>
	</div>

	<?php
	$button_text = __( 'Start Resizing All Images', 'smartimg' );
	if ( get_option( 'smartimg_resume_id' ) ) {
		$button_text = __( 'Continue Resizing', 'smartimg' );
	}
	?>

	<p class="submit" id="smartimg-examine-button">
		<button class="button-primary" onclick="smartimg_load_images();"><?php echo esc_html( $button_text ); ?></button>
	</p>
	<form id="smartimg-bulk-stop" style="display:none;margin:1em 0 1em;" method="post" action="">
		<button type="submit" class="button-secondary action"><?php esc_html_e( 'Stop Resizing', 'smartimg' ); ?></button>
	</form>
	<?php if ( get_option( 'smartimg_resume_id' ) ) : ?>
	<p class="smartimg-bulk-text" style="margin-top:1em;"><?php esc_html_e( 'Would you like to start back at the beginning?', 'smartimg' ); ?></p>
	<form class="smartimg-bulk-form" method="post" action="">
		<?php wp_nonce_field( 'smartimg-bulk-reset', 'smartimg_wpnonce' ); ?>
		<input type="hidden" name="smartimg_reset" value="1">
		<button id="smartimg-bulk-reset" type="submit" class="button-secondary action"><?php esc_html_e( 'Clear Queue', 'smartimg' ); ?></button>
	</form>
	<?php endif; ?>
	<div id="smartimg_loading" style="display: none;margin:1em 0 1em;"><img src="<?php echo plugins_url( 'images/ajax-loader.gif', __FILE__ ); ?>" style="margin-bottom: .25em; vertical-align:middle;" />
		<?php esc_html_e( 'Searching for images. This may take a moment.', 'smartimg' ); ?>
	</div>
	<div id="resize_results" style="display: none; border: solid 2px #666666; padding: 10px; height: 400px; overflow: auto;">
		<div id="bulk-resize-beginning"><?php esc_html_e( 'Resizing...', 'smartimg' ); ?> <img src="<?php echo plugins_url( 'images/ajax-loader.gif', __FILE__ ); ?>" style="margin-bottom: .25em; vertical-align:middle;" /></div>
	</div>

	<?php

	echo '</div>';
}

/**
 * Multi-user config file exists so display a notice
 */
function smartimg_settings_page_notice() {
	?>
	<div class="updated settings-error">
	<p><strong><?php esc_html_e( 'SmartImg settings have been configured by the server administrator. There are no site-specific settings available.', 'smartimg' ); ?></strong></p>
	</div>
	<?php
}

/**
 * Check to see if GD is missing, and alert the user.
 */
function smartimg_missing_gd_admin_notice() {
	if ( _gd_supported() ) {
		return;
	}
	echo "<div id='smartimg-missing-gd' class='notice notice-warning'><p>" . esc_html__( 'The GD extension is not enabled in PHP, SmartImg may not function correctly. Enable GD or contact your web host for assistance.', 'smartimg' ) . '</p></div>';
}

/**
 * Render the site settings form.  This is processed by
 * WordPress built-in options persistance mechanism
 */
function smartimg_settings_page_form() {
	?>
	<form method="post" action="options.php">
	<?php settings_fields( 'smartimg-settings-group' ); ?>
		<table class="form-table">

		<tr>
		<th scope="row"><?php esc_html_e( 'Images uploaded within a Page/Post', 'smartimg' ); ?></th>
		<td>
			<label for="smartimg_max_width"><?php esc_html_e( 'Max Width', 'smartimg' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="smartimg_max_width" value="<?php echo (int) get_option( 'smartimg_max_width', SMARTIMG_DEFAULT_MAX_WIDTH ); ?>" />
			<label for="smartimg_max_height"><?php esc_html_e( 'Max Height', 'smartimg' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="smartimg_max_height" value="<?php echo (int) get_option( 'smartimg_max_height', SMARTIMG_DEFAULT_MAX_HEIGHT ); ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'smartimg' ); ?>
			<p class="description"><?php esc_html_e( 'These dimensions are used for Bulk Resizing also.', 'smartimg' ); ?></p>
		</td>
		</tr>

		<tr>
		<th scope="row"><?php esc_html_e( 'Images uploaded directly to the Media Library', 'smartimg' ); ?></th>
		<td>
			<label for="smartimg_max_width_library"><?php esc_html_e( 'Max Width', 'smartimg' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="smartimg_max_width_library" value="<?php echo (int) get_option( 'smartimg_max_width_library', SMARTIMG_DEFAULT_MAX_WIDTH ); ?>" />
			<label for="smartimg_max_height_library"><?php esc_html_e( 'Max Height', 'smartimg' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="smartimg_max_height_library" value="<?php echo (int) get_option( 'smartimg_max_height_library', SMARTIMG_DEFAULT_MAX_HEIGHT ); ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'smartimg' ); ?>
		</td>
		</tr>

		<tr>
		<th scope="row"><?php esc_html_e( 'Images uploaded elsewhere (Theme headers, backgrounds, logos, etc)', 'smartimg' ); ?></th>
		<td>
			<label for="smartimg_max_width_other"><?php esc_html_e( 'Max Width', 'smartimg' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="smartimg_max_width_other" value="<?php echo (int) get_option( 'smartimg_max_width_other', SMARTIMG_DEFAULT_MAX_WIDTH ); ?>" />
			<label for="smartimg_max_height_other"><?php esc_html_e( 'Max Height', 'smartimg' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="smartimg_max_height_other" value="<?php echo (int) get_option( 'smartimg_max_height_other', SMARTIMG_DEFAULT_MAX_HEIGHT ); ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'smartimg' ); ?>
		</td>
		</tr>


		<tr>
			<th scope="row">
				<label for='smartimg_quality' ><?php esc_html_e( 'JPG image quality', 'smartimg' ); ?>
			</th>
			<td>
				<input type='text' id='smartimg_quality' name='smartimg_quality' class='small-text' value='<?php echo smartimg_jpg_quality(); ?>' />
				<?php esc_html_e( 'Valid values are 1-100.', 'smartimg' ); ?>
				<p class='description'><?php esc_html_e( 'Only used when resizing images, does not affect thumbnails.', 'smartimg' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="smartimg_bmp_to_jpg"><?php esc_html_e( 'Convert BMP To JPG', 'smartimg' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="smartimg_bmp_to_jpg" name="smartimg_bmp_to_jpg" value="true" <?php checked( (bool) get_option( 'smartimg_bmp_to_jpg', SMARTIMG_DEFAULT_BMP_TO_JPG ) ); ?> />
				<?php esc_html_e( 'Only applies to new image uploads, existing BMP images cannot be converted or resized.', 'smartimg' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="smartimg_png_to_jpg"><?php esc_html_e( 'Convert PNG To JPG', 'smartimg' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="smartimg_png_to_jpg" name="smartimg_png_to_jpg" value="true" <?php checked( (bool) get_option( 'smartimg_png_to_jpg', SMARTIMG_DEFAULT_PNG_TO_JPG ) ); ?> />
				<?php
				printf(
					/* translators: %s: link to install EWWW Image Optimizer plugin */
					esc_html__( 'Only applies to new image uploads, existing images may be converted with %s.', 'smartimg' ),
					'<a href="' . admin_url( 'plugin-install.php?s=ewww+image+optimizer&tab=search&type=term' ) . '">EWWW Image Optimizer</a>'
				);
				?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="smartimg_delete_originals"><?php esc_html_e( 'Delete Originals', 'smartimg' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="smartimg_delete_originals" name="smartimg_delete_originals" value="true" <?php checked( get_option( 'smartimg_delete_originals' ) ); ?> />
				<?php esc_html_e( 'Remove the large pre-scaled originals that WordPress retains for thumbnail generation.', 'smartimg' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="smartimg_always_resize_jpg"><?php esc_html_e( 'Always Resize JPG', 'smartimg' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="smartimg_always_resize_jpg" name="smartimg_always_resize_jpg" value="true" <?php checked( get_option ( 'smartimg_always_resize_jpg' ) ); ?> />
				<?php esc_html_e( 'Always resize JPG on upload even if they are not bigger than max size', 'smartimg' ); ?>
			</td>
		</tr>
	</table>

	<p class="submit"><input type="submit" class="button-primary" value="<?php esc_attr_e( 'Save Changes', 'smartimg' ); ?>" /></p>

	</form>
	<?php

}

?>
