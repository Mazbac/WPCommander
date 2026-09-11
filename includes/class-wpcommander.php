<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPCommander {
	private static $instance = null;
	private $resources;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->resources = new WPCommander_Resources();

		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_ability_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	public function register_admin_page(): void {
		add_menu_page(
			__( 'WPCommander', 'wpcommander' ),
			__( 'WPCommander', 'wpcommander' ),
			'manage_options',
			'wpcommander',
			array( $this, 'render_admin_page' ),
			'dashicons-controls-repeat',
			65
		);
	}

	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_wpcommander' !== $hook_suffix ) {
			return;
		}

		$css_path = WPCOMMANDER_PATH . 'dist/assets/wpcommander.css';
		$js_path  = WPCOMMANDER_PATH . 'dist/assets/wpcommander.js';

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'wpcommander-admin',
				WPCOMMANDER_URL . 'dist/assets/wpcommander.css',
				array(),
				(string) filemtime( $css_path )
			);
		}

		if ( file_exists( $js_path ) ) {
			wp_enqueue_script_module(
				'wpcommander-admin',
				WPCOMMANDER_URL . 'dist/assets/wpcommander.js',
				array(),
				(string) filemtime( $js_path )
			);
		}
	}

	public function render_admin_page(): void {
		$bootstrap = wp_json_encode(
			$this->get_admin_bootstrap_data(),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		echo '<div class="wrap"><div id="wpcommander-root"></div></div>';
		echo '<script>window.wpCommanderBootstrap=' . $bootstrap . ';</script>';
	}

	public function register_rest_routes(): void {
		register_rest_route(
			'wpcommander/v1',
			'/openapi',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_openapi' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wpcommander/v1',
			'/manifest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_manifest' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'wpcommander/v1',
			'/diagnostics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_diagnostics' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'wpcommander/v1',
			'/abilities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_abilities' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'wpcommander/v1',
			'/abilities/execute',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_execute_ability' ),
				'permission_callback' => static function (): bool {
					return is_user_logged_in();
				},
			)
		);
	}

	public function register_ability_category(): void {
		wp_register_ability_category(
			'wpcommander-control',
			array(
				'label'       => __( 'WPCommander Control', 'wpcommander' ),
				'description' => __( 'Machine-readable WordPress control-plane abilities.', 'wpcommander' ),
			)
		);
	}

	public function register_abilities(): void {
		wp_register_ability(
			'wpcommander/get-manifest',
			array(
				'label'               => __( 'Get WPCommander manifest', 'wpcommander' ),
				'description'         => __( 'Returns site readiness and WPCommander connection metadata.', 'wpcommander' ),
				'category'            => 'wpcommander-control',
				'output_schema'       => array(
					'type' => 'object',
				),
				'execute_callback'    => array( $this, 'get_manifest_data' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly' => true,
					),
				),
			)
		);

		wp_register_ability(
			'wpcommander/list-abilities',
			array(
				'label'               => __( 'List exposed abilities', 'wpcommander' ),
				'description'         => __( 'Lists WordPress Abilities exposed for external clients.', 'wpcommander' ),
				'category'            => 'wpcommander-control',
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'execute_callback'    => array( $this, 'get_abilities_data' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);

		wp_register_ability(
			'wpcommander/get-access-report',
			array(
				'label'               => __( 'Run read-only access diagnostics', 'wpcommander' ),
				'description'         => __( 'Checks which WordPress subsystems WPCommander can inspect without changing production data.', 'wpcommander' ),
				'category'            => 'wpcommander-control',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'get_diagnostics_data' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			)
		);

		$this->register_resource_abilities();
	}

	private function register_resource_abilities(): void {
		$meta = array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'   => true,
				'destructive' => false,
				'idempotent' => true,
			),
		);

		wp_register_ability(
			'wpcommander/search-resources',
			array(
				'label'               => __( 'Search WordPress resources', 'wpcommander' ),
				'description'         => __( 'Find WordPress resources across content, builder data, media, taxonomy, users, comments, menus, plugins, themes, options, and site state without vendor-specific adapters.', 'wpcommander' ),
				'category'            => 'wpcommander-control',
				'input_schema'        => $this->get_resource_search_schema(),
				'output_schema'       => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'execute_callback'    => array( $this->resources, 'search' ),
				'permission_callback' => array( $this->resources, 'can_search' ),
				'meta'                => $meta,
			)
		);

		wp_register_ability(
			'wpcommander/inspect-resource',
			array(
				'label'               => __( 'Inspect a WordPress resource', 'wpcommander' ),
				'description'         => __( 'Read a bounded WordPress resource or JSON Pointer across content, builders, media, taxonomy, users, comments, menus, plugins, themes, options, and site state.', 'wpcommander' ),
				'category'            => 'wpcommander-control',
				'input_schema'        => $this->get_resource_inspect_schema(),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this->resources, 'inspect' ),
				'permission_callback' => array( $this->resources, 'can_inspect' ),
				'meta'                => $meta,
			)
		);

		wp_register_ability(
			'wpcommander/search-resource-values',
			array(
				'label'               => __( 'Search inside a WordPress resource', 'wpcommander' ),
				'description'         => __( 'Find matching keys or scalar values and return JSON Pointers, useful for large builder or theme data.', 'wpcommander' ),
				'category'            => 'wpcommander-control',
				'input_schema'        => $this->get_resource_value_search_schema(),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this->resources, 'search_values' ),
				'permission_callback' => array( $this->resources, 'can_search_values' ),
				'meta'                => $meta,
			)
		);
	}

	private function get_resource_selector_properties(): array {
		return array(
			'kind' => array(
				'type'        => 'string',
				'enum'        => array( 'post', 'post-meta', 'option', 'media', 'term', 'user', 'comment', 'menu', 'plugin', 'theme', 'site' ),
				'description' => 'Generic WordPress resource kind.',
			),
			'id'   => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Numeric object ID for post, post-meta, media, term, user, comment, or menu.',
			),
			'key'  => array(
				'type'        => 'string',
				'maxLength'   => 191,
				'description' => 'Exact post-meta/option key, plugin file, or theme stylesheet. Sensitive-looking keys are not exposed.',
			),
			'taxonomy' => array(
				'type'        => 'string',
				'maxLength'   => 64,
				'description' => 'Taxonomy name for term resources.',
			),
		);
	}

	private function get_resource_search_schema(): array {
		$properties          = $this->get_resource_selector_properties();
		$properties['query'] = array( 'type' => 'string', 'maxLength' => 200 );
		$properties['limit'] = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20 );

		return array(
			'type'       => 'object',
			'properties' => $properties,
		);
	}

	private function get_resource_inspect_schema(): array {
		$properties            = $this->get_resource_selector_properties();
		$properties['pointer'] = array(
			'type'        => 'string',
			'maxLength'   => 1000,
			'description' => 'Optional RFC 6901 JSON Pointer into the decoded resource value.',
		);

		return array(
			'type'       => 'object',
			'required'   => array( 'kind' ),
			'properties' => $properties,
		);
	}

	private function get_resource_value_search_schema(): array {
		$properties          = $this->get_resource_selector_properties();
		$properties['query'] = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 );
		$properties['limit'] = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20 );

		return array(
			'type'       => 'object',
			'required'   => array( 'kind', 'query' ),
			'properties' => $properties,
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function rest_openapi(): WP_REST_Response {
		return rest_ensure_response( $this->get_openapi_schema() );
	}

	public function rest_manifest(): WP_REST_Response {
		return rest_ensure_response( $this->get_manifest_data() );
	}

	public function rest_diagnostics(): WP_REST_Response {
		return rest_ensure_response( $this->get_diagnostics_data() );
	}

	public function rest_abilities(): WP_REST_Response {
		return rest_ensure_response( $this->get_abilities_data() );
	}

	public function rest_execute_ability( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$name   = isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '';
		$input  = array_key_exists( 'input', $params ) ? $params['input'] : null;

		if ( '' === $name ) {
			return new WP_Error( 'wpcommander_missing_ability', __( 'Ability name is required.', 'wpcommander' ), array( 'status' => 400 ) );
		}

		$ability = wp_get_ability( $name );
		if ( ! $ability || ! $this->ability_is_exposed( $ability ) ) {
			return new WP_Error( 'wpcommander_ability_not_found', __( 'The requested ability is not exposed.', 'wpcommander' ), array( 'status' => 404 ) );
		}

		if ( $this->is_read_only_mode() && ! $this->ability_is_readonly( $ability ) ) {
			return new WP_Error(
				'wpcommander_read_only_mode',
				__( 'WPCommander is currently in read-only diagnostics mode. Write abilities are blocked.', 'wpcommander' ),
				array( 'status' => 403 )
			);
		}

		$result = $ability->execute( $input );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function get_manifest_data(): array {
		$supported = wp_is_application_passwords_supported();

		return array(
			'siteName'          => get_bloginfo( 'name' ),
			'wordpressVersion'  => get_bloginfo( 'version' ),
			'pluginVersion'     => WPCOMMANDER_VERSION,
			'accessMode'        => $this->is_read_only_mode() ? 'read-only' : 'write-enabled',
			'connectionStatus'  => $supported ? 'ready' : 'warning',
			'connectionMessage' => $supported
				? __( 'Control plane is ready for a Custom GPT connection.', 'wpcommander' )
				: __( 'Application Passwords are unavailable. HTTPS or a supported local environment is required.', 'wpcommander' ),
			'schemaUrl'         => rest_url( 'wpcommander/v1/openapi' ),
			'resourceKinds'     => array( 'post', 'post-meta', 'option', 'media', 'term', 'user', 'comment', 'menu', 'plugin', 'theme', 'site' ),
			'capabilities'      => array(
				array(
					'id'          => 'discover',
					'label'       => __( 'Discover site capabilities', 'wpcommander' ),
					'description' => __( 'Inspect exposed WordPress Abilities and WPCommander resources.', 'wpcommander' ),
					'access'      => 'read',
					'source'      => 'WPCommander',
				),
				array(
					'id'          => 'inspect',
					'label'       => __( 'Inspect site data', 'wpcommander' ),
					'description' => __( 'Search and inspect bounded posts, post meta, safe options, and structured builder data.', 'wpcommander' ),
					'access'      => 'read',
					'source'      => 'WPCommander',
				),
				array(
					'id'          => 'execute',
					'label'       => $this->is_read_only_mode() ? __( 'Run read-only abilities', 'wpcommander' ) : __( 'Execute exposed abilities', 'wpcommander' ),
					'description' => $this->is_read_only_mode()
						? __( 'Execute exposed read-only WordPress Abilities while production writes stay blocked.', 'wpcommander' )
						: __( 'Run exposed WordPress Abilities through one stable action endpoint.', 'wpcommander' ),
					'access'      => $this->is_read_only_mode() ? 'read' : 'write',
					'source'      => 'WordPress',
				),
			),
			'activity'          => array(),
		);
	}

	private function get_admin_bootstrap_data(): array {
		$data                   = $this->get_manifest_data();
		$data['diagnosticsUrl'] = rest_url( 'wpcommander/v1/diagnostics' );
		$data['restNonce']      = wp_create_nonce( 'wp_rest' );

		return $data;
	}

	public function get_diagnostics_data(): array {
		global $wpdb;

		$labels = array(
			'post'      => __( 'Posts and pages', 'wpcommander' ),
			'post-meta' => __( 'Post metadata / builders', 'wpcommander' ),
			'option'    => __( 'Options and theme settings', 'wpcommander' ),
			'media'     => __( 'Media library', 'wpcommander' ),
			'term'      => __( 'Taxonomies and terms', 'wpcommander' ),
			'user'      => __( 'Users', 'wpcommander' ),
			'comment'   => __( 'Comments', 'wpcommander' ),
			'menu'      => __( 'Classic navigation menus', 'wpcommander' ),
			'plugin'    => __( 'Plugins', 'wpcommander' ),
			'theme'     => __( 'Themes', 'wpcommander' ),
			'site'      => __( 'Site and environment', 'wpcommander' ),
		);

		$checks = array();
		foreach ( $labels as $kind => $label ) {
			$probe          = $this->resources->probe_kind( $kind );
			$probe['id']    = $kind;
			$probe['label'] = $label;
			$checks[]       = $probe;
		}

		$elementor = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data'" );
		foreach ( $checks as &$check ) {
			if ( 'post-meta' === $check['id'] ) {
				$check['detail'] .= ' ' . sprintf( __( '%d posts contain Elementor data.', 'wpcommander' ), $elementor );
				break;
			}
		}
		unset( $check );

		$abilities = wp_get_abilities();
		$exposed   = 0;
		foreach ( $abilities as $ability ) {
			if ( $this->ability_is_exposed( $ability ) ) {
				++$exposed;
			}
		}
		$checks[] = array(
			'id'     => 'abilities',
			'label'  => __( 'WordPress Abilities', 'wpcommander' ),
			'status' => 'ok',
			'detail' => sprintf( __( '%d abilities are exposed to external clients.', 'wpcommander' ), $exposed ),
		);

		return array(
			'generatedAt'   => gmdate( 'c' ),
			'accessMode'    => $this->is_read_only_mode() ? 'read-only' : 'write-enabled',
			'resourceKinds' => array_keys( $labels ),
			'checks'        => $checks,
		);
	}

	public function get_abilities_data(): array {
		$abilities = wp_get_abilities();
		ksort( $abilities );
		$items = array();

		foreach ( array_slice( $abilities, 0, 100, true ) as $ability ) {
			if ( ! $this->ability_is_exposed( $ability ) ) {
				continue;
			}

			$meta    = $ability->get_meta();
			$items[] = array(
				'name'         => $ability->get_name(),
				'label'        => $ability->get_label(),
				'description'  => $ability->get_description(),
				'category'     => $ability->get_category(),
				'inputSchema'  => $ability->get_input_schema(),
				'outputSchema' => $ability->get_output_schema(),
				'annotations'  => isset( $meta['annotations'] ) ? $meta['annotations'] : array(),
			);
		}

		return $items;
	}

	private function ability_is_exposed( WP_Ability $ability ): bool {
		$meta = $ability->get_meta();
		return ! empty( $meta['show_in_rest'] ) || ! empty( $meta['public'] );
	}

	private function ability_is_readonly( WP_Ability $ability ): bool {
		$meta        = $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		return ! empty( $annotations['readonly'] );
	}

	private function is_read_only_mode(): bool {
		return (bool) apply_filters( 'wpcommander_read_only_mode', true );
	}

	private function get_openapi_schema(): array {
		return array(
			'openapi' => '3.1.0',
			'info'    => array(
				'title'       => 'WPCommander',
				'version'     => WPCOMMANDER_VERSION,
				'description' => 'Discover and execute safe, machine-readable WordPress capabilities.',
			),
			'servers' => array(
				array( 'url' => untrailingslashit( home_url( '/' ) ) ),
			),
			'paths'   => array(
				'/wp-json/wpcommander/v1/manifest' => array(
					'get' => array(
						'operationId' => 'getWPCommanderManifest',
						'summary'     => 'Check WPCommander readiness and connection metadata',
						'security'    => array( array( 'basicAuth' => array() ) ),
						'responses'   => array(
							'200' => array(
								'description' => 'WPCommander manifest',
								'content'     => array(
									'application/json' => array( 'schema' => array( 'type' => 'object' ) ),
								),
							),
						),
					),
				),
				'/wp-json/wpcommander/v1/diagnostics' => array(
					'get' => array(
						'operationId' => 'getWPCommanderDiagnostics',
						'summary'     => 'Run read-only access diagnostics against the WordPress site',
						'security'    => array( array( 'basicAuth' => array() ) ),
						'responses'   => array(
							'200' => array(
								'description' => 'Read-only access report',
								'content'     => array(
									'application/json' => array( 'schema' => array( 'type' => 'object' ) ),
								),
							),
						),
					),
				),
				'/wp-json/wpcommander/v1/abilities' => array(
					'get' => array(
						'operationId' => 'listWordPressAbilities',
						'summary'     => 'List WordPress Abilities exposed to external clients',
						'security'    => array( array( 'basicAuth' => array() ) ),
						'responses'   => array(
							'200' => array(
								'description' => 'Exposed abilities',
								'content'     => array(
									'application/json' => array(
										'schema' => array(
											'type'  => 'array',
											'items' => array( 'type' => 'object' ),
										),
									),
								),
							),
						),
					),
				),
				'/wp-json/wpcommander/v1/abilities/execute' => array(
					'post' => array(
						'operationId' => 'executeWordPressAbility',
						'summary'     => 'Execute one exposed WordPress Ability',
						'security'    => array( array( 'basicAuth' => array() ) ),
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'required'   => array( 'name' ),
										'properties' => array(
											'name'  => array(
												'type'        => 'string',
												'description' => 'Namespaced ability name returned by listWordPressAbilities.',
											),
											'input' => array(
												'description' => 'Input matching the selected ability inputSchema.',
											),
										),
									),
								),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Ability result',
								'content'     => array(
									'application/json' => array( 'schema' => array() ),
								),
							),
						),
					),
				),
			),
			'components' => array(
				'securitySchemes' => array(
					'basicAuth' => array(
						'type'   => 'http',
						'scheme' => 'basic',
					),
				),
			),
		);
	}
}
