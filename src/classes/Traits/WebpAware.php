<?php
/**
 * Shared helpers for WebP capability detection.
 *
 * @package easy-watermark
 */

namespace EasyWatermark\Traits;

/**
 * Provides reusable WebP capability checks.
 */
trait WebpAware {
        /**
         * Determines if the current server environment supports WebP handling via GD.
         *
         * @return bool
         */
        protected static function is_webp_supported() {
                if ( ! function_exists( 'gd_info' ) ) {
                        return false;
                }

                $gdinfo = gd_info();

                return ! empty( $gdinfo['WebP Support'] )
                        && function_exists( 'imagecreatefromwebp' )
                        && function_exists( 'imagewebp' );
        }

        /**
         * Instance-level helper for WebP support checks.
         *
         * @return bool
         */
        protected function supports_webp() {
                return static::is_webp_supported();
        }
}
