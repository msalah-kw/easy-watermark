<?php
/**
 * Settings class
 *
 * @package easy-watermark
 */

namespace EasyWatermark\Dashboard;

use EasyWatermark\Core\Plugin;
use EasyWatermark\Core\View;
use EasyWatermark\Helpers\Image as ImageHelper;
use EasyWatermark\Watermark\Handler;

/**
 * Settings class
 */
class Tools extends Page {

        /**
         * Watermark handler instance.
         *
         * @var Handler
         */
        private $handler;

        /**
         * Constructor
         */
        public function __construct() {
                parent::__construct( __( 'Tools', 'easy-watermark' ), 'tools', 20 );

                $this->handler = Plugin::get()->get_watermark_handler();
        }

	/**
	 * Display admin notices
	 *
	 * @action easy-watermark/dashboard/settings/notices
	 *
	 * @return void
	 */
	public function admin_notices() {
		// phpcs:disable WordPress.Security
		if ( isset( $_GET['settings-updated'] ) ) {
			echo new View( 'notices/success', [
				'message' => __( 'Settings saved.', 'easy-watermark' ),
			] );
		}
		// phpcs:enable
	}

	/**
	 * Prepares arguments for view
	 *
	 * @filter easy-watermark/dashboard/tools/view-args
	 *
	 * @param  array $args View args.
	 * @return array
	 */
	public function view_args( $args ) {

		global $wpdb;

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $backup_count = (int) $wpdb->get_var( "SELECT COUNT( post_id ) FROM {$wpdb->postmeta} WHERE meta_key = '_ew_has_backup'" );

                $webp_supported       = ImageHelper::supports_webp();
                $webp_attachment_count = 0;

                if ( ! $webp_supported ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                        $webp_attachment_count = (int) $wpdb->get_var(
                                "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash' AND post_mime_type = 'image/webp'"
                        );
                }

                return [
                        'watermarks'             => $this->handler->get_watermarks(),
                        'backup_count'           => $backup_count,
                        'attachments'            => $this->get_attachments(),
                        'webp_supported'         => $webp_supported,
                        'webp_attachment_count'  => $webp_attachment_count,
                ];
        }

	/**
	 * Gets attachments available for watermarking
	 *
	 * @param  string $mode Mode (watermark|restore).
	 * @return array
	 */
	private function get_attachments( $mode = 'watermark' ) {

                $mime_types = ImageHelper::get_available_mime_types();
                $result     = [];
                $attachment_ids = get_posts( [
                        'post_type'              => 'attachment',
                        'post_mime_type'         => array_keys( $mime_types ),
                        'post_status'            => 'any',
                        'numberposts'            => -1,
                        'fields'                 => 'ids',
                        'no_found_rows'          => true,
                        'update_post_meta_cache' => false,
                        'update_post_term_cache' => false,
                ] );

                $watermarks = [];

                if ( 'watermark' === $mode ) {
                        $watermarks = $this->handler->get_watermarks();
                }

                if ( ! empty( $attachment_ids ) ) {
                        $this->handler->prime_attachment_post_types( $attachment_ids );
                }

                foreach ( $attachment_ids as $attachment_id ) {
                        $attachment = get_post( $attachment_id );

                        if ( ! $attachment instanceof \WP_Post ) {
                                continue;
                        }

                        if ( 'trash' === $attachment->post_status ) {
                                // Skip trashed attachments regardless of mode.
                                continue;
                        }

                        if ( get_post_meta( $attachment_id, '_ew_used_as_watermark', true ) ) {
                                // Skip images used as watermark.
                                continue;
                        }

                        if ( 'restore' === $mode && ! get_post_meta( $attachment_id, '_ew_has_backup', true ) ) {
                                // In 'restore' mode skip items without backup.
                                continue;
                        }

                        if ( 'watermark' === $mode ) {
                                $applicable = $this->handler->get_watermarks_for_attachment( $attachment_id, $watermarks );

                                if ( empty( $applicable ) ) {
                                        continue;
                                }
                        }

                        $result[] = [
                                'id'    => $attachment_id,
                                'title' => $attachment->post_title,
                        ];
                }

                return $result;

	}

	/**
	 * Prepares arguments for view
	 *
	 * @action wp_ajax_easy-watermark/tools/get-attachments
	 *
	 * @return void
	 */
	public function ajax_get_attachments() {

		check_ajax_referer( 'get_attachments', 'nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$mode   = isset( $_REQUEST['mode'] ) ? $_REQUEST['mode'] : null;
		$result = $this->get_attachments( $mode );

		wp_send_json_success( $result );

	}
}
