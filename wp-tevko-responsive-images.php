<?php
/**
 * @link              https://github.com/ResponsiveImagesCG/wp-tevko-responsive-images
 * @since             2.0.0
 * @package           http://www.smashingmagazine.com/2015/02/24/ricg-responsive-images-for-wordpress/
 *
 * @wordpress-plugin
 * Plugin Name:       RICG Responsive Images
 * Plugin URI:        https://github.com/ResponsiveImagesCG/wp-tevko-responsive-images
 * Description:       Bringing automatic default responsive images to WordPress
 * Version:           3.1.1
 * Author:            The RICG
 * Author URI:        http://responsiveimages.org/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Don't load the plugin directly.
defined( 'ABSPATH' ) or die( "No script kiddies please!" );

/*
 * Include the advanced image compression files.
 * See readme.md for more information.
 */
if ( class_exists( 'Imagick' ) ) {
	require_once( plugin_dir_path( __FILE__ ) . 'class-respimg.php' );
	require_once( plugin_dir_path( __FILE__ ) . 'class-wp-image-editor-respimg.php' );

	/**
	 * Filter to add php-respimg as an image editor.
	 *
	 * @since 2.3.0
	 *
	 * @return array Editors.
	 **/
	function tevkori_wp_image_editors( $editors ) {
		if ( current_theme_supports( 'advanced-image-compression' ) ) {
			array_unshift( $editors, 'WP_Image_Editor_Respimg' );
		}

		return $editors;
	}
	add_filter( 'wp_image_editors', 'tevkori_wp_image_editors' );
}

// Load the deprecated core functions.
require_once( plugin_dir_path( __FILE__ ) . 'wp-tevko-deprecated-functions.php' );

/*
 * Load copies of our core functions if the plugin is installed on a version of WordPress
 * previous to 4.4, when the functions were added to core.
 */
if ( ! function_exists( 'wp_get_attachment_image_srcset' ) ) {
	require_once( plugin_dir_path( __FILE__ ) . 'wp-tevko-core-functions.php' );
}

// Enqueue bundled version of the Picturefill library.
function tevkori_get_picturefill() {
	wp_enqueue_script( 'picturefill', plugins_url( 'js/picturefill.min.js', __FILE__ ), array(), '3.0.1', true );
}
add_action( 'wp_enqueue_scripts', 'tevkori_get_picturefill' );

/**
 * Filter to add 'srcset' and 'sizes' attributes to post thumbnails and gallery images.
 * The filter is added to the hook in wp-tevko-core-functions.php because
 * it is only needed on a version of WordPress previous to 4.4.
 *
 * @since 2.3.0
 * @see 'wp_get_attachment_image_attributes'
 *
 * @return array Attributes for image.
 */
function tevkori_filter_attachment_image_attributes( $attr, $attachment, $size ) {
	// Set 'srcset' and 'sizes' if not already present and both were returned.
	if ( empty( $attr['srcset'] ) ) {
		$srcset = wp_get_attachment_image_srcset( $attachment->ID, $size );
		$sizes  = wp_get_attachment_image_sizes( $attachment->ID, $size );

		if ( $srcset && $sizes ) {
			$attr['srcset'] = $srcset;

			if ( empty( $attr['sizes'] ) ) {
				$attr['sizes'] = $sizes;
			}
		}
	}

	return $attr;
}

/**
 * Backward compatibility shim for 'data-sizes' attributes in content.
 *
 * Prior to version 2.5 a 'srcset' and 'data-sizes' attribute were added to the image
 * while inserting the image in the content. We replace the 'data-sizes' attribute by
 * a 'sizes' attribute.
 *
 * @since 3.0.0
 *
 * @param string $content The content to filter;
 * @return string The filtered content with `data-sizes` replaced by `sizes` attributes.
 */
function tevkori_replace_data_sizes( $content ) {
	return str_replace( ' data-sizes="', ' sizes="', $content );
}
add_filter( 'the_content', 'tevkori_replace_data_sizes' );

/**
 * Checks if there are images with 'data-sizes' and 'srcset' attributes in the post content.
 *
 * @since 3.2.0
 * @access private
 *
 * @return int Number of posts that contain images with 'data-sizes' and 'srcset' attributes.
 */
function _tevkori_find_respimg_attr() {
	$matches = get_transient( 'tevkori_find_respimg_attr' );

	// Strict comparison because the transient value can be 0, but will be false if it has not been set yet.
	if ( false === $matches ) {
		global $wpdb;

		$string = '<img[^>]+data-sizes="[^"]+" srcset="[^"]+"';

		$sql = 	"
			SELECT COUNT(*)
			FROM $wpdb->posts
			WHERE post_type != 'revision' AND post_content RLIKE %s
			";

		$matches = $wpdb->get_var( $wpdb->prepare( $sql, $string ) );

		set_transient( 'tevkori_find_respimg_attr', $matches, YEAR_IN_SECONDS );
	}

	return $matches;
}

/**
 * Removes the 'tevkori_find_respimg_attr' transient upon plugin deactivation.
 *
 * @since 3.2.0
 * @access private
 * @see 'register_deactivation_hook'
 */
function _tevkori_delete_transient() {
	delete_transient( 'tevkori_find_respimg_attr' );
}
register_deactivation_hook( __FILE__, '_tevkori_delete_transient' );

/**
 * Adds a menu page to the Tools menu if images with 'data-sizes' and 'srcset' attributes
 * have been found and user has sufficient capability.
 *
 * @since 3.2.0
 * @access private
 * @see 'add_management_page()'
 */
function _tevkori_add_menu_page() {
	$matches = _tevkori_find_respimg_attr();

	if ( $matches ) {
		add_management_page(
			'RICG Responsive Images',
			'RICG Responsive Images',
			'activate_plugins',
			'ricg-responsive-images',
			'_tevkori_menu_page'
		);
	}
}
add_action( 'admin_menu', '_tevkori_add_menu_page' );

/**
 * Callback function that outputs the content for the menu page.
 *
 * @since 3.2.0
 * @access private
 * @see '_tevkori_add_menu_page()'
 */
function _tevkori_menu_page() {
	global $wp_version;

	$matches = _tevkori_find_respimg_attr();
	?>
	<div class="wrap">
		<h2>RICG Responsive Images Plugin</h2>

		<div class="notice notice-warning">
			<p>We have found <?php if ( 1 == $matches ) { echo '1 post'; } else { echo $matches . ' posts'; } ?> with images that contain <code>data-sizes</code> and <code>srcset</code> attributes. We strongly recommend to remove those attributes!</p>
		</div>

		<h3>Explanation</h3>

		<p>To make an image responsive the <code>img</code> element needs to have a <code>srcset</code> and <code>sizes</code> attribute. Prior to version 2.5, this plugin added a <code>srcset</code> and <code>data-sizes</code> attribute while inserting the image in the content and replaced <code>data-sizes</code> by <code>sizes</code> before a post was being displayed. Since version 2.5 those attributes are no longer added while inserting an image in the content. Instead, both <code>srcset</code> and <code>sizes</code> are added to images by using a filter before a post is being displayed, but <em>not</em> if an image already has a <code>srcset</code> attribute.</p>

		<p>The plugin still replaces <code>data-sizes</code> by <code>sizes</code> to make sure that images that have been inserted while version 2.4 or older was active have both the <code>srcset</code> and <code>sizes</code> attribute. However, after deactivating the plugin those images won't get a <code>sizes</code> attribute anymore while they do have a <code>srcset</code>. This is <strong>invalid markup</strong> and results in images being displayed at a <strong>wrong size</strong>!</p>

		<p>Besides this, the functions that calculate the values for the <code>srcset</code> and <code>sizes</code> attributes have been improved over time and filter hooks have been added to make it possible to change the values in specific situations. As long as images already have a <code>srcset</code> attribute you won't benefit from these improvements.</p>

		<p>Therefor it's strongly recommended to remove <code>srcset</code> and <code>data-sizes</code> attributes from <code>img</code> elements in the post content. You can do this automatically with the cleanup tool on this page or manually.</p>

		<?php if ( $wp_version >= 4.4 ) : ?>
		<h4>WordPress 4.4+</h4>

		<p>As from WordPress 4.4 the <code>srcset</code> and <code>sizes</code> attributes are added by WordPress itself. This is done in exactly the same way as it was done by this plugin, meaning that no attributes are added if an image already has a <code>srcset</code> attribute.</p>

		<h4>Do I Still Need This Plugin After Removing The Attributes?</h4>

		<p>No, you would no longer need to use this plugin to make images responsive if the <code>srcset</code> and <code>data-sizes</code> attributes have been removed from all images. However, you can keep using it to benefit from the following two features:</p>

		<ol>
			<li>The plugin loads the Picturefill polyfill, a small JavaScript file that makes responsive images work on older browsers that don't offer native support</li>
			<li>Advanced image compression (via opt-in)</li>
		</ol>

		<p>See the <a href="https://wordpress.org/plugins/ricg-responsive-images/">plugin page</a> and the <a href="https://github.com/ResponsiveImagesCG/wp-tevko-responsive-images#documentation">plugin documentation</a> for more information.</p>
		<?php endif; ?>

		<h3>Cleanup Tool</h3>

		<p>This tool automatically removes <code>srcset</code> and <code>data-sizes</code> attributes from all images in the content of your posts. It updates the post content in the database. This can't be undone and if something goes wrong you might lose your content so it's very important to <strong>make a backup of your database</strong> before proceeding!</p>

		<p>After succesfully removing these attributes from all images this page will disappear from the Tools menu and you will no longer see a warning on the dashboard and plugin menu page.</p>

		<form action="<?php echo admin_url( 'admin-post.php' ); ?>" method="post">
			<input type="hidden" name="action" value="tevkori_cleanup_post_content">
			<?php wp_nonce_field( 'tevkori_cleanup_post_content', 'tevkori_cleanup_post_content_nonce' ); ?>
			<label>
				<input type="checkbox" name="backup">
				Yes, I have made a backup of the database of this WordPress installation.
			</label>
			<?php submit_button( 'Cleanup', 'large', 'tevkori-cleanup-post-content' ); ?>
		</form>

		<h3>Manually Remove</h3>

		<p>To manually remove the <code>srcset</code> and <code>data-sizes</code> attributes you have to edit each post and page that contains images that were inserted while version 2.4 or older of this plugin was active. In the editor switch from the "Visual" to "Text" tab in the top right corner in order to see image markup instead of the images itself. Look for <code>&lt;img ... &gt;</code> elements and delete <code>data-sizes="..." srcset="..."</code>.</p>

		<p>After you have removed these attributes from all images you can run this check. If there are no more images with these attributes this page will disappear from the Tools menu and you will no longer see a warning on the dashboard and plugin menu page.</p>

		<form action="<?php echo admin_url( 'admin-post.php' ); ?>" method="post">
			<input type="hidden" name="action" value="tevkori_check_post_content">
			<?php wp_nonce_field( 'tevkori_check_post_content', 'tevkori_check_post_content_nonce' ); ?>
			<?php submit_button( 'Check', 'large', 'tevkori-check-post-content' ); ?>
		</form>

		<h3>Support</h3>

		<p>If you have any questions, please post a message on the <a href="https://wordpress.org/support/plugin/ricg-responsive-images">support forum</a> of this plugin.</p>
	</div>
	<?php
}

/**
 * Removes 'data-sizes' and 'srcset' attributes from image elements in the post content.
 *
 * @since 3.2.0
 * @access private
 * @see 'admin_post'
 */
function _tevkori_cleanup_post_content() {
	check_admin_referer( 'tevkori_cleanup_post_content', 'tevkori_cleanup_post_content_nonce' );

	if ( isset( $_POST['backup'] ) ) {
		global $wpdb;

		$string = '<img[^>]+data-sizes="[^"]+" srcset="[^"]+"';

		$sql = "
			SELECT ID, post_content
			FROM $wpdb->posts
			WHERE post_type != 'revision' AND post_content RLIKE %s
		";

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $string ), ARRAY_N );

		if ( ! empty( $results ) ) {
			foreach ( $results as $result ) {
				$post_content = preg_replace( '/<img([^>]+?) data-sizes="([^"]+?)" srcset="([^"]+?)"/', '<img $1', $result[1] );

				$post = array(
					'ID'           => $result[0],
					'post_content' => $post_content,
				);

				wp_update_post( $post );
			}
		}

		delete_transient( 'tevkori_find_respimg_attr' );

		$matches = _tevkori_find_respimg_attr();

		if ( $matches ) {
			wp_redirect( admin_url( 'tools.php?page=ricg-responsive-images&ricg-cleanup=error' ) );
		} else {
			wp_redirect( admin_url( 'plugins.php?ricg-cleanup=success' ) );
		}
	} else {
		wp_redirect( admin_url( 'tools.php?page=ricg-responsive-images&ricg-cleanup=backup' ) );
	}

	exit();
}
add_action( 'admin_post_tevkori_cleanup_post_content', '_tevkori_cleanup_post_content' );

/**
 * Triggers a new check to see if there are images with 'data-sizes' and 'srcset' attributes in the post content.
 *
 * @since 3.2.0
 * @access private
 * @see 'admin_post'
 */
function _tevkori_check_post_content() {
	check_admin_referer( 'tevkori_check_post_content', 'tevkori_check_post_content_nonce' );

	delete_transient( 'tevkori_find_respimg_attr' );

	$matches = _tevkori_find_respimg_attr();

	if ( $matches ) {
		wp_redirect( admin_url( 'tools.php?page=ricg-responsive-images&ricg-cleanup=notdone' ) );
	} else {
		wp_redirect( admin_url( 'plugins.php?ricg-cleanup=success' ) );
	}

	exit();
}
add_action( 'admin_post_tevkori_check_post_content', '_tevkori_check_post_content' );

/**
 * Displays admin notices.
 *
 * @since 3.2.0
 * @access private
 * @see 'admin_notices'
 */
function _tevkori_admin_notices() {
	global $pagenow;

	$matches = _tevkori_find_respimg_attr();

	if ( $matches && ( $pagenow == 'plugins.php' || $pagenow == 'index.php' ) ) {
		echo '<div class="notice notice-warning is-dismissible"><p>The RICG Responsive Images plugin needs your attention. Please visit the <a href="tools.php?page=ricg-responsive-images">plugin page in the Tools menu</a>.</p></div>';
	}

	if ( $pagenow == 'plugins.php' && isset( $_GET['ricg-cleanup'] ) ) {
		if ( 'success' == $_GET['ricg-cleanup'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>Done! All <code>data-sizes</code> and <code>srcset</code> attributes have been succesfully removed from your images!</p></div>';
		}
	}
	if ( $pagenow == 'tools.php' && isset( $_GET['ricg-cleanup'] ) ) {
		$notice = $_GET['ricg-cleanup'];

		if ( 'notdone' == $notice ) {
		    echo '<div class="notice notice-error is-dismissible"><p>Check finished. Not all <code>data-sizes</code> and <code>srcset</code> attributes have been removed yet!</p></div>';
		} elseif ( 'backup' == $notice ) {
		    echo '<div class="notice notice-warning is-dismissible"><p>Please make a backup of your database before using the cleanup tool!</p></div>';
		} elseif ( 'error' == $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>Something went wrong! We recommend to check your posts if something is broken, revert to the backup of your database if necessary, and remove the <code>data-sizes</code> and <code>srcset</code> attributes manually.</p></div>';
		}
	}
}
add_action( 'admin_notices', '_tevkori_admin_notices' );
