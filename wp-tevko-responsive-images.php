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
 * Version:           3.0.0
 * Author:            The RICG
 * Author URI:        http://responsiveimages.org/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Don't load the plugin directly.
defined( 'ABSPATH' ) or die( "No script kiddies please!" );

// List includes.
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

// Enqueue bundled version of the Picturefill library.
function tevkori_get_picturefill() {
	wp_enqueue_script( 'picturefill', plugins_url( 'js/picturefill.min.js', __FILE__ ), array(), '3.0.1', true );
}
add_action( 'wp_enqueue_scripts', 'tevkori_get_picturefill' );

if ( ! function_exists( '_wp_upload_dir_baseurl' ) ) :
/**
 * Caches and returns the base URL of the uploads directory.
 *
 * @since 3.0.0
 * @access private
 *
 * @return string The base URL, cached.
 */
function _wp_upload_dir_baseurl() {
	static $baseurl = null;

	if ( ! $baseurl ) {
		$uploads_dir = wp_upload_dir();
		$baseurl = $uploads_dir['baseurl'];
	}

	return $baseurl;
}
endif;

if ( ! function_exists( '_wp_get_image_size_from_meta' ) ) :
/**
 * Get the image size as array from its meta data.
 *
 * Used for responsive images.
 *
 * @since 3.0.0
 * @access private
 *
 * @param string $size_name  Image size. Accepts any valid image size name ('thumbnail', 'medium', etc.).
 * @param array  $image_meta The image meta data.
 * @return array|bool Array of width and height values in pixels (in that order)
 *                    or false if the size doesn't exist.
 */
function _wp_get_image_size_from_meta( $size_name, $image_meta ) {
	if ( $size_name === 'full' ) {
		return array(
			absint( $image_meta['width'] ),
			absint( $image_meta['height'] ),
		);
	} elseif ( ! empty( $image_meta['sizes'][$size_name] ) ) {
		return array(
			absint( $image_meta['sizes'][$size_name]['width'] ),
			absint( $image_meta['sizes'][$size_name]['height'] ),
		);
	}

	return false;
}
endif;

if ( ! function_exists( 'wp_get_attachment_image_srcset' ) ) :
/**
 * Retrieves the value for an image attachment's 'srcset' attribute.
 *
 * @since 3.0.0
 *
 * @param int          $attachment_id Image attachment ID.
 * @param array|string $size          Image size. Accepts any valid image size name, or an array of
 *                                    width and height values in pixels (in that order). Default 'medium'.
 * @param string       $image_url     Optional. The URL of the image.
 * @param array        $image_meta    Optional. The image meta data as returned by 'wp_get_attachment_metadata()'.
 * @return string|bool A string of sources with their descriptor and value,
 *                     for use as value in a 'srcset' attribute or false.
 */
function wp_get_attachment_image_srcset( $attachment_id, $size = 'medium', $image_url = null, $image_meta = null ) {
	// Get the image meta data if it isn't passed as argument.
	if ( ! is_array( $image_meta ) ) {
		$image_meta = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
	}

	// Bail early if image sizes info is not available.
	if ( empty( $image_meta['sizes'] ) ) {
		return false;
	}

	$image_sizes = $image_meta['sizes'];

	// Add full size to the '$image_sizes' array.
	$image_sizes['full'] = array(
		'width'  => $image_meta['width'],
		'height' => $image_meta['height'],
		'file'   => wp_basename( $image_meta['file'] ),
	);

	// Get the width and height of the image.
	if ( is_array( $size ) ) {
		$size_array = $size;
		$image_width  = absint( $size[0] );
		$image_height = absint( $size[1] );
	} elseif ( is_string( $size ) ) {
		$size_array   = _wp_get_image_size_from_meta( $size, $image_meta );
		$image_width  = $size_array[0];
		$image_height = $size_array[1];
	} else {
		return false;
	}

	// Bail early if the image has no width.
	if ( $image_width < 1 ) {
		return false;
	}

	// Get the image base URL and directory.
	$image_baseurl = _wp_upload_dir_baseurl();
	$dirname       = dirname( $image_meta['file'] );

	if ( $dirname !== '.' ) {
		$image_baseurl = path_join( $image_baseurl, $dirname );
	}

	// Calculate the image aspect ratio.
	$image_ratio = $image_height / $image_width;

	// Get the image URL if it's not passed as argument.
	if ( ! $image_url ) {
		$image = wp_get_attachment_image_src( $attachment_id, $size );

		if ( ! $image ) {
			return false;
		}

		$image_url = $image[0];
	}

	/*
	 * Images that have been edited in WordPress after being uploaded will
	 * contain a unique hash. Look for that hash and use it later to filter
	 * out images that are leftovers from previous versions.
	 */
	$image_edited = preg_match( '/-e[0-9]{13}/', $image_url, $image_edit_hash );

	/**
	 * Filter the maximum image width to be included in a 'srcset' attribute.
	 *
	 * @since 3.0.0
	 *
	 * @param int   $max_width  The maximum image width to be included in the 'srcset'. Default '1600'.
	 * @param array $size_array Array of width and height values in pixels (in that order).
	 */
	$max_srcset_image_width = apply_filters( 'max_srcset_image_width', 1600, $size_array );

	// Array to hold URL candidates.
	$sources = array();

	/*
	 * Loop through available images. Only use images that are resized
	 * versions of the same edit.
	 */
	foreach ( $image_sizes as $image ) {
		// Filter out images that are from previous edits.
		if ( $image_edited && ! strpos( $image['file'], $image_edit_hash[0] ) ) {
			continue;
		}

		// Filter out images that are wider than '$max_srcset_image_width'.
		if ( $max_srcset_image_width && $image['width'] > $max_srcset_image_width ) {
			continue;
		}

		$candidate_url = $image['file'];

		// Calculate the new image ratio.
		if ( $image['width'] ) {
			$image_ratio_compare = $image['height'] / $image['width'];
		} else {
			$image_ratio_compare = 0;
		}

		// If the new ratio differs by less than 0.01, use it.
		if ( abs( $image_ratio - $image_ratio_compare ) < 0.01 && ! array_key_exists( $candidate_url, $sources ) ) {
			// Add the URL, descriptor, and value to the sources array to be returned.
			$sources[ $image['width'] ] = array(
				'url'        => path_join( $image_baseurl, $candidate_url ),
				'value'      => $image['width'],
				'descriptor' => 'w',
			);
		}
	}

	/**
	 * Filter the array used to create the 'srcset' value string.
	 *
	 * @since 3.0.0
	 *
	 * @param array        $sources       An array of image URLs, values, and descriptors.
	 * @param int          $attachment_id Image attachment ID.
	 * @param array|string $size          Image size. Image size name, or an array of width and height
	 *                                    values in pixels (in that order).
	 * @param string       $image_url     The URL of the image.
	 * @param array        $image_meta    The image meta data as returned by 'wp_get_attachment_metadata()'.
	 */
	$sources = apply_filters( 'wp_get_attachment_image_srcset', array_values( $sources ), $attachment_id, $size, $image_url, $image_meta );

	// Only return a 'srcset' value if there is more than one source.
	if ( count( $sources ) < 2 ) {
		return false;
	}

	// Create the 'srcset' value string.
	$srcset = '';

	foreach ( $sources as $source ) {
		$srcset .= $source['url'] . ' ' . $source['value'] . $source['descriptor'] . ', ';
	}

	return rtrim( $srcset, ', ' );
}
endif;

/**
 * Returns an array of image sources for a 'srcset' attribute.
 *
 * @since 2.1.0
 * @deprecated 3.0 Use 'wp_get_attachment_image_srcset()'
 * @see 'wp_get_attachment_image_srcset()'
 *
 * @param int          $id   Image attachment ID.
 * @param array|string $size Image size. Accepts any valid image size, or an array of width and height
 *                           values in pixels (in that order). Default 'medium'.
 * @return array|bool An array of 'srcset' values or false.
 */
function tevkori_get_srcset_array( $id, $size = 'medium' ) {
	_deprecated_function( __FUNCTION__, '3.0.0', 'wp_get_attachment_image_srcset()' );

	$srcset = wp_get_attachment_image_srcset( $id, $size );

	// Transform the 'srcset' value string to a pre-core style array.
	$sources = explode( ', ', $srcset );
	$arr = array();

	foreach ( $sources as $source ) {
		$split = explode( ' ', $source );
		$width = rtrim( $split[1], "w" );
		$arr[ $width ] = $source;
	}

	/**
	 * Filter the output of 'tevkori_get_srcset_array()'.
	 *
	 * @since 2.4.0
	 * @deprecated 3.0 Use 'wp_get_attachment_image_srcset'
	 * @see 'wp_get_attachment_image_srcset'
	 *
	 * @param array        $arr   An array of image sources.
	 * @param int          $id    Attachment ID for image.
	 * @param array|string $size  Image size. Image size or an array of width and height
	 *                            values in pixels (in that order).
	 */
	return apply_filters( 'tevkori_srcset_array', $arr, $id, $size );
}

/**
 * Returns the value for a 'srcset' attribute.
 *
 * @since 2.3.0
 * @deprecated 3.0 Use 'wp_get_attachment_image_srcset()'
 * @see 'wp_get_attachment_image_srcset()'
 *
 * @param int          $id   Image attachment ID.
 * @param array|string $size Image size. Accepts any valid image size, or an array of width and height
 *                           values in pixels (in that order). Default 'medium'.
 * @return string|bool A 'srcset' value string or false.
 */
function tevkori_get_srcset( $id, $size = 'medium' ) {
	_deprecated_function( __FUNCTION__, '3.0.0', 'wp_get_attachment_image_srcset()' );

	if ( has_filter( 'tevkori_srcset_array' ) ) {
		$srcset_array = tevkori_get_srcset_array( $id, $size );

		return implode( ', ', $srcset_array );
	} else {
		return wp_get_attachment_image_srcset( $id, $size );
	}
}

/**
 * Returns a 'srcset' attribute.
 *
 * @since 2.1.0
 * @deprecated 3.0 Use 'wp_get_attachment_image_srcset()'
 * @see 'wp_get_attachment_image_srcset()'
 *
 * @param int          $id   Image attachment ID.
 * @param array|string $size Image size. Accepts any valid image size, or an array of width and height
 *                           values in pixels (in that order). Default 'medium'.
 * @return string|bool A full 'srcset' string or false.
 */
function tevkori_get_srcset_string( $id, $size = 'medium' ) {
	_deprecated_function( __FUNCTION__, '3.0.0', 'wp_get_attachment_image_srcset()' );

	if ( has_filter( 'tevkori_srcset_array' ) ) {
		$srcset_value = tevkori_get_srcset( $id, $size );

		return $srcset_value ? 'srcset="' . $srcset_value . '"' : false;
	} else {
		$srcset_value = wp_get_attachment_image_srcset( $id, $size );

		return $srcset_value ? 'srcset="' . $srcset_value . '"' : false;
	}
}

if ( ! function_exists( 'wp_get_attachment_image_sizes' ) ) :
/**
 * Retrieves the value for an image attachment's 'sizes' attribute.
 *
 * @since 3.0.0
 *
 * @param array|string $size          Image size. Accepts any valid image size name, or an array of
 *                                    width and height values in pixels (in that order). Default 'medium'.
 * @param int          $attachment_id Optional. Image attachment ID. Required if `$size` is an image size name
 *                                    and `$image_meta` is omited. Otherwise used to pass to the filter.
 * @param array        $image_meta    Optional. The image meta data as returned by 'wp_get_attachment_metadata()'.
 * @return string|bool A string of size values for use as value in a 'sizes' attribute or false.
 */
function wp_get_attachment_image_sizes( $size = 'medium', $attachment_id = 0, $image_meta = null ) {
	$width = 0;

	if ( is_array( $size ) ) {
		$width = absint( $size[0] );
	} elseif ( is_string( $size ) ) {
		if ( ! $image_meta && $attachment_id ) {
			$image_meta = wp_get_attachment_metadata( $attachment_id );
		}
		if ( is_array( $image_meta ) ) {
			$size_array = _wp_get_image_size_from_meta( $size, $image_meta );
			if ( $size_array ) {
				$width = $size_array[0];
			}
		}
	} else {
		return false;
	}

	// Setup the default 'sizes' attribute.
	$sizes = sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $width );

	/**
	 * Filter the output of 'wp_get_attachment_image_sizes()'.
	 *
	 * @since 3.0.0
	 *
	 * @param string       $sizes         A source size value for use in a 'sizes' attribute.
	 * @param array|string $size          Image size name, or an array of width and height
	 *                                    values in pixels (in that order).
	 * @param int          $attachment_id Image attachment ID of the original image, or 0.
	 * @param array        $image_meta    The image meta data as returned by 'wp_get_attachment_metadata()',
	 *                                    or null.
	 */
	return apply_filters( 'wp_get_attachment_image_sizes', $sizes, $size, $attachment_id, $image_meta );
}
endif;

/**
 * Returns the value for a 'sizes' attribute.
 *
 * @since 2.2.0
 * @deprecated 3.0 Use 'wp_get_attachment_image_sizes()'
 * @see 'wp_get_attachment_image_sizes()'
 *
 * @param int          $id   Image attachment ID.
 * @param array|string $size Image size. Accepts any valid image size, or an array of width and height
 *                           values in pixels (in that order). Default 'medium'.
 * @param array        $args {
 *     Optional. Arguments to retrieve posts.
 *
 *     @type array|string $sizes An array or string containing of size information.
 *     @type int          $width A single width value used in the default 'sizes' string.
 * }
 * @return string|bool A valid source size value for use in a 'sizes' attribute or false.
 */
function tevkori_get_sizes( $id, $size = 'medium', $args = null ) {
	_deprecated_function( __FUNCTION__, '3.0.0', 'wp_get_attachment_image_sizes()' );

	if ( $arg || has_filter( 'tevkori_image_sizes_args' ) ) {
		// Try to get the image width from '$args' first.
		if ( is_array( $args ) && ! empty( $args['width'] ) ) {
			$img_width = (int) $args['width'];
		} elseif ( $img = image_get_intermediate_size( $id, $size ) ) {
			list( $img_width, $img_height ) = image_constrain_size_for_editor( $img['width'], $img['height'], $size );
		}

		// Bail early if '$img_width' isn't set.
		if ( ! $img_width ) {
			return false;
		}

		// Set the image width in pixels.
		$img_width = $img_width . 'px';

		// Set up our default values.
		$defaults = array(
			'sizes' => array(
				array(
					'size_value' => '100vw',
					'mq_value'   => $img_width,
					'mq_name'    => 'max-width'
				),
				array(
					'size_value' => $img_width
				),
			)
		);

		$args = wp_parse_args( $args, $defaults );

		/**
		* Filter arguments used to create the 'sizes' attribute value.
		*
		* @since 2.4.0
		* @deprecated 3.0 Use 'wp_get_attachment_image_sizes'
		* @see 'wp_get_attachment_image_sizes'
		*
		* @param array        $args An array of arguments used to create a 'sizes' attribute.
		* @param int          $id   Post ID of the original image.
		* @param array|string $size Image size. Image size or an array of width and height
		*                           values in pixels (in that order).
		*/
		$args = apply_filters( 'tevkori_image_sizes_args', $args, $id, $size );

		// If sizes is passed as a string, just use the string.
		if ( is_string( $args['sizes'] ) ) {
			$size_list = $args['sizes'];

		// Otherwise, breakdown the array and build a sizes string.
		} elseif ( is_array( $args['sizes'] ) ) {

			$size_list = '';

			foreach ( $args['sizes'] as $size ) {

				// Use 100vw as the size value unless something else is specified.
				$size_value = ( $size['size_value'] ) ? $size['size_value'] : '100vw';

				// If a media length is specified, build the media query.
				if ( ! empty( $size['mq_value'] ) ) {

					$media_length = $size['mq_value'];

					// Use max-width as the media condition unless min-width is specified.
					$media_condition = ( ! empty( $size['mq_name'] ) ) ? $size['mq_name'] : 'max-width';

					// If a media length was set, create the media query.
					$media_query = '(' . $media_condition . ": " . $media_length . ') ';

				} else {

					// If no media length was set, '$media_query' is blank.
					$media_query = '';
				}

				// Add to the source size list string.
				$size_list .= $media_query . $size_value . ', ';
			}

			// Remove the trailing comma and space from the end of the string.
			$size_list = substr( $size_list, 0, -2 );
		}

		// If '$size_list' is defined set the string, otherwise set false.
		return ( $size_list ) ? $size_list : false;
	} else {
		return wp_get_attachment_image_sizes( $size, $id );
	}
}

/**
 * Returns a 'sizes' attribute.
 *
 * @since 2.2.0
 * @deprecated 3.0 Use 'wp_get_attachment_image_sizes()'
 * @see 'wp_get_attachment_image_sizes()'
 *
 * @param int          $id   Image attachment ID.
 * @param array|string $size Image size. Accepts any valid image size, or an array of width and height
 *                           values in pixels (in that order). Default 'medium'.
 * @param array        $args {
 *     Optional. Arguments to retrieve posts.
 *
 *     @type array|string $sizes An array or string containing of size information.
 *     @type int          $width A single width value used in the default 'sizes' string.
 * }
 * @return string|bool A valid source size list as a 'sizes' attribute or false.
 */
function tevkori_get_sizes_string( $id, $size = 'medium', $args = null ) {
	_deprecated_function( __FUNCTION__, '3.0.0', 'wp_get_attachment_image_sizes()' );

	if ( $arg || has_filter( 'tevkori_image_sizes_args' ) ) {
		$sizes = tevkori_get_sizes( $id, $size, $arg );
	} else {
		$sizes = wp_get_attachment_image_sizes( $size, $id );
	}

	return $sizes ? 'sizes="' . esc_attr( $sizes ) . '"' : false;
}

if ( ! function_exists( 'wp_make_content_images_responsive' ) ) :
/**
 * Filters 'img' elements in post content to add 'srcset' and 'sizes' attributes.
 *
 * @since 3.0.0
 *
 * @see 'wp_image_add_srcset_and_sizes()'
 *
 * @param string $content The raw post content to be filtered.
 * @return string Converted content with 'srcset' and 'sizes' attributes added to images.
 */
 function wp_make_content_images_responsive( $content ) {
	$images = get_media_embedded_in_content( $content, 'img' );

	$selected_images = $attachment_ids = array();

	foreach( $images as $image ) {
		if ( false === strpos( $image, ' srcset="' ) && preg_match( '/wp-image-([0-9]+)/i', $image, $class_id ) &&
			( $attachment_id = absint( $class_id[1] ) ) ) {

			/*
			 * If exactly the same image tag is used more than once, overwrite it.
			 * All identical tags will be replaced later with 'str_replace()'.
			 */
			$selected_images[ $image ] = $attachment_id;
			// Overwrite the ID when the same image is included more than once.
			$attachment_ids[ $attachment_id ] = true;
		}
	}

	if ( count( $attachment_ids ) > 1 ) {
		/*
		 * Warm object cache for use with 'get_post_meta()'.
		 *
		 * To avoid making a database call for each image, a single query
		 * warms the object cache with the meta information for all images.
		 */
		update_meta_cache( 'post', array_keys( $attachment_ids ) );
	}

	foreach ( $selected_images as $image => $attachment_id ) {
		$image_meta = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		$content = str_replace( $image, wp_image_add_srcset_and_sizes( $image, $image_meta, $attachment_id ), $content );
	}

	return $content;
}
endif;

if ( ! has_filter( 'the_content', 'wp_make_content_images_responsive' ) ) :
add_filter( 'the_content', 'wp_make_content_images_responsive', 5, 1 );
endif;

/**
 * Filter to add 'srcset' and 'sizes' attributes to images in the post content.
 *
 * @since 2.5.0
 * @deprecated 3.0 Use 'wp_make_content_images_responsive()'
 * @see 'wp_make_content_images_responsive()'
 *
 * @param string $content The raw post content to be filtered.
 * @return string Converted content with 'srcset' and 'sizes' added to images.
 */
function tevkori_filter_content_images( $content ) {
	_deprecated_function( __FUNCTION__, '3.0.0', 'wp_make_content_images_responsive()' );
	return wp_make_content_images_responsive( $content );
}

if ( ! function_exists( 'wp_image_add_srcset_and_sizes' ) ) :
/**
 * Adds 'srcset' and 'sizes' attributes to an existing 'img' element.
 *
 * @since 3.0.0
 *
 * @see 'wp_get_attachment_image_srcset()'
 * @see 'wp_get_attachment_image_sizes()'
 *
 * @param string $image         An HTML 'img' element to be filtered.
 * @param array  $image_meta    The image meta data as returned by 'wp_get_attachment_metadata()'.
 * @param int    $attachment_id Image attachment ID.
 * @return string Converted 'img' element with 'srcset' and 'sizes' attributes added.
 */
 function wp_image_add_srcset_and_sizes( $image, $image_meta, $attachment_id ) {
	// Ensure the image meta exists.
	if ( empty( $image_meta['sizes'] ) ) {
		return $image;
	}

	$src = preg_match( '/src="([^"]+)"/', $image, $match_src ) ? $match_src[1] : '';
	list( $image_url ) = explode( '?', $src );

	// Return early if we couldn't get the image source.
	if ( ! $image_url ) {
		return $image;
	}

	// Bail early if an image has been inserted and later edited.
	if ( preg_match( '/-e[0-9]{13}/', $image_meta['file'], $img_edit_hash ) &&
		strpos( wp_basename( $image_url ), $img_edit_hash[0] ) === false ) {

		return $image;
	}

	$width  = preg_match( '/ width="([0-9]+)"/',  $image, $match_width  ) ? absint( $match_width[1] )  : 0;
	$height = preg_match( '/ height="([0-9]+)"/', $image, $match_height ) ? absint( $match_height[1] ) : 0;

	if ( ! $width || ! $height ) {
		/*
		 * If attempts to parse the size value failed, attempt to use the image meta data to match
		 * the image file name from 'src' against the available sizes for an attachment.
		 */
		$image_filename = wp_basename( $image_url );

		if ( $image_filename === wp_basename( $image_meta['file'] ) ) {
			$width = absint( $image_meta['width'] );
			$height = absint( $image_meta['height'] );
		} else {
			foreach( $image_meta['sizes'] as $image_size_data ) {
				if ( $image_filename === $image_size_data['file'] ) {
					$width = absint( $image_size_data['width'] );
					$height = absint( $image_size_data['height'] );
					break;
				}
			}
		}
	}

	// Return if we don't have a width or height.
	if ( ! $width || ! $height ) {
		return $image;
	}

	$size_array = array( $width, $height );

	// Get the 'srcset' and 'sizes' values.
	$srcset = wp_get_attachment_image_srcset( $attachment_id, $size_array, $image_url, $image_meta );
	$sizes  = wp_get_attachment_image_sizes( $size_array, $attachment_id, $image_meta );

	if ( $srcset && $sizes ) {
		// Format the 'srcset' and 'sizes' string and escape attributes.
		$srcset_and_sizes = sprintf( ' srcset="%s" sizes="%s"', esc_attr( $srcset ), esc_attr( $sizes ) );

		// Add 'srcset' and 'sizes' attributes to the image markup.
		$image = preg_replace( '/<img ([^>]+?)[\/ ]*>/', '<img $1' . $srcset_and_sizes . ' />', $image );
	}

	return $image;
 }
endif;

/**
 * Adds 'srcset' and 'sizes' attributes to image elements.
 *
 * @since 2.6.0
 * @deprecated 3.0 Use 'wp_image_add_srcset_and_sizes()'
 * @see 'wp_image_add_srcset_and_sizes()'
 *
 * @param string $image An HTML 'img' element to be filtered.
 * @return string Converted 'img' element with 'srcset' and 'sizes' added.
 */
function tevkori_img_add_srcset_and_sizes( $image ) {
	_deprecated_function( __FUNCTION__, '3.0.0', 'wp_image_add_srcset_and_sizes()' );
	return wp_image_add_srcset_and_sizes( $image );
}

/**
 * Filter to add 'srcset' and 'sizes' attributes to post thumbnails and gallery images.
 *
 * @see 'wp_get_attachment_image_attributes'
 * @return array Attributes for image.
 */
function tevkori_filter_attachment_image_attributes( $attr, $attachment, $size ) {
	// Set srcset and sizes if not already present and both were returned.
	if ( empty( $attr['srcset'] ) ) {
		$srcset = wp_get_attachment_image_srcset( $attachment->ID, $size );
		$sizes  = wp_get_attachment_image_sizes( $size, $attachment->ID );

		if ( $srcset && $sizes ) {
			$attr['srcset'] = $srcset;

			if ( empty( $attr['sizes'] ) ) {
				$attr['sizes'] = $sizes;
			}
		}
	}

	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'tevkori_filter_attachment_image_attributes', 0, 3 );
