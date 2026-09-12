<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPCommander_Mutations {
	private const OPTION_ENABLED = 'wpcommander_structured_writes_enabled';
	private const OPTION_ACTIVITY = 'wpcommander_activity_log';
	private const MAX_ACTIVITY = 50;
	private const MAX_REVERSIBLE_BYTES = 65536;
	private const MUTABLE_KINDS = array( 'post', 'post-meta', 'option', 'media', 'term', 'comment' );

	private $resources;

	public function __construct( WPCommander_Resources $resources ) {
		$this->resources = $resources;
	}

	public function is_enabled(): bool {
		return '1' === (string) get_option( self::OPTION_ENABLED, '0' );
	}

	public function set_enabled( bool $enabled ): bool {
		update_option( self::OPTION_ENABLED, $enabled ? '1' : '0', false );
		return $this->is_enabled() === $enabled;
	}

	public function can_mutate( array $input ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		$kind = isset( $input['kind'] ) ? (string) $input['kind'] : '';
		if ( ! in_array( $kind, self::MUTABLE_KINDS, true ) ) {
			return false;
		}

		return $this->resources->can_inspect( $input );
	}

	public function create_resource( array $input ) {
		if ( ! $this->is_enabled() ) {
			return new WP_Error( 'wpcommander_structured_writes_disabled', __( 'Structured writes are disabled in WPCommander settings.', 'wpcommander' ), array( 'status' => 403 ) );
		}
		if ( 'post' !== (string) ( $input['kind'] ?? '' ) ) {
			return new WP_Error( 'wpcommander_create_kind_unsupported', __( 'Structured create currently supports WordPress posts, pages, and custom post types. Other WordPress-accessible resources remain reachable through Abilities or universal execution.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$source_id          = isset( $input['sourceId'] ) ? (int) $input['sourceId'] : 0;
		$source             = null;
		$source_fingerprint = '';
		if ( $source_id > 0 ) {
			$selector = array( 'kind' => 'post', 'id' => $source_id, 'key' => '', 'taxonomy' => '' );
			if ( ! $this->resources->can_inspect( $selector ) || ! current_user_can( 'edit_post', $source_id ) ) {
				return new WP_Error( 'wpcommander_create_source_forbidden', __( 'The source post could not be read by the current WordPress user.', 'wpcommander' ), array( 'status' => 403 ) );
			}
			$expected = isset( $input['expectedSourceFingerprint'] ) ? strtolower( (string) $input['expectedSourceFingerprint'] ) : '';
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) ) {
				return new WP_Error( 'wpcommander_missing_fingerprint', __( 'Creating from an existing resource requires its fresh resourceFingerprint.', 'wpcommander' ), array( 'status' => 400 ) );
			}
			$loaded = $this->resources->load_for_mutation( $selector );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			$source_fingerprint = $this->resources->fingerprint_value( $loaded['value'] );
			if ( ! hash_equals( $expected, $source_fingerprint ) ) {
				return new WP_Error( 'wpcommander_stale_resource', __( 'The source changed after it was inspected. Re-read it before creating from it.', 'wpcommander' ), array( 'status' => 409 ) );
			}
			$source = get_post( $source_id );
			if ( ! $source instanceof WP_Post || 'attachment' === $source->post_type ) {
				return new WP_Error( 'wpcommander_create_source_invalid', __( 'The source must be a non-attachment WordPress post object.', 'wpcommander' ), array( 'status' => 400 ) );
			}
		}

		$post_type_name = $source instanceof WP_Post ? $source->post_type : sanitize_key( (string) ( $input['postType'] ?? 'post' ) );
		$post_type      = get_post_type_object( $post_type_name );
		if ( ! $post_type || ! current_user_can( $post_type->cap->create_posts ) ) {
			return new WP_Error( 'wpcommander_create_forbidden', __( 'The current WordPress user cannot create this post type.', 'wpcommander' ), array( 'status' => 403 ) );
		}

		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'draft';
		if ( ! in_array( $status, array( 'draft', 'pending', 'publish', 'private' ), true ) ) {
			return new WP_Error( 'wpcommander_create_status_invalid', __( 'Create status must be draft, pending, publish, or private.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		if ( in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( $post_type->cap->publish_posts ) ) {
			return new WP_Error( 'wpcommander_create_publish_forbidden', __( 'The current WordPress user cannot publish this post type.', 'wpcommander' ), array( 'status' => 403 ) );
		}

		$default_title = $source instanceof WP_Post ? sprintf( __( 'Copy of %s', 'wpcommander' ), $source->post_title ) : '';
		$title         = isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : $default_title;
		if ( '' === $title ) {
			return new WP_Error( 'wpcommander_create_title_required', __( 'A title is required when creating a new WordPress post resource from scratch.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		$slug = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';
		$content = $source instanceof WP_Post ? $source->post_content : (string) ( $input['content'] ?? '' );
		$excerpt = $source instanceof WP_Post ? $source->post_excerpt : (string) ( $input['excerpt'] ?? '' );
		$parent  = $source instanceof WP_Post ? (int) $source->post_parent : (int) ( $input['parent'] ?? 0 );
		$copy_meta       = $source instanceof WP_Post && ( ! array_key_exists( 'copyMeta', $input ) || true === $input['copyMeta'] );
		$copy_taxonomies = $source instanceof WP_Post && ( ! array_key_exists( 'copyTaxonomies', $input ) || true === $input['copyTaxonomies'] );
		$request_hash = hash(
			'sha256',
			(string) wp_json_encode( array( 'create', $source_id, $source_fingerprint, $post_type_name, $title, $slug, $status, $content, $excerpt, $parent, $copy_meta, $copy_taxonomies ) )
		);
		$replay = $this->find_create_replay( $request_hash );
		if ( null !== $replay ) {
			return $replay;
		}

		$postarr = array(
			'post_type'    => $post_type_name,
			'post_status'  => $status,
			'post_author'  => get_current_user_id(),
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_parent'  => $parent,
		);
		if ( $source instanceof WP_Post ) {
			$postarr['menu_order']     = (int) $source->menu_order;
			$postarr['comment_status'] = $source->comment_status;
			$postarr['ping_status']    = $source->ping_status;
		}
		$new_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		if ( $source instanceof WP_Post ) {
			$copied = $this->copy_post_relations( $source, (int) $new_id, $copy_meta, $copy_taxonomies );
			if ( is_wp_error( $copied ) ) {
				wp_delete_post( (int) $new_id, true );
				return $copied;
			}
		}
		clean_post_cache( (int) $new_id );

		$created_selector = array( 'kind' => 'post', 'id' => (int) $new_id, 'key' => '', 'taxonomy' => '' );
		$created_loaded   = $this->resources->load_for_mutation( $created_selector );
		if ( is_wp_error( $created_loaded ) ) {
			wp_delete_post( (int) $new_id, true );
			return $created_loaded;
		}
		$after_fingerprint   = $this->resources->fingerprint_value( $created_loaded['value'] );
		$created_fingerprint = $this->created_state_fingerprint( (int) $new_id );
		if ( is_wp_error( $created_fingerprint ) ) {
			wp_delete_post( (int) $new_id, true );
			return $created_fingerprint;
		}
		$activity = $this->record_create_activity( $request_hash, $source_id, (int) $new_id, $after_fingerprint, $created_fingerprint );
		return array(
			'activityId'          => $activity['id'],
			'state'               => 'applied',
			'operation'           => 'create',
			'sourceAddress'       => $source_id > 0 ? 'post/' . $source_id : null,
			'address'             => 'post/' . (int) $new_id,
			'id'                  => (int) $new_id,
			'title'               => get_the_title( (int) $new_id ),
			'slug'                => get_post_field( 'post_name', (int) $new_id ),
			'status'              => get_post_status( (int) $new_id ),
			'reversible'          => true,
			'resourceFingerprint' => $after_fingerprint,
		);
	}

	public function delete_resource( array $input ) {
		if ( ! $this->is_enabled() ) {
			return new WP_Error( 'wpcommander_structured_writes_disabled', __( 'Structured writes are disabled in WPCommander settings.', 'wpcommander' ), array( 'status' => 403 ) );
		}
		if ( 'post' !== (string) ( $input['kind'] ?? '' ) ) {
			return new WP_Error( 'wpcommander_delete_kind_unsupported', __( 'Structured delete currently supports WordPress posts, pages, and custom post types. Other WordPress-accessible resources remain reachable through Abilities or universal execution.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		if ( true !== ( $input['confirmed'] ?? false ) ) {
			return new WP_Error( 'wpcommander_delete_confirmation_required', __( 'Deleting a WordPress resource requires clear delete intent. Set confirmed=true when the user explicitly requested the deletion.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$id          = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$permanent   = true === ( $input['permanent'] ?? false );
		$fingerprint = isset( $input['expectedResourceFingerprint'] ) ? strtolower( (string) $input['expectedResourceFingerprint'] ) : '';
		if ( $id < 1 || 1 !== preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			return new WP_Error( 'wpcommander_invalid_delete', __( 'Delete requires a resource ID and its fresh resourceFingerprint.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$request_hash = hash( 'sha256', (string) wp_json_encode( array( 'delete', 'post', $id, $fingerprint, $permanent ) ) );
		$replay = $this->find_delete_replay( $request_hash );
		if ( null !== $replay ) {
			return $replay;
		}

		$selector = array( 'kind' => 'post', 'id' => $id, 'key' => '', 'taxonomy' => '' );
		if ( ! $this->resources->can_inspect( $selector ) || ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'wpcommander_delete_forbidden', __( 'The current WordPress user cannot delete this post resource.', 'wpcommander' ), array( 'status' => 403 ) );
		}
		$loaded = $this->resources->load_for_mutation( $selector );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$current_fingerprint = $this->resources->fingerprint_value( $loaded['value'] );
		if ( ! hash_equals( $fingerprint, $current_fingerprint ) ) {
			return new WP_Error( 'wpcommander_stale_resource', __( 'The resource changed after it was inspected. Re-read it before deleting it.', 'wpcommander' ), array( 'status' => 409 ) );
		}

		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'attachment' === $post->post_type ) {
			return new WP_Error( 'wpcommander_delete_kind_unsupported', __( 'Structured post deletion does not delete media attachments. Use the appropriate WordPress or Full-control operation for media deletion.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$before_status = (string) $post->post_status;
		if ( $permanent ) {
			$deleted = wp_delete_post( $id, true );
			if ( ! $deleted || get_post( $id ) ) {
				return new WP_Error( 'wpcommander_delete_failed', __( 'WordPress could not permanently delete the resource.', 'wpcommander' ), array( 'status' => 500 ) );
			}
			$activity = $this->record_delete_activity( $request_hash, $id, $before_status, true, '', false );
		} else {
			$trashed = wp_trash_post( $id );
			if ( ! $trashed || 'trash' !== get_post_status( $id ) ) {
				return new WP_Error( 'wpcommander_delete_failed', __( 'WordPress could not move the resource to trash.', 'wpcommander' ), array( 'status' => 500 ) );
			}
			$after = $this->resources->load_for_mutation( $selector );
			if ( is_wp_error( $after ) ) {
				return $after;
			}
			$after_fingerprint = $this->resources->fingerprint_value( $after['value'] );
			$activity = $this->record_delete_activity( $request_hash, $id, $before_status, false, $after_fingerprint, true );
		}

		return array(
			'activityId' => $activity['id'],
			'state'      => 'applied',
			'operation'  => 'delete',
			'address'    => 'post/' . $id,
			'permanent'  => $permanent,
			'reversible' => ! $permanent,
		);
	}

	public function mutate_batch( array $input ) {
		$authorization = $this->authorize( $input );
		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}

		$changes = isset( $input['changes'] ) && is_array( $input['changes'] ) ? array_values( $input['changes'] ) : array();
		if ( empty( $changes ) || count( $changes ) > 50 ) {
			return new WP_Error( 'wpcommander_invalid_batch', __( 'Batch updates require between 1 and 50 changes.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$fingerprint = isset( $input['expectedResourceFingerprint'] ) ? strtolower( (string) $input['expectedResourceFingerprint'] ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			return new WP_Error( 'wpcommander_missing_fingerprint', __( 'A fresh resource fingerprint from inspectWordPressResource is required.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$selector = array(
			'kind'     => isset( $input['kind'] ) ? (string) $input['kind'] : '',
			'id'       => isset( $input['id'] ) ? (int) $input['id'] : 0,
			'key'      => isset( $input['key'] ) ? (string) $input['key'] : '',
			'taxonomy' => isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '',
		);
		$loaded = $this->resources->load_for_mutation( $selector );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$current_fingerprint = $this->resources->fingerprint_value( $loaded['value'] );
		$request_hash = hash( 'sha256', (string) wp_json_encode( array( 'update-batch', $selector, $changes, $fingerprint ) ) );
		$replay = $this->find_batch_replay( $request_hash, $current_fingerprint );
		if ( null !== $replay ) {
			return $replay;
		}
		if ( ! hash_equals( $fingerprint, $current_fingerprint ) ) {
			if ( $this->batch_matches_current_state( $loaded['value'], $changes ) ) {
				return array(
					'state'               => 'applied',
					'operation'           => 'update-batch',
					'address'             => $loaded['address'],
					'changeCount'         => count( $changes ),
					'replayed'            => true,
					'reversible'          => false,
					'resourceFingerprint' => $current_fingerprint,
				);
			}
			return new WP_Error( 'wpcommander_stale_resource', __( 'The resource changed after it was inspected. Re-read it before applying this command.', 'wpcommander' ), array( 'status' => 409 ) );
		}

		$working           = $loaded['value'];
		$prepared_changes  = array();
		$effective_changes = array();
		$seen_pointers     = array();
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) {
				return new WP_Error( 'wpcommander_invalid_batch_change', __( 'Every batch change must be an object.', 'wpcommander' ), array( 'status' => 400 ) );
			}
			$normalized = $this->normalize_input( array_merge( $selector, $change, array( 'expectedResourceFingerprint' => $fingerprint ) ) );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}
			if ( isset( $seen_pointers[ $normalized['pointer'] ] ) ) {
				return new WP_Error( 'wpcommander_duplicate_batch_pointer', __( 'A batch may change each JSON Pointer only once.', 'wpcommander' ), array( 'status' => 400 ) );
			}
			foreach ( array_keys( $seen_pointers ) as $seen_pointer ) {
				if ( $this->batch_pointers_overlap( (string) $seen_pointer, $normalized['pointer'] ) ) {
					return new WP_Error( 'wpcommander_overlapping_batch_pointer', __( 'A batch cannot update both a parent JSON Pointer and one of its descendants.', 'wpcommander' ), array( 'status' => 400 ) );
				}
			}
			$seen_pointers[ $normalized['pointer'] ] = true;

			$step_loaded          = $loaded;
			$step_loaded['value'] = $working;
			$prepared             = $this->prepare_change( $normalized, $step_loaded );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
			$next = $prepared['resourceValue'];
			if ( $this->values_equal( $working, $next ) ) {
				$working = $next;
				continue;
			}
			if ( $prepared['beforeExists'] && $this->encoded_size( $prepared['beforeValue'] ) > self::MAX_REVERSIBLE_BYTES ) {
				return new WP_Error( 'wpcommander_change_too_large_for_safe_revert', __( 'One batch target is too large for the current safe revert envelope. Use a narrower JSON Pointer.', 'wpcommander' ), array( 'status' => 413 ) );
			}
			if ( 'set' === $normalized['operation'] && $this->encoded_size( $normalized['value'] ) > self::MAX_REVERSIBLE_BYTES ) {
				return new WP_Error( 'wpcommander_value_too_large', __( 'One requested batch value is too large. Use a narrower target.', 'wpcommander' ), array( 'status' => 413 ) );
			}
			$prepared_changes[] = array(
				'pointer'      => $normalized['pointer'],
				'beforeExists' => $prepared['beforeExists'],
				'beforeValue'  => $prepared['beforeValue'],
			);
			$effective_changes[] = $normalized;
			$working             = $next;
		}
		if ( empty( $effective_changes ) ) {
			return array(
				'state'               => 'unchanged',
				'operation'           => 'update-batch',
				'address'             => $loaded['address'],
				'changeCount'         => 0,
				'reversible'          => false,
				'resourceFingerprint' => $current_fingerprint,
			);
		}
		if ( $this->encoded_size( $prepared_changes ) > self::MAX_REVERSIBLE_BYTES ) {
			return new WP_Error( 'wpcommander_batch_too_large_for_safe_revert', __( 'The combined batch before-state is too large for safe revert. Split the update into smaller batches.', 'wpcommander' ), array( 'status' => 413 ) );
		}

		$applied = $this->apply_batch_change( $selector, $loaded, $working, $effective_changes );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
		$verified = $this->verify_batch_state( $selector, $working, $effective_changes );
		if ( is_wp_error( $verified ) ) {
			$rollback = $this->apply_batch_change( $selector, $loaded, $loaded['value'], $effective_changes );
			if ( is_wp_error( $rollback ) ) {
				return new WP_Error( 'wpcommander_batch_rollback_failed', __( 'The batch could not be verified and automatic rollback also failed. Inspect the resource before continuing.', 'wpcommander' ), array( 'status' => 500, 'verificationError' => $verified->get_error_code(), 'rollbackError' => $rollback->get_error_code() ) );
			}
			return $verified;
		}

		$activity = $this->record_batch_activity( $request_hash, $selector, $loaded['address'], $prepared_changes, $current_fingerprint, $verified['resourceFingerprint'] );
		return array(
			'activityId'          => $activity['id'],
			'state'               => 'applied',
			'operation'           => 'update-batch',
			'address'             => $loaded['address'],
			'changeCount'         => count( $effective_changes ),
			'reversible'          => true,
			'resourceFingerprint' => $verified['resourceFingerprint'],
		);
	}

	private function batch_pointers_overlap( string $left, string $right ): bool {
		if ( '' === $left || '' === $right ) {
			return true;
		}
		return 0 === strpos( $left, $right . '/' ) || 0 === strpos( $right, $left . '/' );
	}

	private function batch_matches_current_state( $resource, array $changes ): bool {
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) || ! isset( $change['operation'], $change['pointer'] ) ) {
				return false;
			}
			$target = $this->read_target( $resource, (string) $change['pointer'] );
			if ( 'remove' === $change['operation'] ) {
				if ( ! is_wp_error( $target ) ) {
					return false;
				}
				continue;
			}
			if ( 'set' !== $change['operation'] || ! array_key_exists( 'value', $change ) || is_wp_error( $target ) || ! $this->values_equal( $target['value'], $change['value'] ) ) {
				return false;
			}
		}
		return true;
	}

	public function mutate( array $input ) {
		$authorization = $this->authorize( $input );
		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}

		$normalized = $this->normalize_input( $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$loaded = $this->resources->load_for_mutation( $normalized );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$before_resource_fingerprint = $this->resources->fingerprint_value( $loaded['value'] );
		$request_hash                = $this->request_hash( $normalized );
		$replay                      = $this->find_replay( $request_hash, $before_resource_fingerprint );
		if ( null !== $replay ) {
			$replay['replayed'] = true;
			return $replay;
		}

		if ( ! hash_equals( $normalized['expectedResourceFingerprint'], $before_resource_fingerprint ) ) {
			return new WP_Error(
				'wpcommander_stale_resource',
				__( 'The resource changed after it was inspected. Re-read it before applying this command.', 'wpcommander' ),
				array( 'status' => 409 )
			);
		}

		$prepared = $this->prepare_change( $normalized, $loaded );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$prepared_fingerprint = $this->resources->fingerprint_value( $prepared['resourceValue'] );
		if ( hash_equals( $before_resource_fingerprint, $prepared_fingerprint ) ) {
			$target = $this->read_target( $loaded['value'], $normalized['pointer'] );
			return array(
				'state'               => 'unchanged',
				'operation'           => $normalized['operation'],
				'target'              => $loaded['address'] . $normalized['pointer'],
				'address'             => $loaded['address'],
				'pointer'             => $normalized['pointer'],
				'reversible'          => false,
				'resourceFingerprint' => $before_resource_fingerprint,
				'valueFingerprint'    => is_wp_error( $target ) ? null : $this->resources->fingerprint_value( $target['value'] ),
			);
		}

		$size = $this->encoded_size( $prepared['beforeValue'] );
		if ( $prepared['beforeExists'] && $size > self::MAX_REVERSIBLE_BYTES ) {
			return new WP_Error(
				'wpcommander_change_too_large_for_safe_revert',
				__( 'The target is too large for the current safe revert envelope. Use a narrower JSON Pointer.', 'wpcommander' ),
				array( 'status' => 413 )
			);
		}

		if ( 'set' === $normalized['operation'] && $this->encoded_size( $normalized['value'] ) > self::MAX_REVERSIBLE_BYTES ) {
			return new WP_Error(
				'wpcommander_value_too_large',
				__( 'The requested value is too large for one structured command. Use a narrower target.', 'wpcommander' ),
				array( 'status' => 413 )
			);
		}

		$applied = $this->apply_prepared_change( $normalized, $loaded, $prepared['resourceValue'] );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$verified = $this->verify_change( $normalized, $prepared['resourceValue'] );
		if ( is_wp_error( $verified ) ) {
			$rollback = $this->rollback_prepared_change( $normalized, $prepared );
			if ( is_wp_error( $rollback ) ) {
				return new WP_Error(
					'wpcommander_rollback_failed',
					__( 'The write could not be verified and automatic rollback also failed. Inspect the resource before any further change.', 'wpcommander' ),
					array( 'status' => 500, 'verificationError' => $verified->get_error_code(), 'rollbackError' => $rollback->get_error_code() )
				);
			}
			return $verified;
		}

		$activity = $this->record_activity(
			$normalized,
			$loaded,
			$prepared,
			$before_resource_fingerprint,
			$verified['resourceFingerprint'],
			$verified['valueFingerprint'],
			$request_hash
		);

		return $this->public_activity_result( $activity, $verified );
	}

	private function authorize( array $input ) {
		if ( ! $this->is_enabled() ) {
			return new WP_Error(
				'wpcommander_structured_writes_disabled',
				__( 'Structured writes are disabled in WPCommander settings.', 'wpcommander' ),
				array( 'status' => 403 )
			);
		}

		$kind = isset( $input['kind'] ) ? (string) $input['kind'] : '';
		if ( 'option' === $kind && $this->is_high_risk_option( isset( $input['key'] ) ? (string) $input['key'] : '' ) ) {
			return new WP_Error( 'wpcommander_high_risk_option', __( 'This high-impact WordPress option is not available through normal structured commands.', 'wpcommander' ), array( 'status' => 403 ) );
		}

		if ( ! in_array( $kind, self::MUTABLE_KINDS, true ) ) {
			return new WP_Error(
				'wpcommander_resource_not_mutable',
				__( 'This resource kind is not available for structured writes yet.', 'wpcommander' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->resources->can_inspect( $input ) ) {
			return new WP_Error(
				'wpcommander_write_forbidden',
				__( 'The current WordPress user cannot change this resource.', 'wpcommander' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	private function is_high_risk_option( string $key ): bool {
		if ( preg_match( '/(?:^|_)user_roles$/', $key ) ) {
			return true;
		}
		return in_array( $key, array( 'siteurl', 'home', 'active_plugins', 'template', 'stylesheet', 'cron', 'rewrite_rules', 'uninstall_plugins', 'default_role', 'users_can_register', 'admin_email', 'new_admin_email' ), true );
	}

	private function normalize_input( array $input ) {
		$operation = isset( $input['operation'] ) ? sanitize_key( (string) $input['operation'] ) : '';
		if ( ! in_array( $operation, array( 'set', 'remove' ), true ) ) {
			return new WP_Error( 'wpcommander_invalid_mutation', __( 'Mutation operation must be set or remove.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$fingerprint = isset( $input['expectedResourceFingerprint'] ) ? strtolower( (string) $input['expectedResourceFingerprint'] ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			return new WP_Error( 'wpcommander_missing_fingerprint', __( 'A fresh resource fingerprint from inspectWordPressResource is required.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$pointer = isset( $input['pointer'] ) ? (string) $input['pointer'] : '';
		if ( '' !== $pointer && '/' !== $pointer[0] ) {
			return new WP_Error( 'wpcommander_invalid_pointer', __( 'JSON Pointer must start with a slash.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		if ( strlen( $pointer ) > 1000 || $this->pointer_is_sensitive( $pointer ) ) {
			return new WP_Error( 'wpcommander_restricted_pointer', __( 'That target path is not available for structured mutation.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		if ( 'set' === $operation && ! array_key_exists( 'value', $input ) ) {
			return new WP_Error( 'wpcommander_missing_value', __( 'Set operations require a value.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		return array(
			'kind'                        => isset( $input['kind'] ) ? (string) $input['kind'] : '',
			'id'                          => isset( $input['id'] ) ? (int) $input['id'] : 0,
			'key'                         => isset( $input['key'] ) ? (string) $input['key'] : '',
			'taxonomy'                    => isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '',
			'operation'                   => $operation,
			'pointer'                     => $pointer,
			'value'                       => array_key_exists( 'value', $input ) ? $input['value'] : null,
			'expectedResourceFingerprint' => $fingerprint,
		);
	}

	private function prepare_change( array $input, array $loaded ) {
		$kind    = $input['kind'];
		$pointer = $input['pointer'];

		if ( in_array( $kind, array( 'post', 'media', 'term', 'comment' ), true ) ) {
			return $this->prepare_object_change( $input, $loaded );
		}

		if ( ! in_array( $kind, array( 'post-meta', 'option' ), true ) ) {
			return new WP_Error( 'wpcommander_resource_not_mutable', __( 'Unsupported structured mutation target.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		if ( '' === $pointer ) {
			return $this->prepare_root_scalar_change( $input, $loaded );
		}

		$resource = $loaded['value'];
		return $this->prepare_pointer_change( $input, $resource );
	}

	private function prepare_object_change( array $input, array $loaded ) {
		if ( 'set' !== $input['operation'] ) {
			return new WP_Error( 'wpcommander_remove_not_supported', __( 'Remove is not supported for this WordPress object field.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$allowed = array(
			'post'    => array( '/title', '/content', '/excerpt', '/slug' ),
			'media'   => array( '/title', '/caption', '/description', '/alt' ),
			'term'    => array( '/name', '/slug', '/description', '/parent' ),
			'comment' => array( '/content' ),
		);
		$pointer = $input['pointer'];
		if ( ! in_array( $pointer, $allowed[ $input['kind'] ], true ) ) {
			return new WP_Error( 'wpcommander_restricted_pointer', __( 'That field is not writable through the structured mutation surface.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$field = substr( $pointer, 1 );
		$value = $input['value'];
		if ( 'parent' === $field ) {
			$value = (int) $value;
		} elseif ( ! is_scalar( $value ) && null !== $value ) {
			return new WP_Error( 'wpcommander_invalid_value', __( 'This WordPress field requires a scalar value.', 'wpcommander' ), array( 'status' => 400 ) );
		} elseif ( 'slug' === $field ) {
			$value = sanitize_title( null === $value ? '' : (string) $value );
		} else {
			$value = null === $value ? '' : (string) $value;
		}

		$resource           = $loaded['value'];
		$before             = array_key_exists( $field, $resource ) ? $resource[ $field ] : null;
		$resource[ $field ] = $value;

		return array(
			'field'        => $field,
			'beforeExists' => true,
			'beforeValue'  => $before,
			'resourceValue'=> $resource,
		);
	}

	private function prepare_root_scalar_change( array $input, array $loaded ) {
		if ( 'set' !== $input['operation'] ) {
			return new WP_Error( 'wpcommander_root_remove_blocked', __( 'Deleting an entire resource is not available through structured mutation.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		if ( is_array( $loaded['value'] ) || is_object( $loaded['value'] ) || is_array( $input['value'] ) || is_object( $input['value'] ) ) {
			return new WP_Error(
				'wpcommander_root_structure_replace_blocked',
				__( 'Structured values must be changed through a specific JSON Pointer instead of replacing the whole object.', 'wpcommander' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'beforeExists' => true,
			'beforeValue'  => $loaded['value'],
			'resourceValue'=> $input['value'],
		);
	}

	private function prepare_pointer_change( array $input, $resource ) {
		if ( ! is_array( $resource ) ) {
			return new WP_Error( 'wpcommander_pointer_requires_structure', __( 'JSON Pointer mutation requires a structured resource.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$before_exists = false;
		$before_value  = null;
		if ( 'set' === $input['operation'] ) {
			$result = $this->set_pointer( $resource, $input['pointer'], $input['value'], $before_exists, $before_value );
		} else {
			$result = $this->remove_pointer( $resource, $input['pointer'], $before_exists, $before_value );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ( $before_exists && $this->contains_sensitive_keys( $before_value ) ) || ( 'set' === $input['operation'] && $this->contains_sensitive_keys( $input['value'] ) ) ) {
			return new WP_Error(
				'wpcommander_sensitive_structure_blocked',
				__( 'That target contains credential-like nested fields. Change a narrower non-sensitive JSON Pointer instead.', 'wpcommander' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'beforeExists' => $before_exists,
			'beforeValue'  => $before_value,
			'resourceValue'=> $resource,
		);
	}

	private function set_pointer( &$resource, string $pointer, $replacement, bool &$before_exists, &$before_value ) {
		$segments = $this->pointer_segments( $pointer );
		if ( is_wp_error( $segments ) || empty( $segments ) ) {
			return is_wp_error( $segments ) ? $segments : new WP_Error( 'wpcommander_invalid_pointer', __( 'A nested JSON Pointer is required.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$leaf    = array_pop( $segments );
		$current =& $resource;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return new WP_Error( 'wpcommander_pointer_parent_not_found', __( 'The parent JSON Pointer path does not exist.', 'wpcommander' ), array( 'status' => 400 ) );
			}
			$current =& $current[ $segment ];
		}

		if ( ! is_array( $current ) ) {
			return new WP_Error( 'wpcommander_pointer_parent_not_structure', __( 'The JSON Pointer parent is not a structured value.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$before_exists = array_key_exists( $leaf, $current );
		$before_value  = $before_exists ? $current[ $leaf ] : null;
		if ( $this->is_list( $current ) && ctype_digit( (string) $leaf ) ) {
			$index = (int) $leaf;
			if ( ! $before_exists || $index >= count( $current ) ) {
				return new WP_Error(
					'wpcommander_array_append_blocked',
					__( 'Adding numeric array elements is not available through safe structured mutation yet.', 'wpcommander' ),
					array( 'status' => 400 )
				);
			}
			$current[ $index ] = $replacement;
			return true;
		}

		$current[ $leaf ] = $replacement;
		return true;
	}

	private function remove_pointer( &$resource, string $pointer, bool &$before_exists, &$before_value ) {
		$segments = $this->pointer_segments( $pointer );
		if ( is_wp_error( $segments ) || empty( $segments ) ) {
			return is_wp_error( $segments ) ? $segments : new WP_Error( 'wpcommander_invalid_pointer', __( 'A nested JSON Pointer is required.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$leaf    = array_pop( $segments );
		$current =& $resource;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return new WP_Error( 'wpcommander_pointer_not_found', __( 'JSON Pointer does not exist in this resource.', 'wpcommander' ), array( 'status' => 400 ) );
			}
			$current =& $current[ $segment ];
		}
		if ( ! is_array( $current ) || ! array_key_exists( $leaf, $current ) ) {
			return new WP_Error( 'wpcommander_pointer_not_found', __( 'JSON Pointer does not exist in this resource.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$before_exists = true;
		$before_value  = $current[ $leaf ];
		if ( $this->is_list( $current ) && ctype_digit( (string) $leaf ) ) {
			return new WP_Error(
				'wpcommander_array_remove_blocked',
				__( 'Removing numeric array elements is not available through safe structured mutation yet.', 'wpcommander' ),
				array( 'status' => 400 )
			);
		}
		unset( $current[ $leaf ] );
		return true;
	}

	private function pointer_segments( string $pointer ) {
		if ( '' === $pointer || '/' !== $pointer[0] ) {
			return new WP_Error( 'wpcommander_invalid_pointer', __( 'JSON Pointer must start with a slash.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$segments = array();
		foreach ( explode( '/', substr( $pointer, 1 ) ) as $segment ) {
			$segments[] = str_replace( array( '~1', '~0' ), array( '/', '~' ), $segment );
		}
		return $segments;
	}

	private function pointer_is_sensitive( string $pointer ): bool {
		if ( '' === $pointer ) {
			return false;
		}
		$segments = $this->pointer_segments( $pointer );
		if ( is_wp_error( $segments ) ) {
			return true;
		}
		foreach ( $segments as $segment ) {
			if ( preg_match( '/(?:pass(?:word)?|secret|token|api[-_]?key|credential|private[-_]?key|client[-_]?secret|access[-_]?key|license[-_]?key|(?:^|[-_])auth(?:orization)?(?:$|[-_]))/i', (string) $segment ) ) {
				return true;
			}
		}
		return false;
	}

	private function contains_sensitive_keys( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && preg_match( '/(?:pass(?:word)?|secret|token|api[-_]?key|credential|private[-_]?key|client[-_]?secret|access[-_]?key|license[-_]?key|(?:^|[-_])auth(?:orization)?(?:$|[-_]))/i', $key ) ) {
				return true;
			}
			if ( $this->contains_sensitive_keys( $item ) ) {
				return true;
			}
		}
		return false;
	}

	private function is_list( array $value ): bool {
		$expected = 0;
		foreach ( $value as $key => $_item ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}
		return true;
	}

	private function apply_prepared_change( array $input, array $loaded, $resource_value ) {
		switch ( $input['kind'] ) {
			case 'post-meta':
				return $this->apply_post_meta( $input, $loaded['encoding'], $resource_value );
			case 'option':
				return $this->apply_option( $input, $loaded['encoding'], $resource_value );
			case 'post':
				return $this->apply_post( $input, $resource_value );
			case 'media':
				return $this->apply_media( $input, $resource_value );
			case 'term':
				return $this->apply_term( $input, $resource_value );
			case 'comment':
				return $this->apply_comment( $input, $resource_value );
		}

		return new WP_Error( 'wpcommander_resource_not_mutable', __( 'Unsupported structured mutation target.', 'wpcommander' ), array( 'status' => 400 ) );
	}

	private function apply_batch_change( array $selector, array $loaded, $resource_value, array $changes ) {
		switch ( $selector['kind'] ) {
			case 'post-meta':
				return $this->apply_post_meta( $selector, $loaded['encoding'], $resource_value );
			case 'option':
				return $this->apply_option( $selector, $loaded['encoding'], $resource_value );
			case 'post':
				$fields  = array( '/title' => 'post_title', '/content' => 'post_content', '/excerpt' => 'post_excerpt', '/slug' => 'post_name' );
				$postarr = array( 'ID' => $selector['id'] );
				foreach ( $changes as $change ) {
					$pointer = (string) ( $change['pointer'] ?? '' );
					if ( isset( $fields[ $pointer ] ) ) {
						$postarr[ $fields[ $pointer ] ] = $resource_value[ substr( $pointer, 1 ) ];
					}
				}
				$result = wp_update_post( $postarr, true );
				return is_wp_error( $result ) ? $result : true;
			case 'media':
				$post_fields = array( '/title' => 'post_title', '/caption' => 'post_excerpt', '/description' => 'post_content' );
				$postarr     = array( 'ID' => $selector['id'] );
				$update_alt  = false;
				foreach ( $changes as $change ) {
					$pointer = (string) ( $change['pointer'] ?? '' );
					if ( isset( $post_fields[ $pointer ] ) ) {
						$postarr[ $post_fields[ $pointer ] ] = $resource_value[ substr( $pointer, 1 ) ];
					} elseif ( '/alt' === $pointer ) {
						$update_alt = true;
					}
				}
				if ( count( $postarr ) > 1 ) {
					$result = wp_update_post( $postarr, true );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				if ( $update_alt ) {
					update_post_meta( $selector['id'], '_wp_attachment_image_alt', $resource_value['alt'] );
				}
				return true;
			case 'term':
				$args = array();
				foreach ( $changes as $change ) {
					$key = ltrim( (string) ( $change['pointer'] ?? '' ), '/' );
					if ( in_array( $key, array( 'name', 'slug', 'description', 'parent' ), true ) ) {
						$args[ $key ] = $resource_value[ $key ];
					}
				}
				$result = wp_update_term( $selector['id'], $selector['taxonomy'], $args );
				return is_wp_error( $result ) ? $result : true;
			case 'comment':
				$result = wp_update_comment(
					array( 'comment_ID' => $selector['id'], 'comment_content' => $resource_value['content'] ),
					true
				);
				return is_wp_error( $result ) ? $result : true;
		}

		return new WP_Error( 'wpcommander_resource_not_mutable', __( 'Unsupported structured batch update target.', 'wpcommander' ), array( 'status' => 400 ) );
	}

	private function verify_batch_state( array $selector, $desired_resource, array $changes ) {
		$loaded = $this->resources->load_for_mutation( $selector );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		foreach ( $changes as $change ) {
			$pointer = (string) ( $change['pointer'] ?? '' );
			$actual  = $this->read_target( $loaded['value'], $pointer );
			$desired = $this->read_target( $desired_resource, $pointer );
			if ( is_wp_error( $actual ) && is_wp_error( $desired ) ) {
				continue;
			}
			if ( is_wp_error( $actual ) || is_wp_error( $desired ) || ! $this->values_equal( $actual['value'], $desired['value'] ) ) {
				return new WP_Error( 'wpcommander_verification_failed', __( 'WordPress accepted the batch update but one or more requested values could not be verified.', 'wpcommander' ), array( 'status' => 500, 'pointer' => $pointer ) );
			}
		}

		return array(
			'address'             => $loaded['address'],
			'resourceFingerprint' => $this->resources->fingerprint_value( $loaded['value'] ),
		);
	}

	private function encode_for_storage( $value, string $encoding ) {
		if ( 'json' === $encoding ) {
			$encoded = wp_json_encode( $value );
			if ( false === $encoded ) {
				return new WP_Error( 'wpcommander_json_encode_failed', __( 'The updated structured value could not be encoded as JSON.', 'wpcommander' ), array( 'status' => 500 ) );
			}
			return $encoded;
		}

		return $value;
	}

	private function apply_post_meta( array $input, string $encoding, $value ) {
		$stored = $this->encode_for_storage( $value, $encoding );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		if ( is_string( $stored ) ) {
			$stored = wp_slash( $stored );
		}
		update_post_meta( $input['id'], $input['key'], $stored );
		return true;
	}

	private function apply_option( array $input, string $encoding, $value ) {
		$stored = $this->encode_for_storage( $value, $encoding );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		update_option( $input['key'], $stored );
		return true;
	}

	private function apply_post( array $input, array $value ) {
		$fields = array(
			'/title'   => 'post_title',
			'/content' => 'post_content',
			'/excerpt' => 'post_excerpt',
			'/slug'    => 'post_name',
		);
		$field  = $fields[ $input['pointer'] ];
		$key    = substr( $input['pointer'], 1 );
		$result = wp_update_post( array( 'ID' => $input['id'], $field => $value[ $key ] ), true );
		return is_wp_error( $result ) ? $result : true;
	}

	private function apply_media( array $input, array $value ) {
		$key = substr( $input['pointer'], 1 );
		if ( 'alt' === $key ) {
			update_post_meta( $input['id'], '_wp_attachment_image_alt', $value['alt'] );
			return true;
		}

		$fields = array(
			'title'       => 'post_title',
			'caption'     => 'post_excerpt',
			'description' => 'post_content',
		);
		$result = wp_update_post( array( 'ID' => $input['id'], $fields[ $key ] => $value[ $key ] ), true );
		return is_wp_error( $result ) ? $result : true;
	}

	private function apply_term( array $input, array $value ) {
		$key    = substr( $input['pointer'], 1 );
		$args   = array();
		$args[ $key ] = $value[ $key ];
		$result = wp_update_term( $input['id'], $input['taxonomy'], $args );
		return is_wp_error( $result ) ? $result : true;
	}

	private function apply_comment( array $input, array $value ) {
		$result = wp_update_comment(
			array(
				'comment_ID'      => $input['id'],
				'comment_content' => $value['content'],
			),
			true
		);
		return is_wp_error( $result ) ? $result : true;
	}

	private function verify_change( array $input, $desired_resource ) {
		$loaded = $this->resources->load_for_mutation( $input );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$target = $this->read_target( $loaded['value'], $input['pointer'] );
		if ( 'set' === $input['operation'] ) {
			$desired = $this->read_target( $desired_resource, $input['pointer'] );
			if ( is_wp_error( $desired ) || is_wp_error( $target ) || ! $this->values_equal( $target['value'], $desired['value'] ) ) {
				return new WP_Error( 'wpcommander_verification_failed', __( 'WordPress accepted the write but the requested value could not be verified. WPCommander attempted rollback.', 'wpcommander' ), array( 'status' => 500 ) );
			}
		} elseif ( ! is_wp_error( $target ) ) {
			return new WP_Error( 'wpcommander_verification_failed', __( 'The removed JSON Pointer still exists after the write. WPCommander attempted rollback.', 'wpcommander' ), array( 'status' => 500 ) );
		}

		return array(
			'address'             => $loaded['address'],
			'resourceFingerprint' => $this->resources->fingerprint_value( $loaded['value'] ),
			'valueFingerprint'    => is_wp_error( $target ) ? null : $this->resources->fingerprint_value( $target['value'] ),
		);
	}

	private function read_target( $resource, string $pointer ) {
		if ( '' === $pointer ) {
			return array( 'exists' => true, 'value' => $resource );
		}
		$segments = $this->pointer_segments( $pointer );
		if ( is_wp_error( $segments ) ) {
			return $segments;
		}
		$current = $resource;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return new WP_Error( 'wpcommander_pointer_not_found', __( 'JSON Pointer does not exist in this resource.', 'wpcommander' ) );
			}
			$current = $current[ $segment ];
		}
		return array( 'exists' => true, 'value' => $current );
	}

	private function values_equal( $left, $right ): bool {
		return $this->resources->fingerprint_value( $left ) === $this->resources->fingerprint_value( $right );
	}

	private function rollback_prepared_change( array $input, array $prepared ) {
		$loaded = $this->resources->load_for_mutation( $input );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$resource = $loaded['value'];
		if ( '' === $input['pointer'] ) {
			$resource = $prepared['beforeValue'];
		} else {
			$before_exists = false;
			$before_value  = null;
			$result = $prepared['beforeExists']
				? $this->set_pointer( $resource, $input['pointer'], $prepared['beforeValue'], $before_exists, $before_value )
				: $this->remove_pointer( $resource, $input['pointer'], $before_exists, $before_value );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $this->apply_prepared_change( $input, $loaded, $resource );
	}

	private function encoded_size( $value ): int {
		$encoded = maybe_serialize( $value );
		return is_string( $encoded ) ? strlen( $encoded ) : self::MAX_REVERSIBLE_BYTES + 1;
	}

	private function copy_post_relations( WP_Post $source, int $target_id, bool $copy_meta, bool $copy_taxonomies ) {
		if ( $copy_meta ) {
			$excluded    = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time' );
			$meta = get_post_meta( $source->ID );
			foreach ( $meta as $key => $values ) {
				if ( in_array( (string) $key, $excluded, true ) ) {
					continue;
				}
				delete_post_meta( $target_id, (string) $key );
				foreach ( (array) $values as $value ) {
					$value  = maybe_unserialize( $value );
					$stored = is_string( $value ) ? wp_slash( $value ) : $value;
					if ( false === add_post_meta( $target_id, (string) $key, $stored ) ) {
						return new WP_Error( 'wpcommander_create_meta_failed', __( 'WordPress could not copy all post metadata to the new resource.', 'wpcommander' ), array( 'status' => 500, 'metaKey' => (string) $key ) );
					}
				}
			}
		}

		if ( $copy_taxonomies ) {
			foreach ( get_object_taxonomies( $source->post_type, 'names' ) as $taxonomy ) {
				$terms = wp_get_object_terms( $source->ID, $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $terms ) ) {
					return $terms;
				}
				$set = wp_set_object_terms( $target_id, array_map( 'intval', $terms ), $taxonomy, false );
				if ( is_wp_error( $set ) ) {
					return $set;
					}
			}
		}
		return true;
	}

	private function created_state_fingerprint( int $post_id ) {
		$loaded = $this->resources->load_for_mutation( array( 'kind' => 'post', 'id' => $post_id, 'key' => '', 'taxonomy' => '' ) );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$meta = get_post_meta( $post_id );
		ksort( $meta );
		foreach ( $meta as $key => $values ) {
			$normalized = array_map( 'maybe_unserialize', (array) $values );
			usort(
				$normalized,
				static function ( $left, $right ): int {
					return strcmp( maybe_serialize( $left ), maybe_serialize( $right ) );
				}
			);
			$meta[ $key ] = $normalized;
		}
		$taxonomies = array();
		$post       = get_post( $post_id );
		if ( $post instanceof WP_Post ) {
			$names = get_object_taxonomies( $post->post_type, 'names' );
			sort( $names );
			foreach ( $names as $taxonomy ) {
				$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $terms ) ) {
					return $terms;
				}
				$terms = array_map( 'intval', $terms );
				sort( $terms );
				$taxonomies[ $taxonomy ] = $terms;
			}
		}
		return $this->resources->fingerprint_value( array( 'post' => $loaded['value'], 'meta' => $meta, 'taxonomies' => $taxonomies ) );
	}

	private function find_create_replay( string $request_hash ) {
		foreach ( $this->get_activity() as $entry ) {
			if ( 'create' !== ( $entry['operation'] ?? '' ) || $request_hash !== ( $entry['requestHash'] ?? '' ) || 'applied' !== ( $entry['state'] ?? '' ) ) {
				continue;
			}
			$id = (int) ( $entry['idValue'] ?? 0 );
			if ( $id < 1 || ! get_post( $id ) ) {
				continue;
			}
			$loaded = $this->resources->load_for_mutation( array( 'kind' => 'post', 'id' => $id, 'key' => '', 'taxonomy' => '' ) );
			if ( is_wp_error( $loaded ) ) {
				continue;
			}
			return array(
				'activityId'          => $entry['id'],
				'state'               => 'applied',
				'operation'           => 'create',
				'sourceAddress'       => ! empty( $entry['sourceId'] ) ? 'post/' . (int) $entry['sourceId'] : null,
				'address'             => 'post/' . $id,
				'id'                  => $id,
				'title'               => get_the_title( $id ),
				'slug'                => get_post_field( 'post_name', $id ),
				'status'              => get_post_status( $id ),
				'replayed'            => true,
				'reversible'          => true,
				'resourceFingerprint' => $this->resources->fingerprint_value( $loaded['value'] ),
			);
		}
		return null;
	}

	private function record_create_activity( string $request_hash, int $source_id, int $target_id, string $after_fingerprint, string $created_fingerprint ): array {
		$entry = array(
			'id'                        => wp_generate_uuid4(),
			'requestHash'               => $request_hash,
			'actorId'                   => get_current_user_id(),
			'address'                   => 'post/' . $target_id,
			'kind'                      => 'post',
			'idValue'                   => $target_id,
			'key'                       => '',
			'taxonomy'                  => '',
			'pointer'                   => '',
			'operation'                 => 'create',
			'state'                     => 'applied',
			'timestamp'                 => gmdate( 'c' ),
			'sourceId'                  => $source_id,
			'beforeExists'              => false,
			'beforeValue'               => null,
			'beforeResourceFingerprint' => '',
			'afterResourceFingerprint'  => $after_fingerprint,
			'createdStateFingerprint'    => $created_fingerprint,
			'afterValueFingerprint'     => null,
			'reversible'                => true,
		);
		$items = $this->get_activity();
		array_unshift( $items, $entry );
		update_option( self::OPTION_ACTIVITY, array_slice( $items, 0, self::MAX_ACTIVITY ), false );
		return $entry;
	}

	private function find_batch_replay( string $request_hash, string $current_fingerprint ) {
		foreach ( $this->get_activity() as $entry ) {
			if ( 'update-batch' !== ( $entry['operation'] ?? '' ) || $request_hash !== ( $entry['requestHash'] ?? '' ) || 'applied' !== ( $entry['state'] ?? '' ) ) {
				continue;
			}
			if ( ! hash_equals( (string) ( $entry['afterResourceFingerprint'] ?? '' ), $current_fingerprint ) ) {
				continue;
			}
			return array(
				'activityId'          => $entry['id'],
				'state'               => 'applied',
				'operation'           => 'update-batch',
				'address'             => $entry['address'],
				'changeCount'         => (int) ( $entry['changeCount'] ?? 0 ),
				'replayed'            => true,
				'reversible'          => true,
				'resourceFingerprint' => $current_fingerprint,
			);
		}
		return null;
	}

	private function record_batch_activity( string $request_hash, array $selector, string $address, array $prepared_changes, string $before_fingerprint, string $after_fingerprint ): array {
		$entry = array(
			'id'                        => wp_generate_uuid4(),
			'requestHash'               => $request_hash,
			'actorId'                   => get_current_user_id(),
			'address'                   => $address,
			'kind'                      => $selector['kind'],
			'idValue'                   => $selector['id'],
			'key'                       => $selector['key'],
			'taxonomy'                  => $selector['taxonomy'],
			'pointer'                   => '',
			'operation'                 => 'update-batch',
			'state'                     => 'applied',
			'timestamp'                 => gmdate( 'c' ),
			'preparedChanges'           => $prepared_changes,
			'changeCount'               => count( $prepared_changes ),
			'beforeResourceFingerprint' => $before_fingerprint,
			'afterResourceFingerprint'  => $after_fingerprint,
			'afterValueFingerprint'     => null,
			'reversible'                => true,
		);
		$items = $this->get_activity();
		array_unshift( $items, $entry );
		update_option( self::OPTION_ACTIVITY, array_slice( $items, 0, self::MAX_ACTIVITY ), false );
		return $entry;
	}

	private function find_delete_replay( string $request_hash ) {
		foreach ( $this->get_activity() as $entry ) {
			if ( 'delete' !== ( $entry['operation'] ?? '' ) || $request_hash !== ( $entry['requestHash'] ?? '' ) || 'applied' !== ( $entry['state'] ?? '' ) ) {
				continue;
			}
			$id        = (int) ( $entry['idValue'] ?? 0 );
			$permanent = ! empty( $entry['permanent'] );
			if ( $permanent ) {
				if ( get_post( $id ) ) {
					continue;
				}
			} elseif ( 'trash' !== get_post_status( $id ) ) {
				continue;
			}
			return array(
				'activityId' => $entry['id'],
				'state'      => 'applied',
				'operation'  => 'delete',
				'address'    => $entry['address'],
				'permanent'  => $permanent,
				'replayed'   => true,
				'reversible' => ! empty( $entry['reversible'] ),
			);
		}
		return null;
	}

	private function record_delete_activity( string $request_hash, int $id, string $before_status, bool $permanent, string $after_fingerprint, bool $reversible ): array {
		$entry = array(
			'id'                        => wp_generate_uuid4(),
			'requestHash'               => $request_hash,
			'actorId'                   => get_current_user_id(),
			'address'                   => 'post/' . $id,
			'kind'                      => 'post',
			'idValue'                   => $id,
			'key'                       => '',
			'taxonomy'                  => '',
			'pointer'                   => '',
			'operation'                 => 'delete',
			'state'                     => 'applied',
			'timestamp'                 => gmdate( 'c' ),
			'beforeStatus'              => $before_status,
			'permanent'                 => $permanent,
			'reversible'                => $reversible,
			'beforeExists'              => true,
			'beforeValue'               => null,
			'beforeResourceFingerprint' => '',
			'afterResourceFingerprint'  => $after_fingerprint,
			'afterValueFingerprint'     => null,
		);
		$items = $this->get_activity();
		array_unshift( $items, $entry );
		update_option( self::OPTION_ACTIVITY, array_slice( $items, 0, self::MAX_ACTIVITY ), false );
		return $entry;
	}

	private function request_hash( array $input ): string {
		$payload = array(
			'kind'        => $input['kind'],
			'id'          => $input['id'],
			'key'         => $input['key'],
			'taxonomy'    => $input['taxonomy'],
			'operation'   => $input['operation'],
			'pointer'     => $input['pointer'],
			'value'       => $input['value'],
			'expected'    => $input['expectedResourceFingerprint'],
		);
		return hash( 'sha256', (string) wp_json_encode( $payload ) );
	}

	private function get_activity(): array {
		$items = get_option( self::OPTION_ACTIVITY, array() );
		return is_array( $items ) ? $items : array();
	}

	private function find_replay( string $request_hash, string $current_resource_fingerprint ) {
		foreach ( $this->get_activity() as $entry ) {
			if ( empty( $entry['requestHash'] ) || $request_hash !== $entry['requestHash'] ) {
				continue;
			}
			if ( 'applied' !== ( $entry['state'] ?? '' ) || $current_resource_fingerprint !== ( $entry['afterResourceFingerprint'] ?? '' ) ) {
				continue;
			}
			return $this->public_activity_result(
				$entry,
				array(
					'address'             => $entry['address'],
					'resourceFingerprint' => $entry['afterResourceFingerprint'],
					'valueFingerprint'    => $entry['afterValueFingerprint'] ?? null,
				)
			);
		}
		return null;
	}

	private function record_activity( array $input, array $loaded, array $prepared, string $before_fingerprint, string $after_fingerprint, $after_value_fingerprint, string $request_hash ): array {
		$entry = array(
			'id'                        => wp_generate_uuid4(),
			'requestHash'               => $request_hash,
			'actorId'                   => get_current_user_id(),
			'address'                   => $loaded['address'],
			'kind'                      => $input['kind'],
			'idValue'                   => $input['id'],

			'key'                       => $input['key'],
			'taxonomy'                  => $input['taxonomy'],
			'pointer'                   => $input['pointer'],
			'operation'                 => $input['operation'],
			'state'                     => 'applied',
			'timestamp'                 => gmdate( 'c' ),
			'beforeExists'              => $prepared['beforeExists'],
			'beforeValue'               => $prepared['beforeValue'],
			'beforeResourceFingerprint' => $before_fingerprint,
			'afterResourceFingerprint'  => $after_fingerprint,
			'afterValueFingerprint'     => $after_value_fingerprint,
			'reversible'                => true,
		);

		$items = $this->get_activity();
		array_unshift( $items, $entry );
		$items = array_slice( $items, 0, self::MAX_ACTIVITY );
		update_option( self::OPTION_ACTIVITY, $items, false );
		return $entry;
	}

	private function public_activity_result( array $entry, array $verified ): array {
		return array(
			'activityId'          => $entry['id'],
			'state'               => $entry['state'],
			'operation'           => $entry['operation'],
			'target'              => $entry['address'] . $entry['pointer'],
			'address'             => $entry['address'],
			'pointer'             => $entry['pointer'],
			'reversible'          => (bool) ( $entry['reversible'] ?? true ),
			'resourceFingerprint' => $verified['resourceFingerprint'],
			'valueFingerprint'    => $verified['valueFingerprint'],
		);
	}

	public function get_public_activity( int $limit = 20 ): array {
		$items = array();
		foreach ( array_slice( $this->get_activity(), 0, max( 1, min( $limit, 50 ) ) ) as $entry ) {
			$items[] = array(
				'id'        => $entry['id'],
				'action'    => $entry['operation'],
				'target'    => $entry['address'] . $entry['pointer'],
				'state'     => $entry['state'],
				'timestamp' => $entry['timestamp'],
				'reversible'=> (bool) ( $entry['reversible'] ?? true ),
			);
		}
		return $items;
	}

	public function revert( array $input ) {
		if ( ! $this->is_enabled() ) {
			return new WP_Error( 'wpcommander_structured_writes_disabled', __( 'Structured writes are disabled in WPCommander settings.', 'wpcommander' ), array( 'status' => 403 ) );
		}
		$activity_id = isset( $input['activityId'] ) ? sanitize_text_field( (string) $input['activityId'] ) : '';
		if ( '' === $activity_id ) {
			return new WP_Error( 'wpcommander_missing_activity', __( 'Activity ID is required.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$items = $this->get_activity();
		$index = $this->find_activity_index( $items, $activity_id );
		if ( null === $index ) {
			return new WP_Error( 'wpcommander_activity_not_found', __( 'The requested activity entry was not found.', 'wpcommander' ), array( 'status' => 404 ) );
		}

		$entry = $items[ $index ];
		if ( 'reverted' === ( $entry['state'] ?? '' ) ) {
			return array(
				'activityId' => $entry['id'],
				'state'      => 'reverted',
				'target'     => $entry['address'] . $entry['pointer'],
				'replayed'   => true,
			);
		}
		if ( 'applied' !== ( $entry['state'] ?? '' ) ) {
			return new WP_Error( 'wpcommander_activity_not_revertible', __( 'This activity entry is not in an applied state.', 'wpcommander' ), array( 'status' => 409 ) );
		}
		if ( false === (bool) ( $entry['reversible'] ?? true ) ) {
			return new WP_Error( 'wpcommander_activity_not_revertible', __( 'This activity entry represents an irreversible operation.', 'wpcommander' ), array( 'status' => 409 ) );
		}

		if ( 'delete' === ( $entry['operation'] ?? '' ) ) {
			$target_id = (int) ( $entry['idValue'] ?? 0 );
			if ( $target_id < 1 || ! current_user_can( 'delete_post', $target_id ) ) {
				return new WP_Error( 'wpcommander_revert_forbidden', __( 'The current WordPress user cannot restore this deleted post.', 'wpcommander' ), array( 'status' => 403 ) );
			}
			$loaded = $this->resources->load_for_mutation( array( 'kind' => 'post', 'id' => $target_id, 'key' => '', 'taxonomy' => '' ) );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			$current_fingerprint = $this->resources->fingerprint_value( $loaded['value'] );
			if ( ! hash_equals( (string) ( $entry['afterResourceFingerprint'] ?? '' ), $current_fingerprint ) ) {
				return new WP_Error( 'wpcommander_revert_stale_resource', __( 'The trashed post changed after deletion. Inspect it before attempting recovery.', 'wpcommander' ), array( 'status' => 409 ) );
			}
			$restored = wp_untrash_post( $target_id );
			if ( ! $restored || 'trash' === get_post_status( $target_id ) ) {
				return new WP_Error( 'wpcommander_delete_revert_failed', __( 'WordPress could not restore the trashed resource.', 'wpcommander' ), array( 'status' => 500 ) );
			}
			$items[ $index ]['state']      = 'reverted';
			$items[ $index ]['revertedAt'] = gmdate( 'c' );
			update_option( self::OPTION_ACTIVITY, $items, false );
			return array(
				'activityId' => $entry['id'],
				'state'      => 'reverted',
				'target'     => $entry['address'],
			);
		}

		if ( 'update-batch' === ( $entry['operation'] ?? '' ) ) {
			$selector = array(
				'kind'     => (string) ( $entry['kind'] ?? '' ),
				'id'       => (int) ( $entry['idValue'] ?? 0 ),
				'key'      => (string) ( $entry['key'] ?? '' ),
				'taxonomy' => (string) ( $entry['taxonomy'] ?? '' ),
			);
			$authorization = $this->authorize( $selector );
			if ( is_wp_error( $authorization ) ) {
				return $authorization;
			}
			$loaded = $this->resources->load_for_mutation( $selector );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			$current_fingerprint = $this->resources->fingerprint_value( $loaded['value'] );
			if ( ! hash_equals( (string) ( $entry['afterResourceFingerprint'] ?? '' ), $current_fingerprint ) ) {
				return new WP_Error( 'wpcommander_revert_stale_resource', __( 'The resource changed after this batch update. Revert is blocked to avoid overwriting newer work.', 'wpcommander' ), array( 'status' => 409 ) );
			}
			$prepared_changes = isset( $entry['preparedChanges'] ) && is_array( $entry['preparedChanges'] ) ? $entry['preparedChanges'] : array();
			if ( empty( $prepared_changes ) ) {
				return new WP_Error( 'wpcommander_batch_revert_missing_state', __( 'The batch activity does not contain reversible before-state.', 'wpcommander' ), array( 'status' => 409 ) );
			}
			$resource = $loaded['value'];
			$revert_changes = array();
			foreach ( array_reverse( $prepared_changes ) as $change ) {
				$pointer       = (string) ( $change['pointer'] ?? '' );
				$before_exists = ! empty( $change['beforeExists'] );
				$before_value  = $change['beforeValue'] ?? null;
				$ignored_exists = false;
				$ignored_value  = null;
				$result = $before_exists
					? $this->set_pointer( $resource, $pointer, $before_value, $ignored_exists, $ignored_value )
					: $this->remove_pointer( $resource, $pointer, $ignored_exists, $ignored_value );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$revert_changes[] = array( 'pointer' => $pointer );
			}
			$applied = $this->apply_batch_change( $selector, $loaded, $resource, $revert_changes );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			$restored = $this->resources->load_for_mutation( $selector );
			if ( is_wp_error( $restored ) ) {
				return $restored;
			}
			$restored_fingerprint = $this->resources->fingerprint_value( $restored['value'] );
			if ( ! hash_equals( (string) ( $entry['beforeResourceFingerprint'] ?? '' ), $restored_fingerprint ) ) {
				$rollback = $this->apply_batch_change( $selector, $restored, $loaded['value'], $revert_changes );
				if ( is_wp_error( $rollback ) ) {
					return new WP_Error( 'wpcommander_batch_revert_rollback_failed', __( 'The batch revert could not be verified and the post-revert rollback also failed. Inspect the resource before continuing.', 'wpcommander' ), array( 'status' => 500, 'rollbackError' => $rollback->get_error_code() ) );
				}
				return new WP_Error( 'wpcommander_revert_verification_failed', __( 'The batch revert did not restore the exact prior resource state.', 'wpcommander' ), array( 'status' => 500 ) );
			}
			$items[ $index ]['state']                     = 'reverted';
			$items[ $index ]['revertedAt']                = gmdate( 'c' );
			$items[ $index ]['revertResourceFingerprint'] = $restored_fingerprint;
			update_option( self::OPTION_ACTIVITY, $items, false );
			return array(
				'activityId'          => $entry['id'],
				'state'               => 'reverted',
				'target'              => $entry['address'],
				'resourceFingerprint' => $restored_fingerprint,
			);
		}

		if ( 'create' === ( $entry['operation'] ?? '' ) ) {
			$target_id = (int) ( $entry['idValue'] ?? 0 );
			if ( $target_id < 1 || ! current_user_can( 'delete_post', $target_id ) ) {
				return new WP_Error( 'wpcommander_revert_forbidden', __( 'The current WordPress user cannot remove this created post.', 'wpcommander' ), array( 'status' => 403 ) );
			}
			$current_fingerprint = $this->created_state_fingerprint( $target_id );
			if ( is_wp_error( $current_fingerprint ) ) {
				return $current_fingerprint;
			}
			$expected_created_fingerprint = (string) ( $entry['createdStateFingerprint'] ?? $entry['afterResourceFingerprint'] ?? '' );
			if ( '' === $expected_created_fingerprint || ! hash_equals( $expected_created_fingerprint, $current_fingerprint ) ) {
				return new WP_Error( 'wpcommander_revert_stale_resource', __( 'The created post changed after creation. Revert its later changes before removing it.', 'wpcommander' ), array( 'status' => 409 ) );
			}
			$deleted = wp_delete_post( $target_id, true );
			if ( ! $deleted ) {
				return new WP_Error( 'wpcommander_create_revert_failed', __( 'WordPress could not remove the created post.', 'wpcommander' ), array( 'status' => 500 ) );
			}
			$items[ $index ]['state']      = 'reverted';
			$items[ $index ]['revertedAt'] = gmdate( 'c' );
			update_option( self::OPTION_ACTIVITY, $items, false );
			return array(
				'activityId' => $entry['id'],
				'state'      => 'reverted',
				'target'     => $entry['address'],
			);
		}

		$selector = array(
			'kind'     => $entry['kind'],
			'id'       => (int) ( $entry['idValue'] ?? 0 ),
			'key'      => (string) ( $entry['key'] ?? '' ),
			'taxonomy' => (string) ( $entry['taxonomy'] ?? '' ),
		);
		$authorization = $this->authorize( $selector );
		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}
		$loaded = $this->resources->load_for_mutation( $selector );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$current_fingerprint = $this->resources->fingerprint_value( $loaded['value'] );
		if ( ! hash_equals( (string) $entry['afterResourceFingerprint'], $current_fingerprint ) ) {
			return new WP_Error(
				'wpcommander_revert_stale_resource',
				__( 'The resource changed after this activity entry. Revert is blocked to avoid overwriting newer work.', 'wpcommander' ),
				array( 'status' => 409 )
			);
		}

		$restore_input = array_merge(
			$selector,
			array(
				'pointer'   => (string) $entry['pointer'],
				'operation' => ! empty( $entry['beforeExists'] ) ? 'set' : 'remove',
				'value'     => $entry['beforeValue'] ?? null,
			)
		);
		$resource = $loaded['value'];
		$prepared = $this->prepare_revert_resource( $restore_input, $resource, $entry );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$applied = $this->apply_prepared_change( $restore_input, $loaded, $prepared );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$verification = $this->verify_revert( $restore_input, $entry );
		if ( is_wp_error( $verification ) ) {
			$this->apply_prepared_change( $restore_input, $loaded, $loaded['value'] );
			return $verification;
		}

		$items[ $index ]['state']                     = 'reverted';
		$items[ $index ]['revertedAt']                = gmdate( 'c' );
		$items[ $index ]['revertResourceFingerprint'] = $verification['resourceFingerprint'];
		update_option( self::OPTION_ACTIVITY, $items, false );

		return array(
			'activityId'          => $entry['id'],
			'state'               => 'reverted',
			'target'              => $entry['address'] . $entry['pointer'],
			'resourceFingerprint' => $verification['resourceFingerprint'],
		);
	}

	private function find_activity_index( array $items, string $activity_id ) {
		foreach ( $items as $index => $entry ) {
			if ( isset( $entry['id'] ) && $activity_id === $entry['id'] ) {
				return $index;
			}
		}
		return null;
	}

	private function prepare_revert_resource( array $input, $resource, array $entry ) {
		if ( '' === $input['pointer'] ) {
			return $entry['beforeValue'];
		}

		$before_exists = false;
		$before_value  = null;
		if ( ! empty( $entry['beforeExists'] ) ) {
			$result = $this->set_pointer( $resource, $input['pointer'], $entry['beforeValue'], $before_exists, $before_value );
		} else {
			$result = $this->remove_pointer( $resource, $input['pointer'], $before_exists, $before_value );
		}
		return is_wp_error( $result ) ? $result : $resource;
	}

	private function verify_revert( array $input, array $entry ) {
		$loaded = $this->resources->load_for_mutation( $input );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$target = $this->read_target( $loaded['value'], $input['pointer'] );
		if ( ! empty( $entry['beforeExists'] ) ) {
			if ( is_wp_error( $target ) || ! $this->values_equal( $target['value'], $entry['beforeValue'] ) ) {
				return new WP_Error( 'wpcommander_revert_verification_failed', __( 'The reverted value could not be verified.', 'wpcommander' ), array( 'status' => 500 ) );
			}
		} elseif ( ! is_wp_error( $target ) ) {
			return new WP_Error( 'wpcommander_revert_verification_failed', __( 'The reverted path still exists after it was removed.', 'wpcommander' ), array( 'status' => 500 ) );
		}

		return array( 'resourceFingerprint' => $this->resources->fingerprint_value( $loaded['value'] ) );
	}
}
