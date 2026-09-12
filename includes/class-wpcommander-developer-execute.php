<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPCommander_Developer_Execute {
	private const OPTION_ENABLED = 'wpcommander_universal_execution_enabled';
	private const OPTION_ACTIVITY = 'wpcommander_execution_log';
	private const MAX_ACTIVITY = 50;
	private const MAX_OUTPUT_BYTES = 65536;
	private const MAX_FILE_BYTES = 4194304;
	private const MAX_CODE_BYTES = 16384;
	private const MAX_SQL_BYTES = 32768;
	private const MAX_CLI_SECONDS = 20;

	public function is_enabled(): bool {
		return '1' === (string) get_option( self::OPTION_ENABLED, '0' );
	}

	public function set_enabled( bool $enabled ): bool {
		update_option( self::OPTION_ENABLED, $enabled ? '1' : '0', false );
		return $this->is_enabled() === $enabled;
	}

	public function can_execute(): bool {
		return $this->is_enabled() && current_user_can( 'manage_options' );
	}

	public function execute( array $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'wpcommander_universal_forbidden', __( 'Universal execution requires administrator permissions.', 'wpcommander' ), array( 'status' => 403 ) );
		}
		if ( ! $this->is_enabled() ) {
			return new WP_Error( 'wpcommander_universal_disabled', __( 'Universal execution is disabled in WPCommander settings.', 'wpcommander' ), array( 'status' => 403 ) );
		}
		if ( true !== ( $input['confirmed'] ?? false ) ) {
			return new WP_Error( 'wpcommander_privileged_confirmation_required', __( 'This operation uses the universal execution plane. Retry with confirmed=true only after the user has clearly requested the privileged operation.', 'wpcommander' ), array( 'status' => 409 ) );
		}

		$operation = isset( $input['operation'] ) ? sanitize_key( (string) $input['operation'] ) : '';
		$started   = microtime( true );
		switch ( $operation ) {
			case 'internal-rest':
				$result = $this->internal_rest( $input );
				break;
			case 'call-function':
				$result = $this->call_function( $input );
				break;
			case 'php-eval':
				$result = $this->php_eval( $input );
				break;
			case 'sql':
				$result = $this->sql( $input );
				break;
			case 'write-file':
				$result = $this->write_file( $input );
				break;
			case 'make-directory':
				$result = $this->make_directory( $input );
				break;
			case 'move-path':
				$result = $this->move_path( $input );
				break;
			case 'delete-path':
				$result = $this->delete_path( $input );
				break;
			case 'wp-cli':
				$result = $this->wp_cli( $input );
				break;
			default:
				$result = new WP_Error( 'wpcommander_unknown_execution_operation', __( 'Unsupported universal execution operation.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$entry = $this->record_activity( $operation, $input, is_wp_error( $result ) ? 'failed' : 'applied', microtime( true ) - $started );
		if ( is_wp_error( $result ) ) {
			$result = $this->sanitize_error( $result );
			$data   = $result->get_error_data();
			$data   = is_array( $data ) ? $data : array();
			$data['executionId'] = $entry['id'];
			$result->add_data( $data );
			return $result;
		}

		return array(
			'executionId' => $entry['id'],
			'operation'   => $operation,
			'state'       => 'applied',
			'result'      => $this->bound_output( $result ),
		);
	}

	private function internal_rest( array $input ) {
		$route  = isset( $input['route'] ) ? (string) $input['route'] : '';
		$method = strtoupper( isset( $input['method'] ) ? (string) $input['method'] : 'GET' );
		if ( '' === $route || '/' !== $route[0] || ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			return new WP_Error( 'wpcommander_invalid_internal_rest', __( 'A valid internal REST route and method are required.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		if ( 0 === strpos( $route, '/wpcommander/v1/developer/execute' ) || 0 === strpos( $route, '/wpcommander/v1/settings/' ) ) {
			return new WP_Error( 'wpcommander_recursive_execution_blocked', __( 'Universal execution cannot recursively invoke its own execution or settings routes.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		if ( false !== stripos( $route, 'application-password' ) || 0 === strpos( $route, '/wpcommander/v1/setup/' ) ) {
			return new WP_Error( 'wpcommander_credential_route_blocked', __( 'Credential creation and application-password routes are not available through universal execution.', 'wpcommander' ), array( 'status' => 403 ) );
		}

		$request = new WP_REST_Request( $method, $route );
		$body    = isset( $input['body'] ) && is_array( $input['body'] ) ? $input['body'] : array();
		if ( 'GET' === $method ) {
			$request->set_query_params( $body );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}
		$sensitive_request = $this->contains_sensitive_context( $route ) || $this->contains_sensitive_context( $body );
		return array( 'status' => $response->get_status(), 'data' => $sensitive_request ? '[redacted]' : $response->get_data() );
	}

	private function call_function( array $input ) {
		$callable = isset( $input['callable'] ) ? trim( (string) $input['callable'] ) : '';
		$args_json = isset( $input['argumentsJson'] ) ? (string) $input['argumentsJson'] : '[]';
		$args      = json_decode( $args_json, true );
		$args      = is_array( $args ) ? array_values( $args ) : null;
		if ( '' === $callable || null === $args || count( $args ) > 30 || ! preg_match( '/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:::[A-Za-z_][A-Za-z0-9_]*)?$/', $callable ) ) {
			return new WP_Error( 'wpcommander_invalid_callable', __( 'A loaded PHP function or static callable and at most 30 arguments are required.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		$blocked = array( 'exec', 'system', 'shell_exec', 'passthru', 'proc_open', 'popen', 'file_get_contents', 'file_put_contents', 'unlink', 'rename', 'getenv', 'constant', 'wp_salt', 'wp_get_session_token', 'wp_generate_auth_cookie', 'wp_die', 'phpinfo', 'ini_get_all' );
		if ( false === strpos( $callable, '::' ) && in_array( strtolower( $callable ), $blocked, true ) ) {
			return new WP_Error( 'wpcommander_callable_uses_dedicated_primitive', __( 'Use the dedicated filesystem or WP-CLI primitive for that operation.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		if ( 0 === stripos( $callable, 'WP_Application_Passwords::' ) ) {
			return new WP_Error( 'wpcommander_credential_callable_blocked', __( 'Application Password management is not available through universal execution.', 'wpcommander' ), array( 'status' => 403 ) );
		}
		if ( ! is_callable( $callable ) ) {
			return new WP_Error( 'wpcommander_callable_not_loaded', __( 'That PHP callable is not loaded in the current WordPress runtime.', 'wpcommander' ), array( 'status' => 404 ) );
		}

		try {
			$return_value = call_user_func_array( $callable, $args );
			if ( is_wp_error( $return_value ) ) {
				return $return_value;
			}
			return array(
				'callable'    => $callable,
				'returnValue' => $this->contains_sensitive_context( $args ) ? '[redacted]' : $return_value,
			);
		} catch ( Throwable $error ) {
			return new WP_Error( 'wpcommander_callable_failed', $error->getMessage(), array( 'status' => 500 ) );
		}
	}

	private function php_eval( array $input ) {
		$code = isset( $input['code'] ) ? (string) $input['code'] : '';
		if ( '' === trim( $code ) || strlen( $code ) > self::MAX_CODE_BYTES ) {
			return new WP_Error( 'wpcommander_invalid_php', __( 'Bounded PHP code is required.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		$tokens = token_get_all( '<?php ' . $code );
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && T_EXIT === $token[0] ) {
				return new WP_Error( 'wpcommander_php_terminator_blocked', __( 'Privileged PHP may not terminate the REST request with exit/die; perform the mutation and return normally.', 'wpcommander' ), array( 'status' => 400 ) );
			}
		}

		$initial_level = ob_get_level();
		$output        = '';
		$value         = null;
		$error         = null;
		try {
			ob_start();
			$value = eval( 'return (static function () {' . "\n" . $code . "\n" . '})();' );
		} catch ( Throwable $caught ) {
			$error = $caught;
		}
		while ( ob_get_level() > $initial_level ) {
			$chunk = ob_get_clean();
			if ( is_string( $chunk ) ) {
				$output = $chunk . $output;
			}
		}
		if ( $error instanceof Throwable ) {
			return new WP_Error( 'wpcommander_php_failed', __( 'Privileged PHP execution failed. Inspect current WordPress state before retrying.', 'wpcommander' ), array( 'status' => 500, 'errorClass' => get_class( $error ) ) );
		}
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		return array(
			'executed'    => true,
			'returnType'  => gettype( $value ),
			'outputBytes' => strlen( $output ),
		);
	}

	private function sql( array $input ) {
		global $wpdb;
		$sql = isset( $input['sql'] ) ? trim( (string) $input['sql'] ) : '';
		if ( '' === $sql || strlen( $sql ) > self::MAX_SQL_BYTES ) {
			return new WP_Error( 'wpcommander_invalid_sql', __( 'Bounded SQL is required.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		if ( $this->sql_has_multiple_statements( $sql ) || preg_match( '/\b(?:GRANT|REVOKE|CREATE\s+USER|ALTER\s+USER|DROP\s+USER|SET\s+PASSWORD|DROP\s+DATABASE|CREATE\s+DATABASE|USE\s+|INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD\s+DATA|LOAD_FILE\s*\(|SLEEP\s*\(|BENCHMARK\s*\(|GET_LOCK\s*\()\b?/i', $sql ) ) {
			return new WP_Error( 'wpcommander_sql_scope_blocked', __( 'Database-account/server administration and multi-statement SQL are outside the WordPress-site execution boundary.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		$sql = rtrim( $sql, "; \t\r\n" );
		$allowed = preg_match( '/^(?:SELECT|DESCRIBE|DESC|EXPLAIN|INSERT|UPDATE|DELETE|REPLACE|CREATE\s+(?:TABLE|(?:UNIQUE\s+)?INDEX|VIEW|TRIGGER)|ALTER\s+TABLE|DROP\s+(?:TABLE|INDEX|VIEW|TRIGGER)|TRUNCATE\s+TABLE|RENAME\s+TABLE|ANALYZE\s+TABLE|OPTIMIZE\s+TABLE|REPAIR\s+TABLE)\b/i', $sql );
		if ( 1 !== $allowed || preg_match( '/\b(?:information_schema|performance_schema|mysql|sys)\s*\./i', $sql ) || $this->sql_targets_external_schema( $sql ) ) {
			return new WP_Error( 'wpcommander_sql_scope_blocked', __( 'Only current-site database reads and table/data mutations are allowed through universal SQL.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		if ( preg_match( '/^SELECT\b/i', $sql ) ) {
			$sql = $this->bound_select_limit( $sql );
		}
		if ( preg_match( '/^(?:SELECT|DESCRIBE|DESC|EXPLAIN)\b/i', $sql ) ) {
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				return new WP_Error( 'wpcommander_sql_failed', $wpdb->last_error, array( 'status' => 500 ) );
			}
			$truncated = is_array( $rows ) && count( $rows ) > 100;
			$rows      = is_array( $rows ) ? array_slice( $rows, 0, 100 ) : array();
			return array(
				'rows'      => $this->contains_sensitive_context( $sql ) ? '[redacted]' : $rows,
				'rowCount'  => count( $rows ),
				'truncated' => $truncated,
			);
		}

		$affected = $wpdb->query( $sql );
		if ( false === $affected ) {
			return new WP_Error( 'wpcommander_sql_failed', $wpdb->last_error ?: __( 'WordPress database query failed.', 'wpcommander' ), array( 'status' => 500 ) );
		}
		return array( 'affectedRows' => (int) $affected, 'insertId' => (int) $wpdb->insert_id );
	}

	private function sql_targets_external_schema( string $sql ): bool {
		$database = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		if ( '' === $database ) {
			return false;
		}
		$patterns = array(
			'/\b(?:FROM|JOIN|UPDATE|INTO|TABLE|TO|VIEW|TRIGGER)\s+\x60?([A-Za-z0-9_$-]+)\x60?\s*\.\s*\x60?[A-Za-z0-9_$-]+\x60?/i',
		);
		if ( preg_match( '/^(?:CREATE\s+(?:UNIQUE\s+)?INDEX|DROP\s+INDEX|CREATE\s+TRIGGER)\b/i', $sql ) ) {
			$patterns[] = '/\bON\s+\x60?([A-Za-z0-9_$-]+)\x60?\s*\.\s*\x60?[A-Za-z0-9_$-]+\x60?/i';
		}
		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $sql, $matches ) ) {
				foreach ( $matches[1] as $schema ) {
					if ( 0 !== strcasecmp( (string) $schema, $database ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	private function bound_select_limit( string $sql ): string {
		if ( ! preg_match( '/\bLIMIT\s+(\d+)(?:\s*,\s*(\d+)|\s+OFFSET\s+(\d+))?/i', $sql, $match ) ) {
			return $sql . ' LIMIT 101';
		}
		$first = (int) $match[1];
		if ( isset( $match[2] ) && '' !== $match[2] ) {
			$replacement = 'LIMIT ' . $first . ', ' . min( (int) $match[2], 101 );
		} elseif ( isset( $match[3] ) && '' !== $match[3] ) {
			$replacement = 'LIMIT ' . min( $first, 101 ) . ' OFFSET ' . (int) $match[3];
		} else {
			$replacement = 'LIMIT ' . min( $first, 101 );
		}
		return preg_replace( '/\bLIMIT\s+\d+(?:\s*,\s*\d+|\s+OFFSET\s+\d+)?/i', $replacement, $sql, 1 ) ?: $sql;
	}

	private function sql_has_multiple_statements( string $sql ): bool {
		$trimmed = rtrim( trim( $sql ), "; \t\r\n" );
		return false !== strpos( $trimmed, ';' );
	}

	private function write_file( array $input ) {
		$resolved = $this->resolve_write_path( $input, true );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$has_text   = array_key_exists( 'content', $input );
		$has_base64 = array_key_exists( 'contentBase64', $input );
		if ( $has_text === $has_base64 ) {
			return new WP_Error( 'wpcommander_invalid_file_content', __( 'Provide exactly one of content or contentBase64 for write-file.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		if ( $has_base64 ) {
			$decoded = base64_decode( (string) $input['contentBase64'], true );
			if ( false === $decoded ) {
				return new WP_Error( 'wpcommander_invalid_base64', __( 'contentBase64 must be valid Base64.', 'wpcommander' ), array( 'status' => 400 ) );
			}
			$content = $decoded;
		} else {
			$content = (string) $input['content'];
		}
		if ( strlen( $content ) > self::MAX_FILE_BYTES ) {
			return new WP_Error( 'wpcommander_file_too_large', __( 'File content exceeds the universal execution limit.', 'wpcommander' ), array( 'status' => 413 ) );
		}
		$expected = isset( $input['expectedSha256'] ) ? strtolower( (string) $input['expectedSha256'] ) : '';
		if ( file_exists( $resolved['absolute'] ) ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) ) {
				return new WP_Error( 'wpcommander_file_fingerprint_required', __( 'A fresh expectedSha256 from source inspection is required before overwriting an existing file.', 'wpcommander' ), array( 'status' => 400 ) );
			}
			$current = hash_file( 'sha256', $resolved['absolute'] );
			if ( ! is_string( $current ) || ! hash_equals( $expected, $current ) ) {
				return new WP_Error( 'wpcommander_file_stale', __( 'The file changed after inspection. Re-read it before writing.', 'wpcommander' ), array( 'status' => 409 ) );
			}
		}
		$parent = dirname( $resolved['absolute'] );
		if ( ! is_dir( $parent ) || ! is_writable( $parent ) ) {
			return new WP_Error( 'wpcommander_path_not_writable', __( 'The target directory is not writable by WordPress.', 'wpcommander' ), array( 'status' => 403 ) );
		}
		$bytes = file_put_contents( $resolved['absolute'], $content, LOCK_EX );
		if ( false === $bytes ) {
			return new WP_Error( 'wpcommander_file_write_failed', __( 'WordPress could not write the requested file.', 'wpcommander' ), array( 'status' => 500 ) );
		}
		clearstatcache( true, $resolved['absolute'] );
		return array( 'root' => $resolved['root'], 'path' => $resolved['relative'], 'bytes' => (int) $bytes, 'sha256' => hash_file( 'sha256', $resolved['absolute'] ) );
	}

	private function make_directory( array $input ) {
		$resolved = $this->resolve_write_path( $input, true );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		if ( is_dir( $resolved['absolute'] ) ) {
			return array( 'root' => $resolved['root'], 'path' => $resolved['relative'], 'created' => false );
		}
		$parent = dirname( $resolved['absolute'] );
		if ( ! is_dir( $parent ) || ! is_writable( $parent ) ) {
			return new WP_Error( 'wpcommander_path_not_writable', __( 'The parent directory is not writable by WordPress.', 'wpcommander' ), array( 'status' => 403 ) );
		}
		if ( ! wp_mkdir_p( $resolved['absolute'] ) ) {
			return new WP_Error( 'wpcommander_directory_create_failed', __( 'WordPress could not create the requested directory.', 'wpcommander' ), array( 'status' => 500 ) );
		}
		return array( 'root' => $resolved['root'], 'path' => $resolved['relative'], 'created' => true );
	}

	private function require_file_fingerprint( string $absolute, array $input ) {
		$expected = isset( $input['expectedSha256'] ) ? strtolower( (string) $input['expectedSha256'] ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) ) {
			return new WP_Error( 'wpcommander_file_fingerprint_required', __( 'A fresh expectedSha256 from stat-path is required before changing an existing file.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		$current = hash_file( 'sha256', $absolute );
		if ( ! is_string( $current ) || ! hash_equals( $expected, $current ) ) {
			return new WP_Error( 'wpcommander_file_stale', __( 'The file changed after inspection. Re-read stat-path before changing it.', 'wpcommander' ), array( 'status' => 409 ) );
		}
		return true;
	}

	private function move_path( array $input ) {
		$source = $this->resolve_write_path( array( 'root' => $input['root'] ?? '', 'path' => $input['path'] ?? '' ), false );
		$target = $this->resolve_write_path( array( 'root' => $input['targetRoot'] ?? ( $input['root'] ?? '' ), 'path' => $input['targetPath'] ?? '' ), true );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( is_file( $source['absolute'] ) ) {
			$fingerprint = $this->require_file_fingerprint( $source['absolute'], $input );
			if ( is_wp_error( $fingerprint ) ) {
				return $fingerprint;
			}
		}
		if ( file_exists( $target['absolute'] ) || ! @rename( $source['absolute'], $target['absolute'] ) ) {
			return new WP_Error( 'wpcommander_move_failed', __( 'WordPress could not move the requested path or the target already exists.', 'wpcommander' ), array( 'status' => 500 ) );
		}
		return array( 'from' => $source['relative'], 'to' => $target['relative'], 'root' => $target['root'] );
	}

	private function delete_path( array $input ) {
		$resolved = $this->resolve_write_path( $input, false );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		if ( '' === $resolved['relative'] ) {
			return new WP_Error( 'wpcommander_root_delete_blocked', __( 'Deleting an execution root is not allowed.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		if ( is_file( $resolved['absolute'] ) ) {
			$fingerprint = $this->require_file_fingerprint( $resolved['absolute'], $input );
			if ( is_wp_error( $fingerprint ) ) {
				return $fingerprint;
			}
		}
		$ok = is_dir( $resolved['absolute'] ) && ! is_link( $resolved['absolute'] )
			? $this->delete_directory_tree( $resolved['absolute'] )
			: @unlink( $resolved['absolute'] );
		if ( ! $ok ) {
			return new WP_Error( 'wpcommander_delete_failed', __( 'WordPress could not delete the requested path.', 'wpcommander' ), array( 'status' => 500 ) );
		}
		return array( 'root' => $resolved['root'], 'path' => $resolved['relative'], 'deleted' => true );
	}

	private function delete_directory_tree( string $directory ): bool {
		$items = @scandir( $directory );
		if ( false === $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $directory . DIRECTORY_SEPARATOR . $item;
			$ok   = is_dir( $path ) && ! is_link( $path ) ? $this->delete_directory_tree( $path ) : @unlink( $path );
			if ( ! $ok ) {
				return false;
			}
		}
		return @rmdir( $directory );
	}

	private function execution_roots(): array {
		$uploads = wp_get_upload_dir();
		return array_filter(
			array(
				'wordpress'  => ABSPATH,
				'content'    => WP_CONTENT_DIR,
				'plugins'    => WP_PLUGIN_DIR,
				'themes'     => get_theme_root(),
				'mu-plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : null,
				'uploads'    => empty( $uploads['basedir'] ) ? null : $uploads['basedir'],
			)
		);
	}

	private function resolve_write_path( array $input, bool $allow_missing ) {
		$root_name = isset( $input['root'] ) ? sanitize_key( (string) $input['root'] ) : '';
		$relative  = isset( $input['path'] ) ? ltrim( str_replace( '\\', '/', (string) $input['path'] ), '/' ) : '';
		$roots     = $this->execution_roots();
		if ( ! isset( $roots[ $root_name ] ) || '' === $relative || false !== strpos( $relative, "\0" ) ) {
			return new WP_Error( 'wpcommander_invalid_execution_path', __( 'A valid execution root and relative path are required.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		if ( preg_match( '#(?:^|/)\.\.(?:/|$)#', $relative ) ) {
			return new WP_Error( 'wpcommander_execution_path_escape', __( 'Execution paths cannot traverse outside their selected root.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		$base = realpath( $roots[ $root_name ] );
		if ( false === $base ) {
			return new WP_Error( 'wpcommander_execution_root_missing', __( 'The selected execution root does not exist.', 'wpcommander' ), array( 'status' => 404 ) );
		}
		$candidate = wp_normalize_path( trailingslashit( $base ) . $relative );
		$base_norm = untrailingslashit( wp_normalize_path( $base ) );
		if ( $candidate !== $base_norm && 0 !== strpos( $candidate, trailingslashit( $base_norm ) ) ) {
			return new WP_Error( 'wpcommander_execution_path_escape', __( 'Execution path escaped the selected WordPress root.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		if ( file_exists( $candidate ) || is_link( $candidate ) ) {
			if ( is_link( $candidate ) ) {
				return new WP_Error( 'wpcommander_symlink_mutation_blocked', __( 'Mutating symlink paths is ambiguous; target the resolved WordPress path explicitly.', 'wpcommander' ), array( 'status' => 400 ) );
			}
			$real = realpath( $candidate );
			if ( false === $real || ! $this->path_inside( $real, $base ) ) {
				return new WP_Error( 'wpcommander_execution_path_escape', __( 'Symlink/path resolution escaped the selected WordPress root.', 'wpcommander' ), array( 'status' => 400 ) );
			}
			return array( 'root' => $root_name, 'relative' => $relative, 'absolute' => $real, 'base' => $base );
		}
		if ( ! $allow_missing ) {
			return new WP_Error( 'wpcommander_execution_path_missing', __( 'The requested WordPress path does not exist.', 'wpcommander' ), array( 'status' => 404 ) );
		}

		$ancestor = dirname( $candidate );
		while ( ! file_exists( $ancestor ) && dirname( $ancestor ) !== $ancestor ) {
			$ancestor = dirname( $ancestor );
		}
		$ancestor_real = realpath( $ancestor );
		if ( false === $ancestor_real || ! $this->path_inside( $ancestor_real, $base ) ) {
			return new WP_Error( 'wpcommander_execution_path_escape', __( 'The target path resolves outside the selected WordPress root.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		return array( 'root' => $root_name, 'relative' => $relative, 'absolute' => $candidate, 'base' => $base );
	}

	private function path_inside( string $path, string $base ): bool {
		$path = wp_normalize_path( $path );
		$base = untrailingslashit( wp_normalize_path( $base ) );
		return $path === $base || 0 === strpos( $path, trailingslashit( $base ) );
	}

	private function wp_cli( array $input ) {
		$args = isset( $input['arguments'] ) && is_array( $input['arguments'] ) ? array_values( $input['arguments'] ) : array();
		if ( empty( $args ) || count( $args ) > 40 ) {
			return new WP_Error( 'wpcommander_invalid_wp_cli', __( 'WP-CLI requires between 1 and 40 arguments.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		foreach ( $args as $arg ) {
			if ( ! is_scalar( $arg ) || strlen( (string) $arg ) > 1000 || false !== strpos( (string) $arg, "\0" ) ) {
				return new WP_Error( 'wpcommander_invalid_wp_cli_argument', __( 'WP-CLI arguments must be bounded scalar values.', 'wpcommander' ), array( 'status' => 400 ) );
			}
		}
		$group = strtolower( (string) $args[0] );
		$sub   = isset( $args[1] ) ? strtolower( (string) $args[1] ) : '';
		if ( in_array( $group, array( 'eval', 'eval-file', 'shell' ), true ) || ( 'config' === $group && in_array( $sub, array( 'get', 'list' ), true ) ) || ( 'user' === $group && 'application-password' === $sub ) || ( 'db' === $group && in_array( $sub, array( 'cli', 'query' ), true ) ) ) {
			return new WP_Error( 'wpcommander_wp_cli_uses_dedicated_primitive', __( 'Use the dedicated PHP, SQL, or non-secret inspection primitive for that WP-CLI operation.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		$binary = (string) apply_filters( 'wpcommander_wp_cli_binary', 'wp' );
		$command = escapeshellarg( $binary ) . ' --path=' . escapeshellarg( untrailingslashit( ABSPATH ) ) . ' --no-color';
		foreach ( $args as $arg ) {
			$command .= ' ' . escapeshellarg( (string) $arg );
		}
		return $this->run_process( $command, $this->contains_sensitive_context( $args ) );
	}

	private function run_process( string $command, bool $suppress_output = false ) {
		if ( ! function_exists( 'proc_open' ) ) {
			return new WP_Error( 'wpcommander_process_unavailable', __( 'This PHP environment does not permit process execution.', 'wpcommander' ), array( 'status' => 501 ) );
		}
		$descriptor = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process = @proc_open( $command, $descriptor, $pipes, ABSPATH );
		if ( ! is_resource( $process ) ) {
			return new WP_Error( 'wpcommander_process_failed', __( 'WPCommander could not start WP-CLI.', 'wpcommander' ), array( 'status' => 500 ) );
		}
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$stdout = '';
		$stderr = '';
		$started            = microtime( true );
		$timed_out          = false;
		$observed_exit_code = null;
		do {
			$stdout = $this->append_process_output( $stdout, (string) stream_get_contents( $pipes[1] ) );
			$stderr = $this->append_process_output( $stderr, (string) stream_get_contents( $pipes[2] ) );
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				$observed_exit_code = isset( $status['exitcode'] ) ? (int) $status['exitcode'] : null;
				break;
			}
			if ( microtime( true ) - $started > self::MAX_CLI_SECONDS ) {
				$timed_out = true;
				proc_terminate( $process );
				break;
			}
			usleep( 20000 );
		} while ( true );
		$stdout = $this->append_process_output( $stdout, (string) stream_get_contents( $pipes[1] ) );
		$stderr = $this->append_process_output( $stderr, (string) stream_get_contents( $pipes[2] ) );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );
		if ( -1 === $exit_code && null !== $observed_exit_code && $observed_exit_code >= 0 ) {
			$exit_code = $observed_exit_code;
		}
		if ( $suppress_output ) {
			$stdout = '[redacted]';
			$stderr = '[redacted]';
		}
		if ( $timed_out ) {
			return new WP_Error( 'wpcommander_wp_cli_timeout', __( 'WP-CLI exceeded the execution time limit.', 'wpcommander' ), array( 'status' => 504, 'stdout' => $this->bound_text( $stdout ), 'stderr' => $this->bound_text( $stderr ) ) );
		}
		if ( 0 !== (int) $exit_code ) {
			return new WP_Error( 'wpcommander_wp_cli_failed', __( 'WP-CLI returned a non-zero exit code.', 'wpcommander' ), array( 'status' => 500, 'exitCode' => (int) $exit_code, 'stdout' => $this->bound_text( $stdout ), 'stderr' => $this->bound_text( $stderr ) ) );
		}
		return array( 'exitCode' => 0, 'stdout' => $this->bound_text( $stdout ), 'stderr' => $this->bound_text( $stderr ) );
	}

	private function append_process_output( string $buffer, string $chunk ): string {
		$limit = self::MAX_OUTPUT_BYTES + 1;
		if ( strlen( $buffer ) >= $limit || '' === $chunk ) {
			return $buffer;
		}
		$remaining = $limit - strlen( $buffer );
		return $buffer . substr( $chunk, 0, $remaining );
	}

	private function record_activity( string $operation, array $input, string $state, float $duration ): array {
		$entry = array(
			'id'          => wp_generate_uuid4(),
			'actorId'     => get_current_user_id(),
			'operation'   => $operation,
			'target'      => $this->bound_text( $this->activity_target( $operation, $input ) ),
			'summary'     => isset( $input['summary'] ) ? $this->bound_text( sanitize_text_field( (string) $input['summary'] ) ) : '',
			'state'       => $state,
			'timestamp'   => gmdate( 'c' ),
			'durationMs'  => (int) round( $duration * 1000 ),
		);
		$items = get_option( self::OPTION_ACTIVITY, array() );
		$items = is_array( $items ) ? $items : array();
		array_unshift( $items, $entry );
		update_option( self::OPTION_ACTIVITY, array_slice( $items, 0, self::MAX_ACTIVITY ), false );
		return $entry;
	}

	public function get_public_activity( int $limit = 20 ): array {
		$items = get_option( self::OPTION_ACTIVITY, array() );
		$items = is_array( $items ) ? $items : array();
		$output = array();
		foreach ( array_slice( $items, 0, max( 1, min( $limit, 50 ) ) ) as $entry ) {
			$output[] = array(
				'id'        => $entry['id'],
				'action'    => $entry['operation'],
				'target'    => $entry['target'],
				'state'     => $entry['state'],
				'timestamp' => $entry['timestamp'],
			);
		}
		return $output;
	}

	private function activity_target( string $operation, array $input ): string {
		switch ( $operation ) {
			case 'internal-rest':
				return strtoupper( (string) ( $input['method'] ?? 'GET' ) ) . ' ' . (string) ( $input['route'] ?? '' );
			case 'call-function':
				return (string) ( $input['callable'] ?? '' );
			case 'php-eval':
				return 'php-eval';
			case 'sql':
				$sql = trim( (string) ( $input['sql'] ?? '' ) );
				preg_match( '/^([A-Za-z]+)/', $sql, $match );
				return strtoupper( (string) ( $match[1] ?? 'SQL' ) );
			case 'write-file':
			case 'make-directory':
			case 'delete-path':
				return (string) ( $input['root'] ?? '' ) . ':' . (string) ( $input['path'] ?? '' );
			case 'move-path':
				return (string) ( $input['root'] ?? '' ) . ':' . (string) ( $input['path'] ?? '' ) . ' -> ' . (string) ( $input['targetRoot'] ?? ( $input['root'] ?? '' ) ) . ':' . (string) ( $input['targetPath'] ?? '' );
			case 'wp-cli':
				$args = isset( $input['arguments'] ) && is_array( $input['arguments'] ) ? array_slice( $input['arguments'], 0, 2 ) : array();
				return 'wp ' . implode( ' ', array_map( 'strval', $args ) );
		}
		return $operation;
	}

	private function sanitize_error( WP_Error $error ): WP_Error {
		$code    = (string) $error->get_error_code();
		$message = (string) $error->get_error_message();
		$data    = $error->get_error_data();
		if ( $this->contains_sensitive_context( $message ) ) {
			$message = __( 'Privileged operation failed with sensitive detail redacted.', 'wpcommander' );
		} else {
			$message = $this->bound_text( $message );
		}
		$data = $this->redact_output( $data, '', 0 );
		return new WP_Error( '' !== $code ? $code : 'wpcommander_execution_failed', $message, $data );
	}

	private function contains_sensitive_context( $value, string $key = '', int $depth = 0 ): bool {
		if ( $this->is_sensitive_key( $key ) || $depth > 8 ) {
			return $this->is_sensitive_key( $key );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item_key => $item ) {
				if ( $this->contains_sensitive_context( $item, (string) $item_key, $depth + 1 ) ) {
					return true;
				}
			}
			return false;
		}
		if ( is_object( $value ) ) {
			return $this->contains_sensitive_context( get_object_vars( $value ), $key, $depth + 1 );
		}
		return is_string( $value ) && $this->is_sensitive_key( $value );
	}

	private function is_key_label_column( string $column ): bool {
		return (bool) preg_match( '/(?:^|_)(?:key|name)$/i', $column )
			|| (bool) preg_match( '/^(?:option|setting|parameter)$/i', $column );
	}

	private function bound_output( $value ) {
		$value = $this->redact_output( $value, '', 0 );
		$encoded = wp_json_encode( $value );
		if ( false === $encoded || strlen( $encoded ) <= self::MAX_OUTPUT_BYTES ) {
			return $value;
		}
		return array(
			'truncated' => true,
			'preview'   => $this->bound_text( substr( $encoded, 0, self::MAX_OUTPUT_BYTES ) ),
		);
	}

	private function redact_output( $value, string $key, int $depth ) {
		if ( $this->is_sensitive_key( $key ) ) {
			return '[redacted]';
		}
		if ( $depth >= 7 ) {
			return '[depth-truncated]';
		}
		if ( is_array( $value ) ) {
			$sensitive_row = false;
			foreach ( $value as $item_key => $item ) {
				if ( is_string( $item ) && $this->is_key_label_column( (string) $item_key ) && ( $this->is_sensitive_key( $item ) || 0 === strpos( $item, 'wpcommander_' ) || 0 === strpos( $item, '_wpcommander_' ) ) ) {
					$sensitive_row = true;
					break;
				}
			}
			$output = array();
			$count  = 0;
			foreach ( $value as $item_key => $item ) {
				if ( $count++ >= 100 ) {
					$output['_truncated'] = true;
					break;
				}
				$output[ $item_key ] = $sensitive_row && ! $this->is_key_label_column( (string) $item_key )
					? '[redacted]'
					: $this->redact_output( $item, (string) $item_key, $depth + 1 );
			}
			return $output;
		}
		if ( is_object( $value ) ) {
			return $this->redact_output( get_object_vars( $value ), $key, $depth + 1 );
		}
		return is_string( $value ) ? $this->bound_text( $value ) : $value;
	}

	private function bound_text( string $text ): string {
		$text = wp_check_invalid_utf8( $text, true );
		$keys = 'password|passwd|secret|token|authorization|credential|session|api[_-]?key|client[_-]?secret|private[_-]?key|access[_-]?key|license[_-]?key';
		$patterns = array(
			'/((?:' . $keys . ')[^=:\n]{0,30}(?:=>|=|:)[[:space:]]*["\'`])([^"\'`]+)(["\'`])/i',
			'/((?:Bearer|Basic)[[:space:]]+)[A-Za-z0-9._~+\/=:-]{8,}/i',
			'/((?:' . $keys . ')[[:space:]]*=)[^&[:space:]"\'`]+/i',
		);
		$result = preg_replace( $patterns, array( '$1[redacted]$3', '$1[redacted]', '$1[redacted]' ), $text );
		$text   = is_string( $result ) ? $result : '[redacted]';
		if ( strlen( $text ) <= self::MAX_OUTPUT_BYTES ) {
			return $text;
		}
		return substr( $text, 0, self::MAX_OUTPUT_BYTES ) . '…';
	}

	private function is_sensitive_key( string $key ): bool {
		return '' !== $key && (bool) preg_match( '/(?:pass(?:word)?|secret|token|api[-_]?key|credential|private[-_]?key|client[-_]?secret|access[-_]?key|license[-_]?key|session|authorization)/i', $key );
	}
}
