<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPCommander_Resources {
	private const DEFAULT_LIMIT = 10;
	private const MAX_LIMIT = 20;
	private const MAX_PREVIEW_BYTES = 24000;
	private const MAX_SEARCH_NODES = 10000;

	public function can_search( array $input ): bool {
		$kind = isset( $input['kind'] ) ? (string) $input['kind'] : 'post';

		if ( in_array( $kind, array( 'option', 'site' ), true ) ) {
			return current_user_can( 'manage_options' );
		}

		if ( 'menu' === $kind ) {
			return current_user_can( 'edit_theme_options' );
		}

		if ( 'plugin' === $kind ) {
			return current_user_can( 'activate_plugins' );
		}

		if ( 'theme' === $kind ) {
			return current_user_can( 'switch_themes' ) || current_user_can( 'edit_theme_options' );
		}

		if ( 'user' === $kind ) {
			return current_user_can( 'list_users' );
		}

		if ( 'comment' === $kind ) {
			return current_user_can( 'moderate_comments' );
		}

		if ( 'term' === $kind ) {
			return $this->can_search_terms( $input );
		}

		if ( 'post-meta' === $kind ) {
			$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
			return $id > 0 ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_posts' );
		}

		if ( 'media' === $kind ) {
			return current_user_can( 'upload_files' );
		}

		return current_user_can( 'edit_posts' );
	}

	public function can_inspect( array $input ): bool {
		return $this->can_access_resource( $input );
	}

	public function can_search_values( array $input ): bool {
		return $this->can_access_resource( $input );
	}

	private function can_access_resource( array $input ): bool {
		$kind = isset( $input['kind'] ) ? (string) $input['kind'] : '';
		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;

		if ( in_array( $kind, array( 'option', 'site' ), true ) ) {
			return current_user_can( 'manage_options' );
		}

		if ( 'post' === $kind || 'post-meta' === $kind || 'media' === $kind ) {
			return $id > 0 && current_user_can( 'edit_post', $id );
		}

		if ( 'term' === $kind ) {
			return $this->can_access_term( $input );
		}

		if ( 'user' === $kind ) {
			return $id > 0 && ( current_user_can( 'list_users' ) || get_current_user_id() === $id );
		}

		if ( 'comment' === $kind ) {
			return $id > 0 && current_user_can( 'moderate_comments' );
		}

		if ( 'menu' === $kind ) {
			return $id > 0 && current_user_can( 'edit_theme_options' );
		}

		if ( 'plugin' === $kind ) {
			return current_user_can( 'activate_plugins' );
		}

		if ( 'theme' === $kind ) {
			return current_user_can( 'switch_themes' ) || current_user_can( 'edit_theme_options' );
		}

		return false;
	}

	public function search( array $input ): array {
		$kind  = isset( $input['kind'] ) ? (string) $input['kind'] : 'post';
		$query = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';
		$limit = $this->normalize_limit( $input['limit'] ?? self::DEFAULT_LIMIT );

		switch ( $kind ) {
			case 'post-meta':
				$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
				return $this->search_post_meta( $id, $query, $limit );
			case 'option':
				return $this->search_options( $query, $limit );
			case 'media':
				return $this->search_media( $query, $limit );
			case 'term':
				return $this->search_terms( $input, $query, $limit );
			case 'user':
				return $this->search_users( $query, $limit );
			case 'comment':
				return $this->search_comments( $query, $limit );
			case 'menu':
				return $this->search_menus( $query, $limit );
			case 'plugin':
				return $this->search_plugins( $query, $limit );
			case 'theme':
				return $this->search_themes( $query, $limit );
			case 'site':
				return $this->search_site( $query );
			default:
				return $this->search_posts( $query, $limit );
		}
	}

	public function inspect( array $input ) {
		$loaded = $this->load_resource( $input );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$value   = $this->redact_value( $loaded['value'] );
		$pointer = isset( $input['pointer'] ) ? (string) $input['pointer'] : '';
		if ( '' !== $pointer ) {
			$value = $this->read_pointer( $value, $pointer );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
		}

		$preview = $this->bounded_preview( $value );

		return array(
			'address'   => $loaded['address'],
			'kind'      => $loaded['kind'],
			'encoding'  => $loaded['encoding'],
			'pointer'   => $pointer,
			'valueType' => $preview['type'],
			'value'     => $preview['value'],
			'truncated' => $preview['truncated'],
			'hint'      => $preview['truncated']
				? __( 'Use search-resource-values or inspect a more specific JSON Pointer.', 'wpcommander' )
				: '',
		);
	}

	public function search_values( array $input ) {
		$query = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';
		if ( '' === $query ) {
			return new WP_Error( 'wpcommander_missing_query', __( 'Search query is required.', 'wpcommander' ) );
		}

		$loaded = $this->load_resource( $input );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$matches = array();
		$nodes   = 0;

		$this->collect_matches(
			$this->redact_value( $loaded['value'] ),
			$query,
			'',
			$matches,
			$nodes,
			$this->normalize_limit( $input['limit'] ?? self::DEFAULT_LIMIT )
		);

		return array(
			'address' => $loaded['address'],
			'query'   => $query,
			'matches' => $matches,
			'scanned' => $nodes,
			'limited' => $nodes >= self::MAX_SEARCH_NODES,
		);
	}

	public function probe_kind( string $kind ): array {
		$input = array( 'kind' => $kind, 'limit' => 1 );
		if ( 'post-meta' === $kind ) {
			$input['query'] = '_elementor_data';
		}
		if ( 'option' === $kind ) {
			$input['query'] = 'blogname';
		}

		if ( ! $this->can_search( $input ) ) {
			return array(
				'kind'   => $kind,
				'status' => 'warning',
				'detail' => __( 'The current WordPress user cannot search this resource type.', 'wpcommander' ),
			);
		}

		$results = $this->search( $input );
		if ( empty( $results ) ) {
			return array(
				'kind'   => $kind,
				'status' => 'warning',
				'detail' => __( 'Search access is available, but no readable sample object was found.', 'wpcommander' ),
			);
		}

		$selector = array_intersect_key(
			$results[0],
			array_flip( array( 'kind', 'id', 'key', 'taxonomy' ) )
		);
		$loaded = $this->inspect( $selector );
		if ( is_wp_error( $loaded ) ) {
			return array(
				'kind'   => $kind,
				'status' => 'warning',
				'detail' => $loaded->get_error_message(),
			);
		}

		return array(
			'kind'     => $kind,
			'status'   => 'ok',
			'address'  => $loaded['address'],
			'encoding' => $loaded['encoding'],
			'detail'   => sprintf( __( 'Search + inspect succeeded for %s.', 'wpcommander' ), $loaded['address'] ),
		);
	}

	private function search_posts( string $query, int $limit ): array {
		$post_types = array_values( get_post_types( array( 'show_ui' => true ), 'names' ) );
		$posts      = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				's'              => $query,

				'orderby'        => 'modified',
				'order'          => 'DESC',
				'suppress_filters' => false,
			)
		);
		$items = array();

		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			$items[] = array(
				'address'  => 'post/' . $post->ID,
				'kind'     => 'post',
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'postType' => $post->post_type,
				'status'   => $post->post_status,
				'modified' => mysql_to_rfc3339( $post->post_modified_gmt ),
			);
		}

		return $items;
	}

	private function search_post_meta( int $post_id, string $query, int $limit ): array {
		global $wpdb;

		if ( $post_id < 1 && '' === $query ) {
			return array();
		}

		$like = '%' . $wpdb->esc_like( $query ) . '%';
		if ( $post_id > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT post_id, meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s ORDER BY meta_key LIMIT %d",
					$post_id,
					$like,
					$limit * 4
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s ORDER BY meta_key LIMIT %d",
					$like,
					$limit * 12
				),
				ARRAY_A
			);
		}

		$items = array();
		foreach ( $rows as $row ) {
			$id  = (int) $row['post_id'];
			$key = (string) $row['meta_key'];
			if ( ! current_user_can( 'edit_post', $id ) || $this->is_sensitive_key( $key ) ) {
				continue;
			}

			$items[] = array(
				'address' => 'post/' . $id . '/meta/' . rawurlencode( $key ),
				'kind'    => 'post-meta',
				'id'      => $id,
				'key'     => $key,
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	private function search_options( string $query, int $limit ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $query ) . '%';
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT %d",
				$like,
				$limit * 3
			)
		);
		$items = array();

		foreach ( $keys as $key ) {
			if ( $this->is_sensitive_key( (string) $key ) ) {
				continue;
			}

			$items[] = array(
				'address' => 'option/' . rawurlencode( (string) $key ),
				'kind'    => 'option',

				'key'     => (string) $key,
			);

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	private function search_media( string $query, int $limit ): array {
		$posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				's'              => $query,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$items = array();
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$items[] = array(
				'address'  => 'media/' . $post->ID,
				'kind'     => 'media',
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'mimeType' => get_post_mime_type( $post ),
				'url'      => wp_get_attachment_url( $post->ID ),
			);
		}
		return $items;
	}

	private function search_terms( array $input, string $query, int $limit ): array {
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$taxes    = '' !== $taxonomy ? array( $taxonomy ) : $this->editable_taxonomies();
		if ( empty( $taxes ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxes,
				'hide_empty' => false,
				'search'     => $query,
				'number'     => $limit,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$items = array();
		foreach ( $terms as $term ) {
			$items[] = array(
				'address'  => 'term/' . rawurlencode( $term->taxonomy ) . '/' . $term->term_id,
				'kind'     => 'term',
				'id'       => $term->term_id,
				'taxonomy' => $term->taxonomy,
				'name'     => $term->name,
				'slug'     => $term->slug,
				'count'    => $term->count,
			);
		}
		return $items;
	}

	private function search_users( string $query, int $limit ): array {
		$users = get_users(
			array(
				'number'         => $limit,
				'search'         => '' === $query ? '' : '*' . $query . '*',
				'search_columns' => array( 'user_login', 'user_nicename', 'display_name', 'user_email' ),
				'orderby'        => 'display_name',
			)
		);
		$items = array();
		foreach ( $users as $user ) {
			$items[] = array(
				'address'     => 'user/' . $user->ID,
				'kind'        => 'user',
				'id'          => $user->ID,
				'login'       => $user->user_login,
				'displayName' => $user->display_name,
				'roles'       => array_values( $user->roles ),
			);
		}
		return $items;
	}

	private function search_comments( string $query, int $limit ): array {
		$comments = get_comments(
			array(
				'number'  => $limit,
				'search'  => $query,
				'status'  => 'all',
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			)
		);
		$items = array();
		foreach ( $comments as $comment ) {
			$items[] = array(
				'address' => 'comment/' . $comment->comment_ID,
				'kind'    => 'comment',
				'id'      => (int) $comment->comment_ID,
				'postId'  => (int) $comment->comment_post_ID,
				'author'  => $comment->comment_author,
				'status'  => wp_get_comment_status( $comment ),
				'date'    => mysql_to_rfc3339( $comment->comment_date_gmt ),
			);
		}
		return $items;
	}

	private function search_menus( string $query, int $limit ): array {
		$menus = wp_get_nav_menus();
		$items = array();
		foreach ( $menus as $menu ) {
			if ( '' !== $query && false === stripos( $menu->name . ' ' . $menu->slug, $query ) ) {
				continue;
			}
			$items[] = array(
				'address' => 'menu/' . $menu->term_id,
				'kind'    => 'menu',
				'id'      => (int) $menu->term_id,
				'name'    => $menu->name,
				'slug'    => $menu->slug,
				'count'   => (int) $menu->count,
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		return $items;
	}

	private function search_plugins( string $query, int $limit ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		$items   = array();
		foreach ( $plugins as $file => $plugin ) {
			$haystack = $file . ' ' . ( $plugin['Name'] ?? '' ) . ' ' . ( $plugin['Description'] ?? '' );
			if ( '' !== $query && false === stripos( $haystack, $query ) ) {
				continue;
			}
			$items[] = array(
				'address' => 'plugin/' . rawurlencode( $file ),
				'kind'    => 'plugin',
				'key'     => $file,
				'name'    => $plugin['Name'] ?? $file,
				'version' => $plugin['Version'] ?? '',
				'active'  => is_plugin_active( $file ),
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		return $items;
	}

	private function search_themes( string $query, int $limit ): array {
		$themes = wp_get_themes();
		$items  = array();
		foreach ( $themes as $stylesheet => $theme ) {
			$haystack = $stylesheet . ' ' . $theme->get( 'Name' ) . ' ' . $theme->get( 'Description' );
			if ( '' !== $query && false === stripos( $haystack, $query ) ) {
				continue;
			}
			$items[] = array(
				'address'    => 'theme/' . rawurlencode( $stylesheet ),
				'kind'       => 'theme',
				'key'        => $stylesheet,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'active'     => get_stylesheet() === $stylesheet,
				'parentTheme' => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		return $items;
	}

	private function search_site( string $query ): array {
		$label = get_bloginfo( 'name' ) . ' ' . home_url( '/' );
		if ( '' !== $query && false === stripos( $label, $query ) ) {
			return array();
		}
		return array(
			array(
				'address' => 'site',
				'kind'    => 'site',
				'name'    => get_bloginfo( 'name' ),
				'url'     => home_url( '/' ),
			),
		);
	}

	private function can_search_terms( array $input ): bool {
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		if ( '' === $taxonomy ) {
			return ! empty( $this->editable_taxonomies() );
		}
		$object = get_taxonomy( $taxonomy );
		return $object && current_user_can( $object->cap->edit_terms );
	}

	private function can_access_term( array $input ): bool {
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$id       = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$object   = '' !== $taxonomy ? get_taxonomy( $taxonomy ) : false;
		return $id > 0 && $object && current_user_can( $object->cap->edit_terms );
	}

	private function editable_taxonomies(): array {
		$items = array();
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $taxonomy ) {
			if ( current_user_can( $taxonomy->cap->edit_terms ) ) {
				$items[] = $taxonomy->name;
			}
		}
		return $items;
	}

	private function load_resource( array $input ) {
		$kind     = isset( $input['kind'] ) ? (string) $input['kind'] : '';
		$id       = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$key      = isset( $input['key'] ) ? (string) $input['key'] : '';
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';

		switch ( $kind ) {
			case 'post':
				return $this->load_post( $id );
			case 'post-meta':
				return $this->load_post_meta( $id, $key );
			case 'option':
				return $this->load_option( $key );
			case 'media':
				return $this->load_media( $id );
			case 'term':
				return $this->load_term( $id, $taxonomy );
			case 'user':
				return $this->load_user( $id );
			case 'comment':
				return $this->load_comment( $id );
			case 'menu':
				return $this->load_menu( $id );
			case 'plugin':
				return $this->load_plugin( $key );
			case 'theme':
				return $this->load_theme( $key );
			case 'site':
				return $this->load_site();
			default:
				return new WP_Error( 'wpcommander_unknown_resource_kind', __( 'Unsupported resource kind.', 'wpcommander' ) );
		}
	}

	private function load_post( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'wpcommander_resource_not_found', __( 'Post was not found or is not accessible.', 'wpcommander' ) );
		}

		return array(
			'address'  => 'post/' . $post_id,
			'kind'     => 'post',
			'encoding' => 'wordpress-post',
			'value'    => array(
				'id'       => $post->ID,
				'type'     => $post->post_type,
				'status'   => $post->post_status,
				'title'    => $post->post_title,
				'slug'     => $post->post_name,
				'excerpt'  => $post->post_excerpt,
				'content'  => $post->post_content,
				'modified' => mysql_to_rfc3339( $post->post_modified_gmt ),
			),
		);
	}

	private function load_post_meta( int $post_id, string $key ) {
		if ( '' === $key || $this->is_sensitive_key( $key ) ) {
			return new WP_Error( 'wpcommander_resource_not_found', __( 'Meta resource is unavailable.', 'wpcommander' ) );
		}

		if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'wpcommander_resource_not_found', __( 'Post was not found or is not accessible.', 'wpcommander' ) );
		}

		if ( ! metadata_exists( 'post', $post_id, $key ) ) {
			return new WP_Error( 'wpcommander_resource_not_found', __( 'Post meta key was not found.', 'wpcommander' ) );
		}

		$decoded = $this->decode_value( get_post_meta( $post_id, $key, true ) );

		return array(
			'address'  => 'post/' . $post_id . '/meta/' . rawurlencode( $key ),
			'kind'     => 'post-meta',
			'encoding' => $decoded['encoding'],
			'value'    => $decoded['value'],
		);
	}

	private function load_option( string $key ) {
		if ( '' === $key || $this->is_sensitive_key( $key ) ) {
			return new WP_Error( 'wpcommander_resource_not_found', __( 'Option resource is unavailable.', 'wpcommander' ) );
		}

		$missing = '__wpcommander_missing_option__';
		$value   = get_option( $key, $missing );
		if ( $missing === $value ) {
			return new WP_Error( 'wpcommander_resource_not_found', __( 'Option was not found.', 'wpcommander' ) );
		}

		$decoded = $this->decode_value( $value );

		return array(
			'address'  => 'option/' . rawurlencode( $key ),
			'kind'     => 'option',
			'encoding' => $decoded['encoding'],
			'value'    => $decoded['value'],
		);
	}

	private function load_media( int $id ) {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wpcommander_resource_not_found', __( 'Media item was not found or is not accessible.', 'wpcommander' ) );
		}

		$metadata = wp_get_attachment_metadata( $id );
		return array(
			'address'  => 'media/' . $id,
			'kind'     => 'media',
			'encoding' => 'wordpress-media',
			'value'    => array(
				'id'          => $id,
				'title'       => $post->post_title,
				'caption'     => $post->post_excerpt,
				'description' => $post->post_content,
				'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'mimeType'    => get_post_mime_type( $id ),
				'url'         => wp_get_attachment_url( $id ),
				'filename'    => get_attached_file( $id ) ? wp_basename( get_attached_file( $id ) ) : '',
				'metadata'    => $this->normalize_value( $metadata ),
			),
		);
	}

	private function load_term( int $id, string $taxonomy ) {
		$object = get_taxonomy( $taxonomy );
		$term   = $object ? get_term( $id, $taxonomy ) : false;
		if ( ! $object || is_wp_error( $term ) || ! $term || ! current_user_can( $object->cap->edit_terms ) ) {
			return new WP_Error( 'wpcommander_resource_not_found', __( 'Term was not found or is not accessible.', 'wpcommander' ) );
		}

		return array(
			'address'  => 'term/' . rawurlencode( $taxonomy ) . '/' . $id,
			'kind'     => 'term',
			'encoding' => 'wordpress-term',
			'value'    => array(
				'id'          => $term->term_id,
				'taxonomy'    => $taxonomy,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => (int) $term->parent,
				'count'       => (int) $term->count,
			),
		);
	}

	private function load_user( int $id ) {
		$user = get_user_by( 'id', $id );
		if ( ! $user || ( ! current_user_can( 'list_users' ) && get_current_user_id() !== $id ) ) {
			return new WP_Error( 'wpcommander_resource_not_found', __( 'User was not found or is not accessible.', 'wpcommander' ) );
		}

		return array(
			'address'  => 'user/' . $id,
			'kind'     => 'user',
			'encoding' => 'wordpress-user',
			'value'    => array(
				'id'          => $id,
				'login'       => $user->user_login,
				'displayName' => $user->display_name,
				'nicename'    => $user->user_nicename,
				'email'       => current_user_can( 'list_users' ) ? $user->user_email : '',
				'url'         => $user->user_url,
				'roles'       => array_values( $user->roles ),
				'registered'  => mysql_to_rfc3339( $user->user_registered ),
			),
		);
	}

	private function load_comment( int $id ) {
		$comment = get_comment( $id );
		if ( ! $comment || ! current_user_can( 'moderate_comments' ) ) {
			return new WP_Error( 'wpcommander_resource_not_found', __( 'Comment was not found or is not accessible.', 'wpcommander' ) );
		}

		return array(
			'address'  => 'comment/' . $id,
			'kind'     => 'comment',
			'encoding' => 'wordpress-comment',
			'value'    => array(
				'id'      => (int) $comment->comment_ID,
				'postId'  => (int) $comment->comment_post_ID,
				'parent'  => (int) $comment->comment_parent,
				'author'  => $comment->comment_author,
				'email'   => $comment->comment_author_email,
				'content' => $comment->comment_content,
				'status'  => wp_get_comment_status( $comment ),
				'type'    => $comment->comment_type,
				'date'    => mysql_to_rfc3339( $comment->comment_date_gmt ),
			),
		);
	}

	private function load_menu( int $id ) {
		$menu = wp_get_nav_menu_object( $id );
		if ( ! $menu || is_wp_error( $menu ) || ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error( 'wpcommander_resource_not_found', __( 'Menu was not found or is not accessible.', 'wpcommander' ) );
		}

		$items = wp_get_nav_menu_items( $menu->term_id );
		$value = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$value[] = array(
				'id'       => (int) $item->ID,
				'title'    => $item->title,
				'url'      => $item->url,
				'parent'   => (int) $item->menu_item_parent,
				'object'   => $item->object,
				'objectId' => (int) $item->object_id,
				'type'     => $item->type,
				'classes'  => array_values( array_filter( (array) $item->classes ) ),
			);
		}

		return array(
			'address'  => 'menu/' . $menu->term_id,
			'kind'     => 'menu',
			'encoding' => 'wordpress-menu',
			'value'    => array(
				'id'    => (int) $menu->term_id,
				'name'  => $menu->name,
				'slug'  => $menu->slug,
				'items' => $value,
			),
		);
	}

	private function load_plugin( string $file ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'wpcommander_resource_not_found', __( 'Plugin is not accessible.', 'wpcommander' ) );
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		if ( '' === $file || ! isset( $plugins[ $file ] ) ) {
			return new WP_Error( 'wpcommander_resource_not_found', __( 'Plugin was not found.', 'wpcommander' ) );
		}
		$plugin = $plugins[ $file ];

		return array(
			'address'  => 'plugin/' . rawurlencode( $file ),
			'kind'     => 'plugin',
			'encoding' => 'wordpress-plugin',
			'value'    => array(
				'file'        => $file,
				'name'        => $plugin['Name'] ?? $file,
				'version'     => $plugin['Version'] ?? '',
				'description' => wp_strip_all_tags( $plugin['Description'] ?? '' ),
				'author'      => wp_strip_all_tags( $plugin['AuthorName'] ?? '' ),
				'active'      => is_plugin_active( $file ),
				'networkOnly' => ! empty( $plugin['Network'] ),
			),
		);
	}

	private function load_theme( string $stylesheet ) {
		if ( ! current_user_can( 'switch_themes' ) && ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error( 'wpcommander_resource_not_found', __( 'Theme is not accessible.', 'wpcommander' ) );
		}
		$theme = wp_get_theme( $stylesheet );
		if ( '' === $stylesheet || ! $theme->exists() ) {
			return new WP_Error( 'wpcommander_resource_not_found', __( 'Theme was not found.', 'wpcommander' ) );
		}

		return array(
			'address'  => 'theme/' . rawurlencode( $stylesheet ),
			'kind'     => 'theme',
			'encoding' => 'wordpress-theme',
			'value'    => array(
				'stylesheet'  => $stylesheet,
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'description' => wp_strip_all_tags( $theme->get( 'Description' ) ),
				'author'      => wp_strip_all_tags( $theme->get( 'Author' ) ),
				'active'      => get_stylesheet() === $stylesheet,
				'parent'      => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
			),
		);
	}

	private function load_site(): array {
		return array(
			'address'  => 'site',
			'kind'     => 'site',
			'encoding' => 'wordpress-site',
			'value'    => array(
				'name'            => get_bloginfo( 'name' ),
				'description'     => get_bloginfo( 'description' ),
				'homeUrl'         => home_url( '/' ),
				'siteUrl'         => site_url( '/' ),
				'wordpressVersion'=> get_bloginfo( 'version' ),
				'phpVersion'      => PHP_VERSION,
				'locale'          => get_locale(),
				'timezone'        => wp_timezone_string(),
				'multisite'       => is_multisite(),
				'environment'     => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
				'activeTheme'     => get_stylesheet(),
				'permalinkStructure' => get_option( 'permalink_structure' ),
			),
		);
	}

	private function decode_value( $value ): array {
		$encoding = is_serialized( $value ) ? 'php-serialized' : 'native';
		$value    = maybe_unserialize( $value );

		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ) {
				$decoded = json_decode( $trimmed, true );
				if ( JSON_ERROR_NONE === json_last_error() ) {
					return array(
						'encoding' => 'json',
						'value'    => $decoded,
					);
				}
			}
		}

		return array(
			'encoding' => $encoding,
			'value'    => $this->normalize_value( $value ),
		);
	}

	private function normalize_value( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$normalized = array();
		foreach ( $value as $key => $item ) {
			$normalized[ $key ] = $this->normalize_value( $item );
		}

		return $normalized;
	}

	private function redact_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$redacted = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && $this->is_sensitive_key( $key ) ) {
				$redacted[ $key ] = '[redacted]';
				continue;
			}

			$redacted[ $key ] = $this->redact_value( $item );
		}

		return $redacted;
	}

	private function is_sensitive_key( string $key ): bool {

		return (bool) preg_match(
			'/(?:pass(?:word)?|secret|token|api[-_]?key|credential|private[-_]?key|client[-_]?secret|access[-_]?key|license[-_]?key|(?:^|[-_])auth(?:orization)?(?:$|[-_]))/i',
			$key
		);
	}

	private function read_pointer( $value, string $pointer ) {
		if ( '' === $pointer ) {
			return $value;
		}

		if ( '/' !== $pointer[0] ) {
			return new WP_Error( 'wpcommander_invalid_pointer', __( 'JSON Pointer must start with a slash.', 'wpcommander' ) );
		}

		$current = $value;
		foreach ( explode( '/', substr( $pointer, 1 ) ) as $segment ) {
			$segment = str_replace( array( '~1', '~0' ), array( '/', '~' ), $segment );
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return new WP_Error( 'wpcommander_pointer_not_found', __( 'JSON Pointer does not exist in this resource.', 'wpcommander' ) );
			}

			$current = $current[ $segment ];
		}

		return $current;
	}

	private function bounded_preview( $value ): array {
		$json = wp_json_encode( $value );
		if ( false !== $json && strlen( $json ) <= self::MAX_PREVIEW_BYTES ) {
			return array(
				'type'      => $this->value_type( $value ),
				'value'     => $value,
				'truncated' => false,
			);
		}

		if ( is_string( $value ) ) {
			$excerpt = function_exists( 'mb_substr' )
				? mb_substr( $value, 0, self::MAX_PREVIEW_BYTES )
				: substr( $value, 0, self::MAX_PREVIEW_BYTES );

			return array(
				'type'      => 'string',
				'value'     => $excerpt,
				'truncated' => true,
			);
		}

		return array(
			'type'      => $this->value_type( $value ),
			'value'     => $this->structure_summary( $value ),
			'truncated' => true,
		);
	}

	private function structure_summary( $value ): array {
		if ( ! is_array( $value ) ) {
			return array( 'type' => $this->value_type( $value ) );
		}

		$keys = array_slice( array_keys( $value ), 0, 50 );
		return array(
			'type'  => 'array',
			'count' => count( $value ),
			'keys'  => $keys,
		);
	}

	private function value_type( $value ): string {
		if ( is_array( $value ) ) {
			return 'array';
		}
		if ( null === $value ) {
			return 'null';
		}

		return gettype( $value );
	}

	private function collect_matches( $value, string $query, string $pointer, array &$matches, int &$nodes, int $limit ): void {

		if ( $nodes >= self::MAX_SEARCH_NODES || count( $matches ) >= $limit ) {
			return;
		}

		++$nodes;
		if ( ! is_array( $value ) ) {
			if ( null !== $value && false !== stripos( (string) $value, $query ) ) {
				$matches[] = array(
					'pointer' => '' === $pointer ? '/' : $pointer,
					'value'   => $this->scalar_excerpt( $value ),
				);
			}
			return;
		}

		foreach ( $value as $key => $item ) {
			$segment       = str_replace( array( '~', '/' ), array( '~0', '~1' ), (string) $key );
			$child_pointer = $pointer . '/' . $segment;
			if ( false !== stripos( (string) $key, $query ) && count( $matches ) < $limit ) {
				$matches[] = array(
					'pointer' => $child_pointer,
					'value'   => is_array( $item ) ? $this->structure_summary( $item ) : $this->scalar_excerpt( $item ),
				);
			}

			$this->collect_matches( $item, $query, $child_pointer, $matches, $nodes, $limit );
			if ( $nodes >= self::MAX_SEARCH_NODES || count( $matches ) >= $limit ) {
				break;
			}
		}
	}

	private function scalar_excerpt( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) <= 500 ) {
			return $value;
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 500 ) . '…' : substr( $value, 0, 500 ) . '...';
	}

	private function normalize_limit( $value ): int {
		$limit = (int) $value;
		if ( $limit < 1 ) {
			return self::DEFAULT_LIMIT;
		}

		return min( $limit, self::MAX_LIMIT );
	}
}
