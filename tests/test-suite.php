<?php

class RICG_Responsive_Images_Tests extends WP_UnitTestCase {

	protected static $large_id;

	protected static $test_file_name;

	public static function setUpBeforeClass() {
		self::$test_file_name = dirname(__FILE__) . '/data/test-large.png';
		self::$large_id = self::create_upload_object( self::$test_file_name );

		// Keep default themes from ruining things.
		// remove_action( 'after_setup_theme', 'twentyfifteen_setup' );
		// remove_action( 'after_setup_theme', 'twentysixteen_setup' );

		// Remove Twenty Sixteen sizes filter for now.
		remove_filter( 'wp_calculate_image_sizes', 'twentysixteen_content_image_sizes_attr' );
	}

	public static function tearDownAfterClass() {
		wp_delete_attachment( self::$large_id );
	}

	public static function create_upload_object( $filename, $parent = 0 ) {
		$contents = file_get_contents( $filename );
		$upload = wp_upload_bits(basename( $filename ), null, $contents );
		$type = '';

		if ( ! empty($upload['type'] ) ) {
			$type = $upload['type'];
		} else {
			$mime = wp_check_filetype( $upload['file'] );
			if ( $mime )
				$type = $mime['type'];
		}

		$attachment = array(
			'post_title'     => basename( $upload['file'] ),
			'post_content'   => '',
			'post_type'      => 'attachment',
			'post_parent'    => $parent,
			'post_mime_type' => $type,
			'guid'           => $upload[ 'url' ],
		);

		// Save the data.
		$id = wp_insert_attachment( $attachment, $upload[ 'file' ], $parent );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );

		return $id;
	}

	/* OUR TESTS */

	/**
	 * @expectedDeprecated tevkori_get_srcset_array
	 */
	function test_tevkori_get_srcset_array() {
		global $_wp_additional_image_sizes;

		// Make an image.
		$id = self::$large_id;
		$srcset = tevkori_get_srcset_array( $id, 'medium' );

		$year_month = date('Y/m');
		$image_meta = wp_get_attachment_metadata( $id );

		$intermediates = array( 'medium', 'medium_large', 'large', 'full' );

		// Add any soft crop intermediate sizes.
		foreach ( $_wp_additional_image_sizes as $name => $additional_size ) {
			if ( ! $_wp_additional_image_sizes[$name]['crop'] || 0 === $_wp_additional_image_sizes[$name]['height'] ) {
				$intermediates[] = $name;
			}
		}

		foreach( $image_meta['sizes'] as $name => $size ) {
			// Whitelist the sizes that should be included so we pick up 'medium_large' in 4.4.
			if ( in_array( $name, $intermediates ) ) {
				$expected[$size['width']] = 'http://example.org/wp-content/uploads/' . $year_month = date('Y/m') . '/' . $size['file'] . ' ' . $size['width'] . 'w';
			}
		}

		// Add the full size width at the end.
		$expected[$image_meta['width']] = 'http://example.org/wp-content/uploads/' . $image_meta['file'] . ' ' . $image_meta['width'] .'w';

		$this->assertSame( $expected, $srcset );
	}

	/**
	 * A test filter for tevkori_get_srcset_array() that removes any sources
	 * that are larger than 500px wide.
	 */
	function _test_tevkori_srcset_array( $array ) {
		foreach ( $array as $size => $file ) {
			if ( $size > 500 ) {
				unset( $array[ $size ] );
			}
		}
		return $array;
	}

	/**
	 * @expectedDeprecated tevkori_get_srcset_array
	 * @expectedException PHPUnit_Framework_Error_Notice
	 */
	function test_filter_tevkori_srcset_array() {
		// Add test filter.
		add_filter( 'tevkori_srcset_array', array( $this, '_test_tevkori_srcset_array' ) );

		// Set up our test.
		$id = self::$large_id;
		$srcset = tevkori_get_srcset_array( $id, 'medium' );

		// Evaluate that the sizes returned is what we expected.
		foreach( $srcset as $width => $source ) {
			$this->assertTrue( $width <= 500 );
		}

		// Remove test filter.
		remove_filter( 'tevkori_srcset_array', array( $this, '_test_tevkori_srcset_array' ) );
	}

	/**
	 * @expectedDeprecated tevkori_get_srcset_array
	 */
	function test_tevkori_get_srcset_array_false() {
		// Make an image.
		$id = self::$large_id;
		$srcset = tevkori_get_srcset_array( 99999, 'foo' );

		// For canola.jpg we should return.
		$this->assertFalse( $srcset );
	}

	/**
	 * @expectedDeprecated tevkori_get_srcset_array
	 */
	function test_tevkori_get_srcset_array_random_size_name() {
		global $_wp_additional_image_sizes;

		// Make an image.
		$id = self::$large_id;
		$srcset = tevkori_get_srcset_array( $id, 'foo' );

		$year_month = date('Y/m');
		$image_meta = wp_get_attachment_metadata( $id );

		$intermediates = array( 'medium', 'medium_large', 'large', 'full' );

		// Add any soft crop intermediate sizes.
		foreach ( $_wp_additional_image_sizes as $name => $additional_size ) {
			if ( ! $_wp_additional_image_sizes[$name]['crop'] || 0 === $_wp_additional_image_sizes[$name]['height'] ) {
				$intermediates[] = $name;
			}
		}

		foreach( $image_meta['sizes'] as $name => $size ) {
			// Whitelist the sizes that should be included so we pick up 'medium_large' in 4.4.
			if ( in_array( $name, $intermediates ) ) {
				$expected[$size['width']] = 'http://example.org/wp-content/uploads/' . $year_month = date('Y/m') . '/' . $size['file'] . ' ' . $size['width'] . 'w';
			}
		}

		// Add the full size width at the end.
		$expected[$image_meta['width']] = 'http://example.org/wp-content/uploads/' . $image_meta['file'] . ' ' . $image_meta['width'] .'w';

		$this->assertSame( $expected, $srcset );
	}

	/**
	 * @expectedDeprecated tevkori_get_srcset_array
	 */
	function test_tevkori_get_srcset_array_no_date_upoads() {
		global $_wp_additional_image_sizes;

		// Save the current setting for uploads folders.
		$uploads_use_yearmonth_folders = get_option( 'uploads_use_yearmonth_folders' );

		// Disable date organized uploads.
		update_option( 'uploads_use_yearmonth_folders', 0 );

		// Make an image.
		$id = self::create_upload_object( self::$test_file_name );
		$srcset = tevkori_get_srcset_array( $id, 'medium' );
		$image_meta = wp_get_attachment_metadata( $id );

		$intermediates = array( 'medium', 'medium_large', 'large', 'full' );

		// Add any soft crop intermediate sizes.
		foreach ( $_wp_additional_image_sizes as $name => $additional_size ) {
			if ( ! $_wp_additional_image_sizes[$name]['crop'] || 0 === $_wp_additional_image_sizes[$name]['height'] ) {
				$intermediates[] = $name;
			}
		}

		foreach( $image_meta['sizes'] as $name => $size ) {
			// Whitelist the sizes that should be included so we pick up 'medium_large' in 4.4.
			if ( in_array( $name, $intermediates ) ) {
				$expected[$size['width']] = 'http://example.org/wp-content/uploads/' . $size['file'] . ' ' . $size['width'] . 'w';
			}
		}

		// Add the full size width at the end.
		$expected[$image_meta['width']] = 'http://example.org/wp-content/uploads/' . $image_meta['file'] . ' ' . $image_meta['width'] .'w';

		$this->assertSame( $expected, $srcset );

		// Leave the uploads option the way you found it.
		update_option( 'uploads_use_yearmonth_folders', $uploads_use_yearmonth_folders );
	}

	/**
	 * @expectedDeprecated tevkori_get_srcset
	 * @expectedDeprecated tevkori_get_srcset_array
	 */
	function test_tevkori_get_srcset_single_srcset() {
		// Make an image.
		$id = self::$large_id;
		/*
		 * In our tests, thumbnails would only return a single srcset candidate,
		 * in which case we don't bother returning a srcset array.
		 */
		$this->assertTrue( 1 === count( tevkori_get_srcset_array( $id, 'thumbnail' ) ) );
		$this->assertFalse( tevkori_get_srcset( $id, 'thumbnail' ) );
	}

	/**
	 * Test for filtering out leftover sizes after an image is edited.
	 * @group 155
	 * @expectedDeprecated tevkori_get_srcset_array
	 */
	function test_tevkori_get_srcset_array_with_edits() {
		// Make an image.
		$id = self::$large_id;

		/*
		 * For this test we're going to mock metadata changes from an edit.
		 * Start by getting the attachment metadata.
		 */
		$meta = wp_get_attachment_metadata( $id );

		// Mimick hash generation method used in wp_save_image().
		$hash = 'e' . time() . rand( 100, 999 );

		// Replace file paths for full and medium sizes with hashed versions.
		$filename_base = basename( $meta['file'], '.png' );
		$meta['file'] = str_replace( $filename_base, $filename_base . '-' . $hash, $meta['file'] );
		$meta['sizes']['medium']['file'] = str_replace( $filename_base, $filename_base . '-' . $hash, $meta['sizes']['medium']['file'] );

		// Save edited metadata.
		wp_update_attachment_metadata( $id, $meta );

		// Get the edited image and observe that a hash was created.
		$img_url = wp_get_attachment_url( $id );

		// Calculate a srcset array.
		$srcset = tevkori_get_srcset_array( $id, 'medium' );

		// Test to confirm all sources in the array include the same edit hash.
		foreach ( $srcset as $source ) {
			$this->assertTrue( false !== strpos( $source, $hash ) );
		}
	}

	/**
	 * Helper function to filter image_downsize and return zero values for width and height.
	 */
	public function _filter_image_downsize( $out, $id, $size ) {
		$img_url = wp_get_attachment_url( $id );
		return array( $img_url, 0, 0 );
	}

	/**
	 * @expectedDeprecated tevkori_get_srcset_array
	 */
	function test_tevkori_get_srcset_array_no_width() {
		// Filter image_downsize() output.
		add_filter( 'image_downsize', array( $this, '_filter_image_downsize' ), 10, 3 );

		// Make our attachment.
		$id = self::create_upload_object( self::$test_file_name );
		$srcset = tevkori_get_srcset_array( $id, 'medium' );

		// The srcset should be false.
		$this->assertFalse( $srcset );

		// Remove filter.
		remove_filter( 'image_downsize', array( $this, '_filter_image_downsize' ) );
	}

	function test_wp_calculate_image_srcset_ratio_variance() {
	// Mock data for this test.
	$size_array = array( 218, 300);
	$image_src = 'http://' . WP_TESTS_DOMAIN . '/wp-content/uploads/2015/12/test-768x1055-218x300.png';
	$image_meta = array(
		'width' => 768,
		'height' => 1055,
		'file' => '2015/12/test-768x1055.png',
		'sizes' => array(
			'thumbnail' => array(
				'file' => 'test-768x1055-150x150.png',
				'width' => 150,
				'height' => 150,
				'mime-type' => 'image/png',
			),
			'medium' => array(
				'file' => 'test-768x1055-218x300.png',
				'width' => 218,
				'height' => 300,
				'mime-type' => 'image/png',
			),
			'custom-600' => array(
				'file' => 'test-768x1055-600x824.png',
				'width' => 600,
				'height' => 824,
				'mime-type' => 'image/png',
			),
			'post-thumbnail' => array(
				'file' => 'test-768x1055-768x510.png',
				'width' => 768,
				'height' => 510,
				'mime-type' => 'image/png',
			),
		),
	);

	$expected_srcset = 'http://' . WP_TESTS_DOMAIN . '/wp-content/uploads/2015/12/test-768x1055-218x300.png 218w, http://' . WP_TESTS_DOMAIN . '/wp-content/uploads/2015/12/test-768x1055-600x824.png 600w, http://' . WP_TESTS_DOMAIN . '/wp-content/uploads/2015/12/test-768x1055.png 768w';

	$this->assertSame( $expected_srcset, wp_calculate_image_srcset( $size_array, $image_src, $image_meta ) );
}

	/**
	 * @expectedDeprecated tevkori_get_srcset_string
	 */
	function test_tevkori_get_srcset_string() {
		global $_wp_additional_image_sizes;

		// Make an image.
		$id = self::$large_id;

		$srcset = tevkori_get_srcset_string( $id, 'full' );
		$image_meta = wp_get_attachment_metadata( $id );
		$year_month = date('Y/m');

		$intermediates = array( 'medium', 'medium_large', 'large', 'full' );

		// Add any soft crop intermediate sizes.
		foreach ( $_wp_additional_image_sizes as $name => $additional_size ) {
			if ( ! $_wp_additional_image_sizes[$name]['crop'] || 0 === $_wp_additional_image_sizes[$name]['height'] ) {
				$intermediates[] = $name;
			}
		}

		$expected = '';

		foreach( $image_meta['sizes'] as $name => $size ) {
			// Whitelist the sizes that should be included so we pick up 'medium_large' in 4.4.
			if ( in_array( $name, $intermediates ) ) {
				$expected .= 'http://example.org/wp-content/uploads/' . $year_month = date('Y/m') . '/' . $size['file'] . ' ' . $size['width'] . 'w, ';
			}
		}
		// Add the full size width at the end.
		$expected .= 'http://example.org/wp-content/uploads/' . $image_meta['file'] . ' ' . $image_meta['width'] .'w';

		$expected = sprintf( 'srcset="%s"', $expected );

		$this->assertSame( $expected, $srcset );
	}

	/**
	 * @expectedDeprecated tevkori_get_sizes
	 */
	function test_tevkori_get_sizes() {
		// Make an image.
		$id = self::$large_id;

		global $content_width;

		// Test sizes against the default WP sizes.
		$intermediates = array( 'thumbnail', 'medium', 'large' );

		// Make sure themes aren't filtering the sizes array.
		remove_all_filters( 'wp_calculate_image_sizes' );

		foreach( $intermediates as $int ) {
			$width = get_option( $int . '_size_w' );

			$width = ( $width <= $content_width ) ? $width : $content_width;

			$expected = '(max-width: ' . $width . 'px) 100vw, ' . $width . 'px';
			$sizes = tevkori_get_sizes( $id, $int );

			$this->assertSame( $expected, $sizes );
		}
	}

	/**
	 * @group 226
	 * @expectedDeprecated tevkori_get_sizes
	 */
	function test_tevkori_get_sizes_with_args() {
		// Make an image.
		$id = self::$large_id;

		$args = array(
			'sizes' => array(
				array(
					'size_value' => '10em',
					'mq_value'   => '60em',
					'mq_name'    => 'min-width'
				),
				array(
					'size_value' => '20em',
					'mq_value'   => '30em',
					'mq_name'    => 'min-width'
				),
				array(
					'size_value' => 'calc(100vw - 30px)'
				),
			)
		);

		$expected = '(min-width: 60em) 10em, (min-width: 30em) 20em, calc(100vw - 30px)';
		$sizes = tevkori_get_sizes( $id, 'medium', $args );

		$this->assertSame( $expected, $sizes );
	}

	/**
	 * A simple test filter for tevkori_get_sizes().
	 */
	function _test_tevkori_image_sizes_args( $args ) {
		$args['sizes'] = "100vw";
		return $args;
	}

	/**
	 * @group 226
	 * @expectedDeprecated tevkori_get_sizes
	 * @expectedException PHPUnit_Framework_Error_Notice
	 */
	function test_filter_tevkori_get_sizes() {
		// Add our test filter.
		add_filter( 'tevkori_image_sizes_args', array( $this, '_test_tevkori_image_sizes_args' ) );

		// Set up our test.
		$id = self::$large_id;
		$sizes = tevkori_get_sizes($id, 'medium');

		// Evaluate that the sizes returned is what we expected.
		$this->assertSame( $sizes, '100vw' );

		remove_filter( 'tevkori_image_sizes_args', array( $this, '_test_tevkori_image_sizes_args' ) );
	}

	/**
	 * @group 226
	 * @expectedException PHPUnit_Framework_Error_Notice
	 */
	function test_filter_shim_calculate_image_sizes() {
		// Add our test filter.
		add_filter( 'tevkori_image_sizes_args', array( $this, '_test_tevkori_image_sizes_args' ) );

		// A size array is the min required data for `wp_calculate_image_sizes()`.
		$size = array( 300, 150 );
		$sizes = wp_calculate_image_sizes( $size, null, null );

		// Evaluate that the sizes returned is what we expected.
		$this->assertSame( $sizes, '100vw' );

		remove_filter( 'tevkori_image_sizes_args', array( $this, '_test_tevkori_image_sizes_args' ) );
	}

	/**
	 * @expectedDeprecated tevkori_get_sizes
	 * @expectedDeprecated tevkori_get_sizes_string
	 */
	function test_tevkori_get_sizes_string() {
		// Make an image.
		$id = self::$large_id;

		$sizes = tevkori_get_sizes( $id, 'medium' );
		$sizes_string = tevkori_get_sizes_string( $id, 'medium' );

		$expected = 'sizes="' . $sizes . '"';

		$this->assertSame( $expected, $sizes_string );
	}

	/**
	 * @group 170
	 */
	function test_wp_make_content_images_responsive() {
		// Make an image.
		$image_meta = wp_get_attachment_metadata( self::$large_id );
		$size_array = array( $image_meta['sizes']['medium']['width'], $image_meta['sizes']['medium']['height'] );

		$srcset = sprintf( 'srcset="%s"', esc_attr( wp_get_attachment_image_srcset( self::$large_id, 'medium', $image_meta ) ) );
		$sizes  = sprintf( 'sizes="%s"',  esc_attr( wp_get_attachment_image_sizes( self::$large_id, 'medium', $image_meta ) ) );

		// Function used to build HTML for the editor.
		$img = get_image_tag( self::$large_id, '', '', '', 'medium' );
		$img_no_size_in_class = str_replace( 'size-', '', $img );
		$img_no_width_height = str_replace( ' width="' . $size_array[0] . '"', '', $img );
		$img_no_width_height = str_replace( ' height="' . $size_array[1] . '"', '', $img_no_width_height );
		$img_no_size_id = str_replace( 'wp-image-', 'id-', $img );
		$img_with_sizes_attr = str_replace( '<img ', '<img sizes="99vw" ', $img );
		$img_xhtml = str_replace( ' />', '/>', $img );
		$img_html5 = str_replace( ' />', '>', $img );

		// Manually add srcset and sizes to the markup from get_image_tag().
		$respimg = preg_replace( '|<img ([^>]+) />|', '<img $1 ' . $srcset . ' ' . $sizes . ' />', $img );
		$respimg_no_size_in_class = preg_replace( '|<img ([^>]+) />|', '<img $1 ' . $srcset . ' ' . $sizes . ' />', $img_no_size_in_class );
		$respimg_no_width_height = preg_replace( '|<img ([^>]+) />|', '<img $1 ' . $srcset . ' ' . $sizes . ' />', $img_no_width_height );
		$respimg_with_sizes_attr = preg_replace('|<img ([^>]+) />|', '<img $1 ' . $srcset . ' />', $img_with_sizes_attr );
		$respimg_xhtml = preg_replace( '|<img ([^>]+)/>|', '<img $1 ' . $srcset . ' ' . $sizes . ' />', $img_xhtml );
		$respimg_html5 = preg_replace( '|<img ([^>]+)>|', '<img $1 ' . $srcset . ' ' . $sizes . ' />', $img_html5 );

		$content = '
			<p>Image, standard. Should have srcset and sizes.</p>
			%1$s

			<p>Image, no size class. Should have srcset and sizes.</p>
			%2$s

			<p>Image, no width and height attributes. Should have srcset and sizes (from matching the file name).</p>
			%3$s

			<p>Image, no attachment ID class. Should NOT have srcset and sizes.</p>
			%4$s

			<p>Image, with sizes attribute. Should NOT have two sizes attributes.</p>
			%5$s

			<p>Image, XHTML 1.0 style (no space before the closing slash). Should have srcset and sizes.</p>
			%6$s

			<p>Image, HTML 5.0 style. Should have srcset and sizes.</p>
			%7$s';

		$content_unfiltered = sprintf( $content, $img, $img_no_size_in_class, $img_no_width_height, $img_no_size_id, $img_with_sizes_attr, $img_xhtml, $img_html5 );
		$content_filtered = sprintf( $content, $respimg, $respimg_no_size_in_class, $respimg_no_width_height, $img_no_size_id, $respimg_with_sizes_attr, $respimg_xhtml, $respimg_html5 );

		$this->assertSame( $content_filtered, wp_make_content_images_responsive( $content_unfiltered ) );
	}

	/**
	 * When rendering attributes for responsive images,
	 * we rely on the 'wp-image-*' class to find the image by ID.
	 * The class name may not be consistent with attachment IDs in DB when
	 * working with imported content or when a user has edited
	 * the 'src' attribute manually. To avoid incorrect images
	 * being displayed, ensure we don't add attributes in this case.
	 */
	function test_wp_make_content_images_responsive_wrong() {
		$image = get_image_tag( self::$large_id, '', '', '', 'medium' );

		// Replace the src URL.
		$image_wrong_src = preg_replace( '|src="[^"]+"|', 'src="http://' . WP_TESTS_DOMAIN . '/wp-content/uploads/foo.jpg"', $image );

		$this->assertSame( $image_wrong_src, wp_make_content_images_responsive( $image_wrong_src ) );
	}

	/**
	 * @group 170
	 * @expectedDeprecated tevkori_filter_content_images
	 */
	function test_tevkori_filter_content_images_with_preexisting_srcset() {
		// Make an image.
		$id = self::$large_id;

		// Generate HTML and add a dummy srcset attribute.
		$image_html = get_image_tag( $id, '', '', '', 'medium' );
		$image_html = preg_replace('|<img ([^>]+) />|', '<img $1 ' . 'srcset="image2x.jpg 2x" />', $image_html );

		// The content filter should return the image unchanged.
		$this->assertSame( $image_html, tevkori_filter_content_images( $image_html ) );
	}

	/**
	 * @group 159
	 */
	function test_tevkori_filter_attachment_image_attributes() {
		// Make an image.
		$id = self::$large_id;

		// Get attachment post data.
		$attachment = get_post( $id );
		$image = wp_get_attachment_image_src( $id, 'medium' );
		list( $src, $width, $height ) = $image;

		// Create dummy attributes array.
		$attr = array(
			'src'    => $src,
			'width'  => $width,
			'height' => $height,
		);

		// Apply filter.
		$resp_attr = tevkori_filter_attachment_image_attributes( $attr, $attachment, 'medium' );

		// Test output.
		$this->assertTrue( isset( $resp_attr['srcset'] ) );
		$this->assertTrue( isset( $resp_attr['sizes'] ) );
	}

	/**
	 * @group 159
	 */
	function test_tevkori_filter_attachment_image_attributes_thumbnails() {
		// Make an image.
		$id = self::$large_id;

		// Get attachment post data.
		$attachment = get_post( $id );
		$image = wp_get_attachment_image_src( $id, 'thumbnail' );
		list( $src, $width, $height ) = $image;

		// Create dummy attributes array.
		$attr = array(
			'src'    => $src,
			'width'  => $width,
			'height' => $height,
		);

		// Apply filter.
		$resp_attr = tevkori_filter_attachment_image_attributes( $attr, $attachment, 'thumbnail' );

		// Test output.
		$this->assertFalse( isset( $resp_attr['srcset'] ) );
		$this->assertFalse( isset( $resp_attr['sizes'] ) );
	}

	/**
	 * Test if full size GIFs don't get a srcset.
	 */
	function test_wp_calculate_image_srcset_animated_gifs() {
		// Mock meta for an animated gif.
		$image_meta = array(
			'width' => 1200,
			'height' => 600,
			'file' => 'animated.gif',
			'sizes' => array(
				'thumbnail' => array(
					'file' => 'animated-150x150.gif',
					'width' => 150,
					'height' => 150,
					'mime-type' => 'image/gif'
				),
				'medium' => array(
					'file' => 'animated-300x150.gif',
					'width' => 300,
					'height' => 150,
					'mime-type' => 'image/gif'
				),
				'large' => array(
					'file' => 'animated-1024x512.gif',
					'width' => 1024,
					'height' => 512,
					'mime-type' => 'image/gif'
				),
			)
		);

		$full_src  = 'http://example.org/wp-content/uploads/' . $image_meta['file'];
		$large_src = 'http://example.org/wp-content/uploads/' . $image_meta['sizes']['large']['file'];

		// Test with soft resized size array.
		$size_array = array( 900, 450 );

		// Full size GIFs should not return a srcset.
		$this->assertFalse( wp_calculate_image_srcset( $full_src, $size_array, $image_meta ) );
		// Intermediate sized GIFs should not include the full size in the srcset.
		$this->assertFalse( strpos( wp_calculate_image_srcset( $large_src, $size_array, $image_meta ), $full_src ) );
	}

	function test_wp_make_content_images_responsive_schemes() {
		$image_meta = wp_get_attachment_metadata( self::$large_id );
		$size_array = array( (int) $image_meta['sizes']['medium']['width'], (int) $image_meta['sizes']['medium']['height'] );

		$srcset = sprintf( 'srcset="%s"', wp_get_attachment_image_srcset( self::$large_id, $size_array, $image_meta ) );
		$sizes  = sprintf( 'sizes="%s"', wp_get_attachment_image_sizes( self::$large_id, $size_array, $image_meta ) );

		// Build HTML for the editor.
		$img          = get_image_tag( self::$large_id, '', '', '', 'medium' );
		$img_https    = str_replace( 'http://', 'https://', $img );
		$img_relative = str_replace( 'http://', '//', $img );

		// Manually add srcset and sizes to the markup from get_image_tag();
		$respimg          = preg_replace( '|<img ([^>]+) />|', '<img $1 ' . $srcset . ' ' . $sizes . ' />', $img );
		$respimg_https    = preg_replace( '|<img ([^>]+) />|', '<img $1 ' . $srcset . ' ' . $sizes . ' />', $img_https );
		$respimg_relative = preg_replace( '|<img ([^>]+) />|', '<img $1 ' . $srcset . ' ' . $sizes . ' />', $img_relative );

		$content = '
			<p>Image, http: protocol. Should have srcset and sizes.</p>
			%1$s

			<p>Image, http: protocol. Should have srcset and sizes.</p>
			%2$s

			<p>Image, protocol-relative. Should have srcset and sizes.</p>
			%3$s';

		$unfiltered = sprintf( $content, $img, $img_https, $img_relative );
		$expected   = sprintf( $content, $respimg, $respimg_https, $respimg_relative );
		$actual     = wp_make_content_images_responsive( $unfiltered );

		$this->assertSame( $expected, $actual );
	}
}
