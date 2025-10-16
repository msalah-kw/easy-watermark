<?php
/**
 * Hooks class
 *
 * @package easy-watermark
 */

namespace EasyWatermark\Watermark;

use EasyWatermark\AttachmentProcessor;
use EasyWatermark\Metaboxes\Attachment\Watermarks;
use EasyWatermark\Traits\Hookable;

/**
 * Hooks class
 */
class Hooks {

	use Hookable;

	/**
	 * Watermark Handler instance
	 *
	 * @var Handler
	 */
	private $handler;

	/**
	 * Constructor
	 *
	 * @param Handler $handler Handler instance.
	 */
	public function __construct( $handler ) {

		$this->hook();

		$this->handler = $handler;

	}

	/**
	 * Cleans backup on attachment removal
	 *
	 * @action delete_attachment
	 *
	 * @param  integer $attachment_id Image attachment ID.
	 * @return void
	 */
	public function delete_attachment( $attachment_id ) {

		$has_backup = get_post_meta( $attachment_id, '_ew_has_backup', true );

		if ( '1' === $has_backup ) {
			$this->handler->clean_backup( $attachment_id );
		}

	}

	/**
	 * Applies watermarks after upload
	 *
	 * @filter wp_generate_attachment_metadata
	 *
	 * @param  array   $metadata      Attachment metadata.
	 * @param  integer $attachment_id Attachment ID.
	 * @return array
	 */
	public function wp_generate_attachment_metadata( $metadata, $attachment_id ) {

		$auto_watermark = true;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['auto_watermark'] ) ) {
			$auto_watermark = filter_var( wp_unslash( $_REQUEST['auto_watermark'] ), FILTER_VALIDATE_BOOLEAN );
		}
		// phpcs:enable

		if ( ! $auto_watermark ) {
			return $metadata;
		}

		$all_watermarks = $this->handler->get_watermarks();

		$watermarks = [];

		foreach ( $all_watermarks as $watermark ) {
			if ( ! $watermark->auto_add ) {
				continue;
			}

			if ( ! $watermark->auto_add_all && ! current_user_can( 'apply_watermark' ) ) {
				continue;
			}

			$mime_type = get_post_mime_type( $attachment_id );

			if ( ! in_array( $mime_type, $watermark->image_types, true ) ) {
				continue;
			}

                        $attachment = get_post( $attachment_id );

                        $post_type = $this->resolve_attachment_post_type( $attachment );

                        if ( ! in_array( $post_type, $watermark->post_types, true ) ) {
                                continue;
                        }

			$watermarks[] = $watermark;
		}

		$this->handler->apply_watermarks( $attachment_id, $watermarks, $metadata );

		return $metadata;

	}

	/**
	 * Filters the attachment data prepared for JavaScript.
	 *
	 * @filter wp_prepare_attachment_for_js
	 *
	 * @param array       $response   Array of prepared attachment data.
	 * @param WP_Post     $attachment Attachment object.
	 * @param array|false $meta       Array of attachment meta data, or false if there is none.
	 */
	public function wp_prepare_attachment_for_js( $response, $attachment, $meta ) {
		$response['nonces']['watermark'] = wp_create_nonce( 'watermark' );
		$response['usedAsWatermark']     = get_post_meta( $attachment->ID, '_ew_used_as_watermark', true ) ? true : false;
		$response['hasBackup']           = get_post_meta( $attachment->ID, '_ew_has_backup', true ) ? true : false;

		return $response;
	}

	/**
	 * Adds bulk actions
	 *
	 * @filter bulk_actions-upload
	 *
	 * @param array $bulk_actions Bulk actions.
	 * @return array
	 */
	public function bulk_actions( $bulk_actions ) {
		$bulk_actions['watermark'] = __( 'Watermark' );
		$bulk_actions['restore']   = __( 'Restore original images' );

		return $bulk_actions;
	}

	/**
	 * Destructor
	 */
	public function __destruct() {
		$this->unhook();
        }

        /**
         * Resolve the most accurate post type for an attachment.
         *
         * @param \WP_Post|false $attachment Attachment object.
         * @return string
         */
        private function resolve_attachment_post_type( $attachment ) {

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
        private function detect_post_id_from_request() {

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
        private function detect_post_id_from_url( $url ) {

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
