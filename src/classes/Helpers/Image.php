<?php
/**
 * Image helper
 *
 * @package easy-watermark
 */

namespace EasyWatermark\Helpers;

use EasyWatermark\Traits\WebpAware;

/**
 * Image helper
 */
class Image {

        use WebpAware;
	/**
	 * Returns all registered image sizes
	 *
	 * @return array
	 */
	public static function get_available_sizes() {
		global $_wp_additional_image_sizes;

		$size_names = apply_filters( 'image_size_names_choose', [
			'thumbnail'    => __( 'Thumbnail' ),
			'medium'       => __( 'Medium' ),
			'medium_large' => __( 'Intermediate' ),
			'large'        => __( 'Large' ),
			'full'         => __( 'Full Size' ),
		] );

		$available_sizes = get_intermediate_image_sizes();
		array_push( $available_sizes, 'full' );

		$sizes = [];
		foreach ( $available_sizes as $size ) {
			if ( array_key_exists( $size, $size_names ) ) {
				$sizes[ $size ] = $size_names[ $size ];
			} else {
				$sizes[ $size ] = $size;
			}
		}

		return $sizes;
	}

        /**
         * Returns available mime types
         *
         * @param bool $include_unsupported Whether to include formats missing server support.
         * @return array
         */
        public static function get_available_mime_types( $include_unsupported = false ) {
                $types = [
                        'image/jpeg' => 'JPEG',
                        'image/png'  => 'PNG',
                        'image/gif'  => 'GIF',
                        'image/webp' => 'WebP',
                ];

                if ( ! $include_unsupported && ! self::supports_webp() ) {
                        unset( $types['image/webp'] );
                }

                return $types;
        }

        /**
         * Determines if the current environment supports WebP handling via GD.
         *
         * @return bool
         */
        public static function supports_webp() {
                return static::is_webp_supported();
        }
}
