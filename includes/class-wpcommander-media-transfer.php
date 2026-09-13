<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPCommander_Media_Transfer {
	private const MAX_FILES = 10;
	private const MAX_REMOTE_BYTES = 20971520;
	private const MAX_IMAGE_PIXELS = 40000000;
	private const SOURCE_META_KEY = '_wpcommander_openai_file_id_hash';

	private $mutations;

	public function __construct( WPCommander_Mutations $mutations ) {
		$this->mutations = $mutations;
	}

	public function can_import(): bool {
		return $this->mutations->is_enabled() && current_user_can( 'upload_files' );
	}

	public function can_export(): bool {
		return is_user_logged_in();
	}

	public function import_chat_images( array $params ) {
		if ( ! $this->can_import() ) {
			return new WP_Error( 'wpcommander_media_write_blocked', __( 'Importing chat images requires Edit site or Full control plus permission to upload media.', 'wpcommander' ), array( 'status' => 403 ) );
		}

		$refs = isset( $params['openaiFileIdRefs'] ) && is_array( $params['openaiFileIdRefs'] )
			? array_values( $params['openaiFileIdRefs'] )
			: array();
		if ( empty( $refs ) || count( $refs ) > self::MAX_FILES ) {
			return new WP_Error( 'wpcommander_invalid_chat_files', __( 'Provide between one and ten chat image files.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$parent_id = isset( $params['parentId'] ) ? max( 0, (int) $params['parentId'] ) : 0;
		if ( $parent_id > 0 && ! current_user_can( 'edit_post', $parent_id ) ) {
			return new WP_Error( 'wpcommander_media_parent_forbidden', __( 'The requested parent post is not editable by this user.', 'wpcommander' ), array( 'status' => 403 ) );
		}

		$imported = array();
		$failed   = array();
		foreach ( $refs as $index => $ref ) {
			$result = $this->import_one( $ref, $parent_id );
			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'index'   => (int) $index,
					'name'    => is_array( $ref ) && isset( $ref['name'] ) ? sanitize_file_name( (string) $ref['name'] ) : '',
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				);
				continue;
			}
			$imported[] = $result;
		}

		return array(
			'imported' => $imported,
			'failed'   => $failed,
			'count'    => count( $imported ),
		);
	}

	public function get_media_links( array $params ) {
		$ids = isset( $params['ids'] ) && is_array( $params['ids'] ) ? array_values( $params['ids'] ) : array();
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) || count( $ids ) > self::MAX_FILES ) {
			return new WP_Error( 'wpcommander_invalid_media_ids', __( 'Provide between one and ten media IDs.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$items = array();
		foreach ( $ids as $id ) {
			$item = $this->media_item( $id, false );
			if ( is_wp_error( $item ) ) {
				$items[] = array(
					'id'      => $id,
					'error'   => $item->get_error_code(),
					'message' => $item->get_error_message(),
				);
				continue;
			}
			$items[] = $item;
		}

		return array( 'items' => $items );
	}

	private function import_one( $ref, int $parent_id ) {
		if ( ! is_array( $ref ) ) {
			return new WP_Error( 'wpcommander_invalid_chat_file', __( 'Chat file references must include the OpenAI file metadata supplied at runtime.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$file_id = isset( $ref['id'] ) ? sanitize_text_field( (string) $ref['id'] ) : '';
		$name    = isset( $ref['name'] ) ? sanitize_file_name( (string) $ref['name'] ) : '';
		$mime    = isset( $ref['mime_type'] ) ? strtolower( sanitize_text_field( (string) $ref['mime_type'] ) ) : '';
		$url     = isset( $ref['download_link'] ) ? esc_url_raw( (string) $ref['download_link'] ) : '';
		if ( '' === $file_id || '' === $name || '' === $mime || '' === $url ) {
			return new WP_Error( 'wpcommander_incomplete_chat_file', __( 'The chat image reference is missing required runtime metadata.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		if ( ! $this->allowed_image_mime( $mime ) ) {
			return new WP_Error( 'wpcommander_chat_file_not_image', __( 'Only supported image files can be imported into WordPress media.', 'wpcommander' ), array( 'status' => 415 ) );
		}
		if ( ! $this->is_openai_download_url( $url ) ) {
			return new WP_Error( 'wpcommander_untrusted_chat_file_url', __( 'The chat file download URL is not an approved OpenAI file host.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$source_hash = hash( 'sha256', $file_id );
		$existing_id = $this->find_existing_import( $source_hash );
		if ( $existing_id > 0 ) {
			return $this->media_item( $existing_id, true );
		}

		$max_bytes = min( self::MAX_REMOTE_BYTES, max( 1, (int) wp_max_upload_size() ) );
		$response  = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 12,
				'redirection'         => 2,
				'limit_response_size' => $max_bytes + 1,
				'user-agent'          => 'WPCommander/' . WPCOMMANDER_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wpcommander_chat_file_download_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 || ! is_string( $body ) || '' === $body ) {
			return new WP_Error( 'wpcommander_chat_file_download_failed', __( 'The OpenAI chat image could not be downloaded.', 'wpcommander' ), array( 'status' => 502 ) );
		}
		if ( strlen( $body ) > $max_bytes ) {
			return new WP_Error( 'wpcommander_chat_file_too_large', __( 'The chat image exceeds the WordPress/WPCommander upload size limit.', 'wpcommander' ), array( 'status' => 413 ) );
		}

		$filename = $this->normalized_filename( $name, $mime );
		$tmp_name = wp_tempnam( $filename );
		if ( ! $tmp_name || false === file_put_contents( $tmp_name, $body ) ) {
			if ( is_string( $tmp_name ) && file_exists( $tmp_name ) ) {
				wp_delete_file( $tmp_name );
			}
			return new WP_Error( 'wpcommander_chat_file_temp_failed', __( 'WPCommander could not create a temporary media file.', 'wpcommander' ), array( 'status' => 500 ) );
		}

		unset( $body );
		$actual_mime = wp_get_image_mime( $tmp_name );
		if ( ! is_string( $actual_mime ) || ! $this->allowed_image_mime( $actual_mime ) ) {
			wp_delete_file( $tmp_name );
			return new WP_Error( 'wpcommander_invalid_image_content', __( 'The downloaded chat file is not a supported image.', 'wpcommander' ), array( 'status' => 415 ) );
		}
		$dimensions = wp_getimagesize( $tmp_name );
		if ( ! is_array( $dimensions ) || empty( $dimensions[0] ) || empty( $dimensions[1] ) || ( (int) $dimensions[0] * (int) $dimensions[1] ) > self::MAX_IMAGE_PIXELS ) {
			wp_delete_file( $tmp_name );
			return new WP_Error( 'wpcommander_image_dimensions_blocked', __( 'The chat image dimensions are invalid or exceed the safe processing limit.', 'wpcommander' ), array( 'status' => 413 ) );
		}
		$filename = $this->normalized_filename( $name, $actual_mime );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file = array(
			'name'     => $filename,
			'type'     => $actual_mime,
			'tmp_name' => $tmp_name,
			'error'    => 0,
			'size'     => (int) filesize( $tmp_name ),
		);
		$attachment_id = media_handle_sideload( $file, $parent_id );
		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp_name ) ) {
				wp_delete_file( $tmp_name );
			}
			return $attachment_id;
		}

		add_post_meta( (int) $attachment_id, self::SOURCE_META_KEY, $source_hash, true );
		$activity = $this->mutations->record_media_import_activity( (int) $attachment_id, $source_hash );
		$item     = $this->media_item( (int) $attachment_id, false );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		$item['activityId'] = $activity['id'];
		return $item;
	}

	private function find_existing_import( string $source_hash ): int {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::SOURCE_META_KEY,
				'meta_value'     => $source_hash,
				'no_found_rows'  => true,
			)
		);
		$id = ! empty( $ids ) ? (int) $ids[0] : 0;
		return $id > 0 && current_user_can( 'edit_post', $id ) ? $id : 0;
	}

	private function media_item( int $id, bool $replayed ) {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wpcommander_media_not_found', __( 'The requested media item was not found or is not accessible.', 'wpcommander' ), array( 'status' => 404 ) );
		}

		$mime = (string) get_post_mime_type( $id );
		if ( ! $this->allowed_image_mime( $mime ) ) {
			return new WP_Error( 'wpcommander_media_not_image', __( 'The requested attachment is not a supported image.', 'wpcommander' ), array( 'status' => 415 ) );
		}

		$url      = wp_get_attachment_url( $id );
		$metadata = wp_get_attachment_metadata( $id );
		$file     = get_attached_file( $id );
		$variants = array();
		foreach ( array_slice( get_intermediate_image_sizes(), 0, 12 ) as $size ) {
			$source = wp_get_attachment_image_src( $id, $size );
			if ( ! is_array( $source ) ) {
				continue;
			}
			$variants[] = array(
				'name'   => (string) $size,
				'url'    => esc_url_raw( (string) $source[0] ),
				'width'  => (int) $source[1],
				'height' => (int) $source[2],
			);
		}

		return array(
			'id'          => $id,
			'address'     => 'media/' . $id,
			'title'       => get_the_title( $id ),
			'mimeType'    => $mime,
			'url'         => esc_url_raw( (string) $url ),
			'downloadUrl' => esc_url_raw( (string) $url ),

			'filename'    => is_string( $file ) && '' !== $file ? wp_basename( $file ) : '',
			'filesize'    => is_string( $file ) && file_exists( $file ) ? (int) filesize( $file ) : null,
			'width'       => is_array( $metadata ) && isset( $metadata['width'] ) ? (int) $metadata['width'] : null,
			'height'      => is_array( $metadata ) && isset( $metadata['height'] ) ? (int) $metadata['height'] : null,
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'     => (string) $post->post_excerpt,
			'variants'    => $variants,
			'replayed'    => $replayed,
		);
	}

	private function allowed_image_mime( string $mime ): bool {
		return in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ), true );
	}

	private function normalized_filename( string $name, string $mime ): string {
		$base = sanitize_file_name( $name );
		if ( '' === $base ) {
			$base = 'chat-image';
		}
		$extension = strtolower( (string) pathinfo( $base, PATHINFO_EXTENSION ) );
		$wanted    = $this->extension_for_mime( $mime );
		if ( ! $this->extension_matches_mime( $extension, $mime ) ) {
			$stem = pathinfo( $base, PATHINFO_FILENAME );
			$base = ( '' !== $stem ? $stem : 'chat-image' ) . '.' . $wanted;
		}
		return wp_unique_filename( sys_get_temp_dir(), $base );
	}

	private function extension_matches_mime( string $extension, string $mime ): bool {
		if ( 'image/jpeg' === $mime ) {
			return in_array( $extension, array( 'jpg', 'jpeg' ), true );
		}
		return '' !== $extension && $extension === $this->extension_for_mime( $mime );
	}

	private function extension_for_mime( string $mime ): string {
		$map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
			'image/avif' => 'avif',
		);
		return isset( $map[ $mime ] ) ? $map[ $mime ] : 'img';
	}

	private function is_openai_download_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( 'oaiusercontent.com' === $host ) {
			return true;
		}
		$suffix = '.oaiusercontent.com';
		return strlen( $host ) > strlen( $suffix ) && $suffix === substr( $host, -strlen( $suffix ) );
	}
}
