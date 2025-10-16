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
         * Resolve the most accurate post type for an attachment.
         *
         * @param \WP_Post|false $attachment Attachment object.
         * @return string
         */
        protected function resolve_attachment_post_type( $attachment ) {

                if ( $attachment instanceof \WP_Post && $attachment->post_parent > 0 ) {
                        $post_type = get_post_type( $attachment->post_parent );

                        if ( $post_type ) {
                                return $post_type;
                        }
                }

                $request_post_id = $this->detect_post_id_from_request();

                if ( $request_post_id ) {
                        $requested_post_type = get_post_type( $request_post_id );

                        if ( $requested_post_type ) {
                                return $requested_post_type;
                        }
                }

                if ( $attachment instanceof \WP_Post ) {
                        $context = get_post_meta( $attachment->ID, '_wp_attachment_context', true );

                        if ( $context && post_type_exists( $context ) ) {
                                return $context;
                        }
                }

                return 'unattached';

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
}
