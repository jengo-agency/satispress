<?php
/**
 * Settings screen provider.
 *
 * @package SatisPress
 * @license GPL-2.0-or-later
 * @since 0.2.0
 */

declare ( strict_types = 1 );

namespace SatisPress\Screen;

use Cedaro\WP\Plugin\AbstractHookProvider;
use SatisPress\Authentication\ApiKey\ApiKey;
use SatisPress\Authentication\ApiKey\ApiKeyRepository;
use SatisPress\Capabilities;
use SatisPress\Provider\HealthCheck;
use WP_Theme;

use function SatisPress\get_packages_permalink;
use function SatisPress\preload_rest_data;

/**
 * Settings screen provider class.
 *
 * @since 0.2.0
 */
class Settings extends AbstractHookProvider {
	/**
	 * API Key repository.
	 *
	 * @var ApiKeyRepository
	 */
	protected $api_keys;

	/**
	 * Create the setting screen.
	 *
	 * @param ApiKeyRepository $api_keys API Key repository.
	 */
	public function __construct( ApiKeyRepository $api_keys ) {
		$this->api_keys = $api_keys;
	}

	/**
	 * Register hooks.
	 *
	 * @since 0.3.0
	 */
	public function register_hooks() {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', [ $this, 'add_menu_item' ] );
		} else {
			add_action( 'admin_menu', [ $this, 'add_menu_item' ] );
		}

		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'add_sections' ] );
		add_action( 'admin_init', [ $this, 'add_settings' ] );
		add_action( 'admin_init', [ $this, 'handle_cache_purge' ] );
	}

	/**
	 * Handle cache purge request.
	 *
	 * @since 2.0.2
	 */
	public function handle_cache_purge() {
		if ( ! isset( $_GET['satispress_action'] ) ) {
			return;
		}

		$action = $_GET['satispress_action'];
		if ( ! in_array( $action, [ 'purge_packages_json', 'purge_packages_cache' ], true ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_OPTIONS ) ) {
			return;
		}

		check_admin_referer( 'satispress_' . $action );

		if ( 'purge_packages_json' === $action ) {
			// Purge packages.json cache
			$version = (int) get_option( 'satispress_packages_cache_version', '1' );
			update_option( 'satispress_packages_cache_version', (string) ( $version + 1 ) );

			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_satispress_packages_%' OR option_name LIKE '_transient_timeout_satispress_packages_%'" );

			add_settings_error( 'satispress', 'packages_json_purged', esc_html__( 'packages.json cache purged successfully.', 'satispress' ), 'success' );
		} elseif ( 'purge_packages_cache' === $action ) {
			// Purge checksum transients
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_satispress_hash_%' OR option_name LIKE '_transient_timeout_satispress_hash_%'" );
			add_settings_error( 'satispress', 'packages_cache_purged', esc_html__( 'Packages cache purged successfully.', 'satispress' ), 'success' );
		}

		wp_safe_redirect( remove_query_arg( [ 'satispress_action', '_wpnonce' ] ) );
		exit;
	}

	/**
	 * Add the settings menu item.
	 *
	 * @since 0.2.0
	 */
	public function add_menu_item() {
		$parent_slug = 'options-general.php';
		if ( is_network_admin() ) {
			$parent_slug = 'settings.php';
		}

		$page_hook = add_submenu_page(
			$parent_slug,
			esc_html__( 'SatisPress', 'satispress' ),
			esc_html__( 'SatisPress', 'satispress' ),
			Capabilities::MANAGE_OPTIONS,
			'satispress',
			[ $this, 'render_screen' ]
		);

		add_action( 'load-' . $page_hook, [ $this, 'load_screen' ] );
	}

	/**
	 * Set up the screen.
	 *
	 * @since 0.3.0
	 */
	public function load_screen() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ HealthCheck::class, 'display_authorization_notice' ] );
		add_action( 'admin_notices', [ HealthCheck::class, 'display_permalink_notice' ] );
	}

	/**
	 * Enqueue scripts and styles.
	 *
	 * @since 0.2.0
	 */
	public function enqueue_assets() {
		wp_enqueue_script( 'satispress-admin' );
		wp_enqueue_style( 'satispress-admin' );
		wp_enqueue_script( 'satispress-access' );
		wp_enqueue_script( 'satispress-repository' );

		wp_localize_script(
			'satispress-access',
			'_satispressAccessData',
			[
				'editedUserId' => get_current_user_id(),
			]
		);

		$preload_paths = [
			'/satispress/v1/packages',
		];

		if ( current_user_can( Capabilities::MANAGE_OPTIONS ) ) {
			$preload_paths = array_merge(
				$preload_paths,
				[
					'/satispress/v1/apikeys?user=' . get_current_user_id(),
					'/satispress/v1/plugins?_fields=slug,name,type',
					'/satispress/v1/themes?_fields=slug,name,type',
				]
			);
		}

		preload_rest_data( $preload_paths );
	}

	/**
	 * Add settings page link to the plugins page.
	 *
	 * @param array $actions An array of plugin action links.
	 * @return array
	 */
	public function add_settings_link( array $actions ): array {
		array_unshift(
			$actions,
			sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				menu_page_url( 'satispress', false ),
				esc_attr__( 'Settings for SatisPress', 'satispress' ),
				esc_html__( 'Settings', 'satispress' )
			),
		);

		return $actions;
	}

	/**
	 * Register settings.
	 *
	 * @since 0.2.0
	 */
	public function register_settings() {
		register_setting( 'satispress', 'satispress', [ $this, 'sanitize_settings' ] );
	}

	/**
	 * Add settings sections.
	 *
	 * @since 0.2.0
	 */
	public function add_sections() {
		add_settings_section(
			'default',
			esc_html__( 'General', 'satispress' ),
			'__return_null',
			'satispress'
		);
	}

	/**
	 * Register individual settings.
	 *
	 * @since 0.2.0
	 */
	public function add_settings() {
		add_settings_field(
			'vendor',
			'<label for="satispress-vendor">' . esc_html__( 'Vendor', 'satispress' ) . '</label>',
			[ $this, 'render_field_vendor' ],
			'satispress',
			'default'
		);

		add_settings_field(
			'packages_json',
			esc_html__( 'packages.json', 'satispress' ),
			[ $this, 'render_field_packages_json' ],
			'satispress',
			'default'
		);

		add_settings_field(
			'packages_cache',
			esc_html__( 'Packages Cache', 'satispress' ),
			[ $this, 'render_field_packages_cache' ],
			'satispress',
			'default'
		);

		add_settings_field(
			'enable_purge',
			esc_html__( 'Enable Purge', 'satispress' ),
			[ $this, 'render_field_enable_purge' ],
			'satispress',
			'default'
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @since 0.2.0
	 *
	 * @param array $value Settings values.
	 * @return array Sanitized and filtered settings values.
	 */
	public function sanitize_settings( array $value ): array {
		if ( ! empty( $value['vendor'] ) ) {
			$value['vendor'] = preg_replace( '/[^a-z0-9_\-\.]+/i', '', $value['vendor'] );
		}

		$value['enable_purge'] = isset( $value['enable_purge'] ) && 'yes' === $value['enable_purge'] ? 'yes' : 'no';

		return (array) apply_filters( 'satispress_sanitize_settings', $value );
	}

	/**
	 * Display the screen.
	 *
	 * @since 0.2.0
	 */
	public function render_screen() {
		$permalink = esc_url( get_packages_permalink() );

		$tabs = [
			'repository' => [
				'name'       => esc_html__( 'Repository', 'satispress' ),
				'capability' => Capabilities::VIEW_PACKAGES,
			],
			'access'     => [
				'name'       => esc_html__( 'Access', 'satispress' ),
				'capability' => Capabilities::MANAGE_OPTIONS,
				'is_active'  => false,
			],
			'composer'   => [
				'name'       => esc_html__( 'Composer', 'satispress' ),
				'capability' => Capabilities::VIEW_PACKAGES,
			],
			'settings'   => [
				'name'       => esc_html__( 'Settings', 'satispress' ),
				'capability' => Capabilities::MANAGE_OPTIONS,
			],
		];

		$active_tab = 'repository';

		include $this->plugin->get_path( 'views/screen-settings.php' );
	}

	/**
	 * Display a field for defining the vendor.
	 *
	 * @since 0.2.0
	 */
	public function render_field_vendor() {
		$value = $this->get_setting( 'vendor', '' );
		?>
		<p>
			<input type="text" name="satispress[vendor]" id="satispress-vendor" value="<?php echo esc_attr( $value ); ?>"><br />
			<span class="description">Default is <code>satispress</code></span>
		</p>
		<?php
	}

	/**
	 * Display a field for packages.json cache management.
	 *
	 * @since 2.0.2
	 */
	public function render_field_packages_json() {
		$purge_url = wp_nonce_url(
			add_query_arg( 'satispress_action', 'purge_packages_json', menu_page_url( 'satispress', false ) ),
			'satispress_purge_packages_json'
		);
		$version = (int) get_option( 'satispress_packages_cache_version', '1' );
		$packages_url = get_packages_permalink();

		global $wpdb;
		$cache_keys = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_satispress_packages_%' AND option_name NOT LIKE '%_time'" );
		$cache_keys = array_map( function( $key ) {
			return str_replace( '_transient_', '', $key );
		}, $cache_keys );

		$cache_info = [];
		foreach ( $cache_keys as $key ) {
			$time = get_transient( $key . '_time' );
			$cache_info[ $key ] = $time ? wp_date( 'Y-m-d H:i:s', (int) $time ) : 'Unknown';
		}
		?>
		<p>
			<a href="<?php echo esc_url( $purge_url ); ?>" class="button"><?php esc_html_e( 'Purge packages.json Cache', 'satispress' ); ?></a>
		</p>
		<p class="description">
			<?php esc_html_e( 'Clears the cached packages.json.', 'satispress' ); ?><br>
			<?php printf( esc_html__( 'Current cache version: %d', 'satispress' ), $version ); ?><br>
			<a href="<?php echo esc_url( $packages_url ); ?>" target="_blank"><?php esc_html_e( 'View packages.json', 'satispress' ); ?></a>
		</p>
		<?php if ( ! empty( $cache_info ) ) : ?>
			<p class="description">
				<strong><?php esc_html_e( 'Active Cache Keys:', 'satispress' ); ?></strong><br>
				<?php foreach ( $cache_info as $key => $time ) : ?>
					<code><?php echo esc_html( $key ); ?></code> - <?php echo esc_html( $time ); ?><br>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Display a field for packages cache management.
	 *
	 * @since 2.0.2
	 */
	public function render_field_packages_cache() {
		$purge_url = wp_nonce_url(
			add_query_arg( 'satispress_action', 'purge_packages_cache', menu_page_url( 'satispress', false ) ),
			'satispress_purge_packages_cache'
		);

		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_satispress_hash_%'" );
		?>
		<p>
			<a href="<?php echo esc_url( $purge_url ); ?>" class="button"><?php esc_html_e( 'Purge Packages Cache', 'satispress' ); ?></a>
		</p>
		<p class="description">
			<?php esc_html_e( 'Clears the cached file checksums.', 'satispress' ); ?><br>
			<?php printf( esc_html__( 'Cached checksums: %d', 'satispress' ), $count ); ?>
		</p>
		<?php
	}

	/**
	 * Display a field for enabling the purge feature.
	 *
	 * @since 2.1.0
	 */
	public function render_field_enable_purge() {
		$value = $this->get_setting( 'enable_purge', 'no' );
		?>
		<p>
			<label>
				<input type="checkbox" name="satispress[enable_purge]" value="yes" <?php checked( $value, 'yes' ); ?>>
				<?php esc_html_e( 'Enable automatic purging of old releases', 'satispress' ); ?>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'Keeps only the 3 last versions, 3 last bugfix versions, 3 last minor versions, and 3 major versions.', 'satispress' ); ?>
		</p>
		<?php
	}

	/**
	 * Retrieve a setting.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Optional. Default setting value.
	 * @return mixed
	 */
	protected function get_setting( string $key, $default = null ) {
		$option = get_option( 'satispress' );

		return $option[ $key ] ?? $default;
	}
}
