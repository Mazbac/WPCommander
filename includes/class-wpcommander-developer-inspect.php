<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPCommander_Developer_Inspect {
	private const MAX_FILE_BYTES = 524288;
	private const MAX_FILE_LINES = 300;
	private const MAX_LINE_BYTES = 2000;
	private const MAX_SEARCH_FILES = 2500;
	private const MAX_SEARCH_MATCHES = 50;
	private const MAX_DB_ROWS = 50;
	private const MAX_DB_SAMPLE_BYTES = 65536;

	public function can_inspect(): bool {
		return current_user_can( 'manage_options' );
	}

	public function execute( array $input ) {
		$operation = isset( $input['operation'] ) ? sanitize_key( (string) $input['operation'] ) : '';

		switch ( $operation ) {
			case 'inventory':
				return $this->inventory();
			case 'stat-path':
				return $this->stat_path( $input );
			case 'list-files':
				return $this->list_files( $input );
			case 'read-file':
				return $this->read_file( $input );
			case 'search-files':
				return $this->search_files( $input );
			case 'list-routes':
				return $this->list_routes( $input );
			case 'list-tables':
				return $this->list_tables( $input );
			case 'describe-table':
				return $this->describe_table( $input );
			case 'sample-table':
				return $this->sample_table( $input );
			default:
				return new WP_Error(
					'wpcommander_unknown_developer_operation',
					__( 'Unsupported developer inspection operation.', 'wpcommander' ),
					array( 'status' => 400 )
				);
		}
	}

	private function inventory(): array {
		global $wpdb;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$routes = rest_get_server()->get_routes();
		$tables = array_values(
			array_filter(
				$wpdb->get_col( 'SHOW TABLES' ),
				static function ( $table ) use ( $wpdb ): bool {
					return 0 === strpos( (string) $table, $wpdb->base_prefix );
				}
			)
		);
		return array(
			'mode'               => 'read-only',
			'plugins'            => count( get_plugins() ),
			'activePlugins'      => count( (array) get_option( 'active_plugins', array() ) ),
			'themes'             => count( wp_get_themes() ),
			'activeTheme'        => get_stylesheet(),
			'postTypes'          => array_values( get_post_types( array( 'show_ui' => true ), 'names' ) ),
			'taxonomies'         => array_values( get_taxonomies( array( 'show_ui' => true ), 'names' ) ),
			'restRouteCount'     => count( $routes ),
			'databaseTableCount' => count( $tables ),
			'fileRoots'          => array_keys( $this->file_roots() ),
			'wpCliAvailable'     => defined( 'WP_CLI' ) && WP_CLI,
			'phpVersion'         => PHP_VERSION,
			'wordpressVersion'   => get_bloginfo( 'version' ),
			'environment'        => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
		);
	}

	private function stat_path( array $input ) {
		$resolved = $this->resolve_stat_path( $input );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$is_file = is_file( $resolved['absolute'] );
		$size    = $is_file ? filesize( $resolved['absolute'] ) : null;
		$mtime   = filemtime( $resolved['absolute'] );
		$sha256  = $is_file ? hash_file( 'sha256', $resolved['absolute'] ) : null;

		return array(
			'root'       => $resolved['root'],
			'path'       => $resolved['relative'],
			'type'       => $is_file ? 'file' : 'directory',
			'size'       => false === $size ? null : $size,
			'modifiedAt' => false === $mtime ? null : gmdate( 'c', $mtime ),
			'sha256'     => is_string( $sha256 ) ? $sha256 : null,
		);
	}

	private function list_files( array $input ) {
		$resolved = $this->resolve_path( $input, true );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$limit = min( max( (int) ( $input['limit'] ?? 100 ), 1 ), 250 );
		$items = array();
		foreach ( new DirectoryIterator( $resolved['absolute'] ) as $entry ) {
			if ( $entry->isDot() || $this->is_restricted_name( $entry->getFilename() ) ) {
				continue;
			}

			$items[] = array(
				'name'       => $entry->getFilename(),
				'path'       => $this->join_relative( $resolved['relative'], $entry->getFilename() ),
				'type'       => $entry->isDir() ? 'directory' : 'file',
				'size'       => $entry->isFile() ? $entry->getSize() : null,
				'modifiedAt' => gmdate( 'c', $entry->getMTime() ),
			);

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return array(
			'root'      => $resolved['root'],
			'path'      => $resolved['relative'],
			'items'     => $items,
			'truncated' => count( $items ) >= $limit,
		);
	}

	private function read_file( array $input ) {
		$resolved = $this->resolve_path( $input, false );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$file_size = filesize( $resolved['absolute'] );
		if ( ! is_file( $resolved['absolute'] ) || false === $file_size || ! $this->is_text_file( $resolved['absolute'] ) || $file_size > self::MAX_FILE_BYTES ) {
			return new WP_Error(
				'wpcommander_file_unreadable',
				__( 'Only bounded text/source files may be inspected.', 'wpcommander' ),
				array( 'status' => 400 )
			);
		}

		$start = max( (int) ( $input['startLine'] ?? 1 ), 1 );
		$max   = min( max( (int) ( $input['maxLines'] ?? 120 ), 1 ), self::MAX_FILE_LINES );
		$lines = file( $resolved['absolute'], FILE_IGNORE_NEW_LINES );
		if ( false === $lines ) {
			return new WP_Error( 'wpcommander_file_unreadable', __( 'The source file could not be read.', 'wpcommander' ), array( 'status' => 500 ) );
		}

		$output = array();
		foreach ( array_slice( $lines, $start - 1, $max ) as $index => $line ) {
			$output[] = array(
				'line' => $start + $index,
				'text' => $this->redact_source_line( (string) $line ),
			);
		}

		return array(
			'root'       => $resolved['root'],
			'path'       => $resolved['relative'],
			'sha256'     => hash_file( 'sha256', $resolved['absolute'] ),
			'lines'      => $output,
			'totalLines' => count( $lines ),
			'truncated'  => $start - 1 + count( $output ) < count( $lines ),
		);
	}

	private function search_files( array $input ) {
		$query = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';
		if ( '' === $query ) {
			return new WP_Error( 'wpcommander_missing_query', __( 'A source search query is required.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$resolved = $this->resolve_path( $input, true );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$matches   = array();
		$scanned   = 0;
		$directory = new RecursiveDirectoryIterator( $resolved['absolute'], FilesystemIterator::SKIP_DOTS );
		$filtered  = new RecursiveCallbackFilterIterator(
			$directory,
			function ( $entry ): bool {
				return ! $this->is_restricted_name( $entry->getFilename() );
			}
		);
		$iterator = new RecursiveIteratorIterator( $filtered );
		foreach ( $iterator as $entry ) {
			if ( $scanned >= self::MAX_SEARCH_FILES || count( $matches ) >= self::MAX_SEARCH_MATCHES ) {
				break;
			}

			$real_path = realpath( $entry->getPathname() );
			if ( false === $real_path || ! $this->is_path_inside_base( $real_path, $resolved['base'] ) ) {
				continue;
			}

			$file_size = filesize( $real_path );
			if ( ! $entry->isFile() || false === $file_size || $this->is_restricted_name( $entry->getFilename() ) || ! $this->is_text_file( $real_path ) || $file_size > self::MAX_FILE_BYTES ) {
				continue;
			}

			++$scanned;
			$lines = @file( $real_path, FILE_IGNORE_NEW_LINES );
			if ( false === $lines ) {
				continue;
			}

			foreach ( $lines as $line_number => $line ) {
				if ( false === stripos( (string) $line, $query ) ) {
					continue;
				}
				$matches[] = array(
					'path' => $this->relative_from_base( $resolved['base'], $entry->getPathname() ),
					'line' => $line_number + 1,
					'text' => $this->redact_source_line( trim( (string) $line ) ),
				);
				if ( count( $matches ) >= self::MAX_SEARCH_MATCHES ) {
					break 2;
				}
			}
		}

		return array(
			'root'      => $resolved['root'],
			'path'      => $resolved['relative'],
			'query'     => $query,
			'matches'   => $matches,
			'scanned'   => $scanned,
			'truncated' => $scanned >= self::MAX_SEARCH_FILES || count( $matches ) >= self::MAX_SEARCH_MATCHES,
		);
	}

	private function list_routes( array $input ): array {
		$query = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';
		$limit = min( max( (int) ( $input['limit'] ?? 100 ), 1 ), 250 );
		$items = array();

		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( '' !== $query && false === stripos( (string) $route, $query ) ) {
				continue;
			}

			$methods = array();
			foreach ( (array) $handlers as $handler ) {
				if ( ! empty( $handler['methods'] ) && is_array( $handler['methods'] ) ) {
					$methods = array_merge( $methods, array_keys( array_filter( $handler['methods'] ) ) );
				}
			}
			$items[] = array(
				'route'   => (string) $route,
				'methods' => array_values( array_unique( $methods ) ),
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return array(
			'routes'    => $items,
			'truncated' => count( $items ) >= $limit,
		);
	}

	private function list_tables( array $input ): array {
		global $wpdb;

		$query  = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';
		$limit  = min( max( (int) ( $input['limit'] ?? 100 ), 1 ), 250 );
		$tables = array_values(
			array_filter(
				$wpdb->get_col( 'SHOW TABLES' ),
				static function ( $table ) use ( $wpdb ): bool {
					return 0 === strpos( (string) $table, $wpdb->base_prefix );
				}
			)
		);
		$items = array();

		foreach ( $tables as $table ) {
			$table = (string) $table;
			if ( '' !== $query && false === stripos( $table, $query ) ) {
				continue;
			}
			$items[] = array(
				'table'      => $table,
				'wordpress'  => 0 === strpos( $table, $wpdb->prefix ),
				'basePrefix' => 0 === strpos( $table, $wpdb->base_prefix ),
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return array(
			'tables'    => $items,
			'total'     => count( $tables ),
			'truncated' => count( $items ) >= $limit,
		);
	}

	private function describe_table( array $input ) {
		global $wpdb;
		$table = isset( $input['table'] ) ? (string) $input['table'] : '';
		if ( ! $this->table_exists( $table ) ) {
			return new WP_Error(
				'wpcommander_table_not_found',
				__( 'Database table was not found.', 'wpcommander' ),
				array( 'status' => 404 )
			);
		}

		$rows    = $wpdb->get_results( 'DESCRIBE `' . esc_sql( $table ) . '`', ARRAY_A );
		$columns = array();
		foreach ( $rows as $row ) {
			$name      = (string) ( $row['Field'] ?? '' );
			$columns[] = array(
				'name'      => $name,
				'type'      => (string) ( $row['Type'] ?? '' ),
				'nullable'  => 'YES' === ( $row['Null'] ?? '' ),
				'key'       => (string) ( $row['Key'] ?? '' ),
				'extra'     => (string) ( $row['Extra'] ?? '' ),
				'sensitive' => $this->is_sensitive_key( $name ),
			);
		}

		return array(
			'table'   => $table,
			'columns' => $columns,
		);
	}

	private function sample_table( array $input ) {
		global $wpdb;
		$table = isset( $input['table'] ) ? (string) $input['table'] : '';
		if ( ! $this->table_exists( $table ) ) {
			return new WP_Error(
				'wpcommander_table_not_found',
				__( 'Database table was not found.', 'wpcommander' ),
				array( 'status' => 404 )
			);
		}

		$limit = min( max( (int) ( $input['limit'] ?? 20 ), 1 ), self::MAX_DB_ROWS );
		$rows  = $wpdb->get_results( 'SELECT * FROM `' . esc_sql( $table ) . '` LIMIT ' . $limit, ARRAY_A );
		$rows  = is_array( $rows ) ? $rows : array();

		$sample = array();
		$bytes  = 0;
		foreach ( $rows as $row ) {
			$row     = $this->redact_database_row( $row );
			$encoded = wp_json_encode( $row );
			$row_size = false === $encoded ? self::MAX_DB_SAMPLE_BYTES + 1 : strlen( $encoded );
			if ( $bytes + $row_size > self::MAX_DB_SAMPLE_BYTES ) {
				break;
			}
			$sample[] = $row;
			$bytes   += $row_size;
		}

		return array(
			'table'     => $table,
			'rows'      => $sample,
			'rowCount'  => count( $sample ),
			'truncated' => count( $sample ) < count( $rows ) || count( $rows ) >= $limit,
		);
	}

	private function redact_database_row( array $row ): array {
		$sensitive_row = false;
		foreach ( $row as $column => $value ) {
			if ( is_string( $value ) && $this->is_key_label_column( (string) $column ) && ( $this->is_sensitive_key( $value ) || 0 === strpos( $value, 'wpcommander_' ) || 0 === strpos( $value, '_wpcommander_' ) ) ) {
				$sensitive_row = true;
				break;
			}
		}

		foreach ( $row as $column => &$value ) {
			if ( $this->is_sensitive_key( (string) $column ) || ( $sensitive_row && ! $this->is_key_label_column( (string) $column ) ) ) {
				$value = '[redacted]';
			} elseif ( is_string( $value ) ) {
				$value = wp_check_invalid_utf8( $value, true );
				$value = $this->redact_sensitive_text( $value );
				if ( strlen( $value ) > 4000 ) {
					$value = substr( $value, 0, 4000 ) . '…';
				}
			}
		}
		unset( $value );

		return $row;
	}

	private function is_key_label_column( string $column ): bool {
		return (bool) preg_match( '/(?:^|_)(?:key|name)$/i', $column )
			|| (bool) preg_match( '/^(?:option|setting|parameter)$/i', $column );
	}

	private function stat_roots(): array {
		$uploads = wp_get_upload_dir();
		return array_filter(
			array(
				'plugins'    => WP_PLUGIN_DIR,
				'themes'     => get_theme_root(),
				'mu-plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : null,
				'wordpress'  => ABSPATH,
				'content'    => WP_CONTENT_DIR,
				'uploads'    => empty( $uploads['basedir'] ) ? null : $uploads['basedir'],
			)
		);
	}

	private function resolve_stat_path( array $input ) {
		$root_name = isset( $input['root'] ) ? sanitize_key( (string) $input['root'] ) : '';
		$relative  = isset( $input['path'] ) ? ltrim( str_replace( '\\', '/', (string) $input['path'] ), '/' ) : '';
		$roots     = $this->stat_roots();
		if ( ! isset( $roots[ $root_name ] ) || '' === $relative || false !== strpos( $relative, "\0" ) || preg_match( '#(?:^|/)\.\.(?:/|$)#', $relative ) ) {
			return new WP_Error( 'wpcommander_invalid_stat_path', __( 'A valid WordPress root and relative path are required.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$base     = realpath( $roots[ $root_name ] );
		$absolute = realpath( trailingslashit( $roots[ $root_name ] ) . $relative );
		if ( false === $base || false === $absolute || ! $this->is_path_inside_base( $absolute, $base ) ) {
			return new WP_Error( 'wpcommander_stat_path_not_found', __( 'Path was not found inside the selected WordPress root.', 'wpcommander' ), array( 'status' => 404 ) );
		}

		return array(
			'root'     => $root_name,
			'base'     => $base,
			'absolute' => $absolute,
			'relative' => $this->relative_from_base( $base, $absolute ),
		);
	}

	private function file_roots(): array {
		return array_filter(
			array(
				'plugins'    => WP_PLUGIN_DIR,
				'themes'     => get_theme_root(),
				'mu-plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : null,
				'wordpress'  => ABSPATH,
			)
		);
	}

	private function resolve_path( array $input, bool $directory ) {
		$root_name = isset( $input['root'] ) ? sanitize_key( (string) $input['root'] ) : '';
		$relative  = isset( $input['path'] ) ? ltrim( str_replace( '\\', '/', (string) $input['path'] ), '/' ) : '';
		$roots     = $this->file_roots();

		if ( ! isset( $roots[ $root_name ] ) ) {
			return new WP_Error( 'wpcommander_unknown_file_root', __( 'Unknown source root.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		if ( preg_match( '#(?:^|/)\.\.(?:/|$)#', $relative ) || $this->is_restricted_name( wp_basename( $relative ) ) ) {
			return new WP_Error( 'wpcommander_restricted_path', __( 'That path is not available for developer inspection.', 'wpcommander' ), array( 'status' => 400 ) );
		}
		if ( 'wordpress' === $root_name && preg_match( '#(?:^|/)wp-content(?:/|$)#i', $relative ) ) {
			return new WP_Error( 'wpcommander_restricted_path', __( 'Use the dedicated plugin, theme, or mu-plugin roots instead of traversing wp-content.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$base     = realpath( $roots[ $root_name ] );
		$absolute = realpath( trailingslashit( $roots[ $root_name ] ) . $relative );
		if ( false === $base || false === $absolute || ! $this->is_path_inside_base( $absolute, $base ) ) {
			return new WP_Error( 'wpcommander_path_not_found', __( 'Source path was not found inside the selected root.', 'wpcommander' ), array( 'status' => 404 ) );
		}

		if ( $directory && ! is_dir( $absolute ) ) {
			return new WP_Error( 'wpcommander_directory_required', __( 'A directory path is required for this operation.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		return array(
			'root'     => $root_name,
			'base'     => $base,
			'absolute' => $absolute,
			'relative' => $this->relative_from_base( $base, $absolute ),
		);
	}

	private function is_path_inside_base( string $path, string $base ): bool {
		$path = wp_normalize_path( $path );
		$base = wp_normalize_path( $base );
		return $path === $base || 0 === strpos( $path, trailingslashit( $base ) );
	}

	private function relative_from_base( string $base, string $path ): string {
		$base = trailingslashit( wp_normalize_path( $base ) );
		$path = wp_normalize_path( $path );
		return ltrim( substr( $path, strlen( $base ) ), '/' );
	}

	private function join_relative( string $directory, string $name ): string {
		return '' === $directory ? $name : trailingslashit( $directory ) . $name;
	}

	private function is_text_file( string $path ): bool {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array(
			$extension,
			array( 'php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'sass', 'less', 'json', 'html', 'htm', 'xml', 'md', 'txt', 'yml', 'yaml', 'twig' ),
			true
		);
	}

	private function is_restricted_name( string $name ): bool {
		$lower = strtolower( $name );
		if ( in_array( $lower, array( 'wp-config.php', 'wp-content', 'auth.json', '.htpasswd', '.git', '.svn', '.hg' ), true ) || 0 === strpos( $lower, '.env' ) ) {
			return true;
		}
		return (bool) preg_match( '/\.(?:pem|key|p12|pfx|jks|sql|sqlite|db)(?:\..*)?$/i', $lower );
	}

	private function redact_source_line( string $line ): string {
		$line = $this->redact_sensitive_text( $line );
		if ( strlen( $line ) <= self::MAX_LINE_BYTES ) {
			return $line;
		}

		$excerpt = function_exists( 'mb_strcut' )
			? mb_strcut( $line, 0, self::MAX_LINE_BYTES, 'UTF-8' )
			: substr( $line, 0, self::MAX_LINE_BYTES );
		return $excerpt . '…';
	}

	private function redact_sensitive_text( string $text ): string {
		$keys = 'password|passwd|secret|token|authorization|credential|session|api[_-]?key|client[_-]?secret|private[_-]?key|access[_-]?key|license[_-]?key';
		$patterns = array(
			'/((?:' . $keys . ')[^=:\n]{0,30}(?:=>|=|:)[[:space:]]*["\'`])([^"\'`]+)(["\'`])/i',
			'/((?:' . $keys . ')[^,\n]{0,40},[[:space:]]*["\'`])([^"\'`]+)(["\'`])/i',
			'/((?:Bearer|Basic)[[:space:]]+)[A-Za-z0-9._~+\/=:-]{8,}/i',
			'/((?:' . $keys . ')[[:space:]]*=)[^&[:space:]"\'`]+/i',
			'/(s:\d+:"(?:' . $keys . ')";s:\d+:")[^"]*(")/i',
		);
		$replacements = array(
			'$1[redacted]$3',
			'$1[redacted]$3',
			'$1[redacted]',
			'$1[redacted]',
			'$1[redacted]$2',
		);
		$result = preg_replace( $patterns, $replacements, $text );
		return is_string( $result ) ? $result : '[redacted]';
	}

	private function is_sensitive_key( string $key ): bool {
		return (bool) preg_match(
			'/(?:pass(?:word)?|secret|token|api[-_]?key|credential|private[-_]?key|client[-_]?secret|access[-_]?key|license[-_]?key|session)/i',
			$key
		);
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		if ( '' === $table || 0 !== strpos( $table, $wpdb->base_prefix ) || ! preg_match( '/^[A-Za-z0-9_$-]+$/', $table ) ) {
			return false;
		}

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return $table === $found;
	}
}
