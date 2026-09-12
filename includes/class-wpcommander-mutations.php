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
			'reversible'          => true,
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
				'reversible'=> true,
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
