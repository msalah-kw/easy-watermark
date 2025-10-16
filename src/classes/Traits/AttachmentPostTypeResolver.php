<?php
/**
 * Attachment post type resolver trait.
 *
 * @package easy-watermark
 */

namespace EasyWatermark\Traits;

/**
 * Provide helpers for resolving the originating post type of an attachment.
 */
trait AttachmentPostTypeResolver {

        /**
         * Cache of attachment post type lookups.
         *
         * @var array<int, string>
         */
        private static $attachment_post_type_cache = [];

        /**
         * Resolve the most accurate post type for an attachment.
         *
         * @param \WP_Post|false $attachment Attachment object.
         * @return string
         */
        protected function resolve_attachment_post_type( $attachment ) {

                $attachment_id = ( $attachment instanceof \WP_Post ) ? (int) $attachment->ID : 0;

                if ( $attachment_id && isset( self::$attachment_post_type_cache[ $attachment_id ] ) ) {
                        return self::$attachment_post_type_cache[ $attachment_id ];
                }

                if ( $attachment instanceof \WP_Post && $attachment->post_parent > 0 ) {
                        $post_type = get_post_type( $attachment->post_parent );

                        if ( $post_type ) {
                                return self::cache_attachment_post_type( $attachment_id, $post_type );
                        }
                }

                $request_post_id = $this->detect_post_id_from_request();

                if ( $request_post_id ) {
                        $requested_post_type = get_post_type( $request_post_id );

                        if ( $requested_post_type ) {
                                return self::cache_attachment_post_type( $attachment_id, $requested_post_type );
                        }
                }

                if ( $attachment instanceof \WP_Post ) {
                        $referenced_post_type = $this->detect_post_type_from_usage( $attachment->ID );

                        if ( $referenced_post_type ) {
                                return self::cache_attachment_post_type( $attachment_id, $referenced_post_type );
                        }

                        $context = get_post_meta( $attachment->ID, '_wp_attachment_context', true );

                        if ( $context && post_type_exists( $context ) ) {
                                return self::cache_attachment_post_type( $attachment_id, $context );
                        }
                }

                return self::cache_attachment_post_type( $attachment_id, 'unattached' );
        }

        /**
         * Detect the target post id from the current request or referer.
         *
         * @return int
         */
        protected function detect_post_id_from_request() {

                $request_post_id = 0;

                // phpcs:disable WordPress.Security.NonceVerification.Recommended
                $request = isset( $_REQUEST ) ? wp_unslash( $_REQUEST ) : [];
                // phpcs:enable

                if ( is_array( $request ) ) {
                        foreach ( [ 'post', 'post_id', 'post_ID', 'postId' ] as $key ) {
                                if ( isset( $request[ $key ] ) && ! is_array( $request[ $key ] ) ) {
                                        $request_post_id = absint( $request[ $key ] );

                                        if ( $request_post_id ) {
                                                return $request_post_id;
                                        }
                                }
                        }

                        foreach ( [ '_wp_http_referer' ] as $referer_key ) {
                                if ( empty( $request[ $referer_key ] ) || is_array( $request[ $referer_key ] ) ) {
                                        continue;
                                }

                                $referer_post_id = $this->detect_post_id_from_url( $request[ $referer_key ] );

                                if ( $referer_post_id ) {
                                        return $referer_post_id;
                                }
                        }
                }

                if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
                        $referer_post_id = $this->detect_post_id_from_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) );

                        if ( $referer_post_id ) {
                                return $referer_post_id;
                        }
                }

                return 0;
        }

        /**
         * Detect post type by scanning known post meta references to an attachment.
         *
         * @param int $attachment_id Attachment ID.
         * @return string|null
         */
        protected function detect_post_type_from_usage( $attachment_id ) {

                static $detected = [];

                if ( isset( $detected[ $attachment_id ] ) ) {
                        return $detected[ $attachment_id ];
                }

                global $wpdb;

                $attachment_id = (int) $attachment_id;

                if ( $attachment_id <= 0 ) {
                        $detected[ $attachment_id ] = null;
                        return null;
                }

                // Look for a direct featured image relationship first.
                $post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                        $wpdb->prepare(
                                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
                                $attachment_id
                        )
                );

                $post_type = $this->select_preferred_post_type_from_ids( $post_ids );

                if ( $post_type ) {
                        $detected[ $attachment_id ] = $post_type;
                        return $post_type;
                }

                // WooCommerce stores gallery images as a comma-separated list.
                if ( post_type_exists( 'product' ) ) {
                        $regexp   = sprintf( '(^|,)%d(,|$)', $attachment_id );
                        $post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                                $wpdb->prepare(
                                        "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value REGEXP %s",
                                        $regexp
                                )
                        );

                        $post_type = $this->select_preferred_post_type_from_ids( $post_ids );

                        if ( $post_type ) {
                                $detected[ $attachment_id ] = $post_type;
                                return $post_type;
                        }
                }

                $post_type = $this->detect_post_type_from_content( $attachment_id );

                if ( $post_type ) {
                        $detected[ $attachment_id ] = $post_type;
                        return $post_type;
                }

                $detected[ $attachment_id ] = null;

                return null;
        }

        /**
         * Attempt to detect the attachment's usage by scanning post content.
         *
         * @param int $attachment_id Attachment ID.
         * @return string|null
         */
        private function detect_post_type_from_content( $attachment_id ) {

                global $wpdb;

                $attachment_id = (int) $attachment_id;

                if ( $attachment_id <= 0 ) {
                        return null;
                }

                $patterns = [
                        '"id":' . $attachment_id,
                        '"attachmentId":' . $attachment_id,
                        'wp-image-' . $attachment_id,
                        'data-id="' . $attachment_id . '"',
                        'data-attachment-id="' . $attachment_id . '"',
                ];

                $likes = array_values( array_unique( array_filter( array_map( static function ( $pattern ) use ( $wpdb ) {
                        if ( '' === $pattern ) {
                                return null;
                        }

                        return '%' . $wpdb->esc_like( $pattern ) . '%';
                }, $patterns ) ) ) );

                if ( empty( $likes ) ) {
                        return null;
                }

                $placeholder = implode( ' OR ', array_fill( 0, count( $likes ), 'post_content LIKE %s' ) );

                $sql = "SELECT DISTINCT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_status NOT IN ('trash','auto-draft') AND ( {$placeholder} ) LIMIT 50"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
                $post_ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$likes ) );

                if ( empty( $post_ids ) ) {
                        return null;
                }

                return $this->select_preferred_post_type_from_ids( $post_ids );
        }

        /**
         * Extract a post id from a URL if possible.
         *
         * @param string $url URL that may contain post data.
         * @return int
         */
        protected function detect_post_id_from_url( $url ) {

                if ( ! is_string( $url ) || '' === $url ) {
                        return 0;
                }

                $query = wp_parse_url( $url, PHP_URL_QUERY );

                if ( ! $query ) {
                        return 0;
                }

                parse_str( $query, $params );

                if ( empty( $params ) || ! is_array( $params ) ) {
                        return 0;
                }

                foreach ( [ 'post', 'post_id', 'post_ID', 'postId' ] as $key ) {
                        if ( isset( $params[ $key ] ) && ! is_array( $params[ $key ] ) ) {
                                $post_id = absint( $params[ $key ] );

                                if ( $post_id ) {
                                        return $post_id;
                                }
                        }
                }

                return 0;
        }

        /**
         * Cache a resolved post type and return it.
         *
         * @param int    $attachment_id Attachment ID.
         * @param string $post_type     Resolved post type.
         * @return string
         */
        private static function cache_attachment_post_type( $attachment_id, $post_type ) {

                if ( $attachment_id > 0 ) {
                        self::$attachment_post_type_cache[ $attachment_id ] = $post_type;
                }

                return $post_type;
        }

        /**
         * Determine the preferred post type from a list of post ids.
         *
         * @param array $post_ids List of post ids referencing the attachment.
         * @return string|null
         */
        private function select_preferred_post_type_from_ids( $post_ids ) {

                if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
                        return null;
                }

                $fallback = null;

                foreach ( $post_ids as $post_id ) {
                        $post_type = $this->normalize_detected_post_type( (int) $post_id );

                        if ( ! $post_type ) {
                                continue;
                        }

                        if ( 'product' === $post_type ) {
                                return 'product';
                        }

                        if ( null === $fallback ) {
                                $fallback = $post_type;
                        }
                }

                return $fallback;
        }

        /**
         * Normalize detected post type to improve matching accuracy.
         *
         * @param int $post_id Post id referencing the attachment.
         * @return string|null
         */
        private function normalize_detected_post_type( $post_id ) {

                if ( $post_id <= 0 ) {
                        return null;
                }

                $post_type = get_post_type( $post_id );

                if ( ! $post_type ) {
                        return null;
                }

                if ( 'product_variation' === $post_type ) {
                        $parent_id = (int) get_post_field( 'post_parent', $post_id );

                        if ( $parent_id > 0 ) {
                                $parent_type = get_post_type( $parent_id );

                                if ( $parent_type ) {
                                        $post_type = $parent_type;
                                }
                        }

                        if ( 'product_variation' === $post_type ) {
                                $post_type = 'product';
                        }
                }

                return $post_type;
        }
}

