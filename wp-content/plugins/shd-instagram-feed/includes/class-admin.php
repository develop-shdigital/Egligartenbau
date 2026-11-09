<?php
/**
 * Settings page: log in with Instagram (or paste a token), Instagram app
 * setup, sync on demand, settings, status and admin notices.
 *
 * @package SHD\InstagramFeed
 */

namespace SHD\InstagramFeed;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
final class Admin {

	const PAGE = 'shd-instagram-feed';

	const CAPABILITY = 'manage_options';

	const SETTINGS_GROUP = 'shd_igf';

	/**
	 * Register admin hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_post_shd_igf_oauth_start', array( self::class, 'handle_oauth_start' ) );
		add_action( 'admin_post_shd_igf_connect', array( self::class, 'handle_connect' ) );
		add_action( 'admin_post_shd_igf_disconnect', array( self::class, 'handle_disconnect' ) );
		add_action( 'admin_post_shd_igf_sync', array( self::class, 'handle_sync' ) );
		add_action( 'admin_post_shd_igf_moderation', array( self::class, 'handle_moderation' ) );
		add_action( 'add_option_' . Options::SETTINGS, array( Sync::class, 'schedule' ), 10, 0 );
		add_action( 'update_option_' . Options::SETTINGS, array( Sync::class, 'schedule' ), 10, 0 );
		add_action( 'admin_notices', array( self::class, 'connection_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Settings → Instagram Feed.
	 */
	public static function add_page(): void {
		$hook = add_options_page(
			__( 'Instagram Feed', 'shd-instagram-feed' ),
			__( 'Instagram Feed', 'shd-instagram-feed' ),
			self::CAPABILITY,
			self::PAGE,
			array( self::class, 'render_page' )
		);

		if ( $hook ) {
			// Self-heal options and the cron event whenever someone opens the page.
			add_action( 'load-' . $hook, array( Options::class, 'install' ) );
			add_action( 'load-' . $hook, array( Sync::class, 'schedule' ) );
		}
	}

	/**
	 * Link to the settings page from the plugins list.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public static function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( Connector::settings_url() ), esc_html__( 'Settings', 'shd-instagram-feed' ) )
		);
		return $links;
	}

	/**
	 * Settings API registration for the feed settings.
	 */
	public static function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			Options::SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Options::class, 'sanitize_settings' ),
				'default'           => Options::default_settings(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section( 'shd_igf_feed', '', '__return_false', self::PAGE );

		add_settings_field(
			'shd_igf_keep',
			__( 'Posts to keep', 'shd-instagram-feed' ),
			array( self::class, 'field_keep' ),
			self::PAGE,
			'shd_igf_feed',
			array( 'label_for' => 'shd-igf-keep' )
		);

		add_settings_field(
			'shd_igf_interval',
			__( 'Check for new posts', 'shd-instagram-feed' ),
			array( self::class, 'field_interval' ),
			self::PAGE,
			'shd_igf_feed',
			array( 'label_for' => 'shd-igf-interval' )
		);
	}

	/**
	 * Number of posts to keep.
	 */
	public static function field_keep(): void {
		$settings = Options::get_settings();
		printf(
			'<input type="number" id="shd-igf-keep" name="%1$s[keep]" value="%2$d" min="1" max="%3$d" step="1" class="small-text"> <p class="description">%4$s</p>',
			esc_attr( Options::SETTINGS ),
			(int) $settings['keep'],
			(int) Options::MAX_POSTS,
			esc_html__( 'The newest posts are kept with their images; older images are deleted automatically. When only ticked posts are shown, ticked posts are kept in addition.', 'shd-instagram-feed' )
		);
	}

	/**
	 * Sync interval.
	 */
	public static function field_interval(): void {
		$settings = Options::get_settings();
		$labels   = array(
			'hourly'     => __( 'Every hour', 'shd-instagram-feed' ),
			'twicedaily' => __( 'Twice a day', 'shd-instagram-feed' ),
			'daily'      => __( 'Once a day', 'shd-instagram-feed' ),
		);

		printf( '<select id="shd-igf-interval" name="%s[interval]">', esc_attr( Options::SETTINGS ) );
		foreach ( $labels as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $settings['interval'], $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * "Login to Instagram": send the browser to the login service, which
	 * forwards it to Instagram's login.
	 */
	public static function handle_oauth_start(): void {
		self::verify_request( 'shd_igf_oauth_start' );

		$reconnect = ! empty( $_POST['shd_igf_reconnect'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and capability checked in verify_request().
		$url       = Connector::login_url( get_current_user_id(), $reconnect );

		if ( is_wp_error( $url ) ) {
			self::redirect( 'error', $url->get_error_message() );
		}

		add_filter( 'allowed_redirect_hosts', array( self::class, 'allow_connect_redirect' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Allow the redirect to the login service.
	 *
	 * @param array $hosts Allowed hosts.
	 * @return array
	 */
	public static function allow_connect_redirect( array $hosts ): array {
		$hosts[] = (string) wp_parse_url( Connector::connect_url(), PHP_URL_HOST );
		return $hosts;
	}

	/**
	 * Save a pasted access token after checking it with Instagram, then run
	 * the first sync so the widget has posts right away.
	 */
	public static function handle_connect(): void {
		self::verify_request( 'shd_igf_connect' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and capability checked in verify_request().
		$token = isset( $_POST['shd_igf_token'] ) ? Sync::clean_token( sanitize_text_field( wp_unslash( $_POST['shd_igf_token'] ) ) ) : '';
		if ( '' === $token ) {
			self::redirect( 'error', __( 'Please paste an access token.', 'shd-instagram-feed' ) );
		}

		list( $type, $message ) = Connector::connect( $token );
		self::redirect( $type, $message );
	}

	/**
	 * Remove the token; optionally delete the cached posts and images.
	 */
	public static function handle_disconnect(): void {
		self::verify_request( 'shd_igf_disconnect' );

		Options::clear_connection();
		Sync::unschedule();

		if ( ! empty( $_POST['shd_igf_delete_cache'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and capability checked in verify_request().
			self::delete_cache();
			self::redirect( 'success', __( 'Disconnected. The cached posts and images were deleted.', 'shd-instagram-feed' ) );
		}

		self::redirect( 'success', __( 'Disconnected. The widget keeps showing the cached posts until you connect again.', 'shd-instagram-feed' ) );
	}

	/**
	 * Sync now.
	 */
	public static function handle_sync(): void {
		self::verify_request( 'shd_igf_sync' );

		list( $type, $message ) = Connector::sync_message( Sync::run() );
		self::redirect( $type, $message );
	}

	/**
	 * Save which posts appear on the website: ticked posts are shown.
	 */
	public static function handle_moderation(): void {
		self::verify_request( 'shd_igf_moderation' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce and capability checked in verify_request().
		$new_posts = isset( $_POST['shd_igf_new_posts'] ) && 'hide' === sanitize_key( wp_unslash( $_POST['shd_igf_new_posts'] ) ) ? 'hide' : 'show';
		$ticked    = isset( $_POST['shd_igf_visible'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['shd_igf_visible'] ) ) : array();
		// phpcs:enable

		$all    = array_map( 'strval', wp_list_pluck( Options::get_feed()['items'], 'id' ) );
		$ticked = array_values( array_intersect( $all, $ticked ) );

		Options::save_moderation(
			array(
				'new_posts' => $new_posts,
				'selected'  => $ticked,
				'hidden'    => array_values( array_diff( $all, $ticked ) ),
			)
		);

		$counts = Feed::counts();
		self::redirect(
			'success',
			sprintf(
				/* translators: 1: number of posts shown, 2: number of posts. */
				_n( 'Saved. %1$d of %2$d post is shown on the website.', 'Saved. %1$d of %2$d posts are shown on the website.', $counts['total'], 'shd-instagram-feed' ),
				$counts['visible'],
				$counts['total']
			)
		);
	}

	/**
	 * Delete all cached posts and the images this plugin imported.
	 */
	private static function delete_cache(): void {
		foreach ( Importer::attachments() as $row ) {
			Importer::delete( $row['attachment_id'] );
		}
		Options::save_feed(
			array(
				'updated_at' => 0,
				'username'   => '',
				'items'      => array(),
			)
		);
		Options::update_status(
			array(
				'pending'  => 0,
				'imported' => 0,
			)
		);
	}

	/**
	 * Nonce and capability check for admin-post actions.
	 *
	 * @param string $action Nonce action.
	 */
	private static function verify_request( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the Instagram feed.', 'shd-instagram-feed' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Show a message on the settings page after a redirect.
	 *
	 * @param string $type    `success`, `warning` or `error`.
	 * @param string $message Message.
	 */
	private static function redirect( string $type, string $message ): void {
		Connector::notify( get_current_user_id(), $type, $message );
		wp_safe_redirect( Connector::settings_url() );
		exit;
	}

	/**
	 * Warn administrators when the feed can no longer update.
	 */
	public static function connection_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( self::CAPABILITY ) || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}

		$problem = self::connection_problem();
		if ( '' === $problem ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Instagram Feed:', 'shd-instagram-feed' ),
			esc_html( $problem ),
			esc_url( Connector::settings_url() ),
			esc_html__( 'Open settings', 'shd-instagram-feed' )
		);
	}

	/**
	 * Description of a problem that stops the feed from updating, if any.
	 *
	 * @return string
	 */
	private static function connection_problem(): string {
		$connection = Options::get_connection();
		if ( '' === $connection['token'] ) {
			return '';
		}

		if ( '' !== $connection['token_error'] ) {
			return sprintf(
				/* translators: %s: error message from Instagram. */
				__( 'Instagram no longer accepts the access token (%s). Click “Login to Instagram” again. Until then the site keeps showing the last synced posts.', 'shd-instagram-feed' ),
				$connection['token_error']
			);
		}

		if ( $connection['expires_at'] && $connection['expires_at'] - time() < 7 * DAY_IN_SECONDS ) {
			return sprintf(
				/* translators: %s: date. */
				__( 'The access token expires on %s and could not be renewed automatically. Click “Login to Instagram” again.', 'shd-instagram-feed' ),
				wp_date( get_option( 'date_format' ), $connection['expires_at'] )
			);
		}

		$status = Options::get_status();
		if ( $status['last_success'] && time() - $status['last_success'] > 3 * DAY_IN_SECONDS && '' !== $status['last_error'] ) {
			return sprintf(
				/* translators: 1: date, 2: error message. */
				__( 'The feed has not updated since %1$s. Last error: %2$s', 'shd-instagram-feed' ),
				wp_date( get_option( 'date_format' ), $status['last_success'] ),
				$status['last_error']
			);
		}

		return '';
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$notice  = get_transient( 'shd_igf_notice_' . get_current_user_id() );
		$expired = isset( $_GET['shd_igf_login'] ) && 'expired' === sanitize_key( wp_unslash( $_GET['shd_igf_login'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects a message.
		$problem = self::connection_problem();

		if ( $notice ) {
			delete_transient( 'shd_igf_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap shd-igf-admin">
			<h1><?php esc_html_e( 'Instagram Feed', 'shd-instagram-feed' ); ?></h1>

			<?php if ( is_array( $notice ) && ! empty( $notice['message'] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( in_array( $notice['type'], array( 'success', 'warning', 'error' ), true ) ? $notice['type'] : 'info' ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $expired ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'The Instagram login link has expired or was already used. Please click “Login to Instagram” again.', 'shd-instagram-feed' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $problem ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $problem ); ?></p></div>
			<?php endif; ?>

			<style>
				.shd-igf-admin .card { max-width: 760px; }
				.shd-igf-admin dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 1em 0; }
				.shd-igf-admin dt { font-weight: 600; }
				.shd-igf-admin dd { margin: 0; }
				.shd-igf-admin__actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 1em 0; }
				.shd-igf-admin__actions form { margin: 0; }
				.shd-igf-admin details { margin: 1.5em 0 0; color: #50575e; }
				.shd-igf-admin summary { cursor: pointer; }
				.shd-igf-admin ol li { margin-bottom: 8px; }
				.shd-igf-admin fieldset label { display: inline-block; margin: 4px 0; }
				.shd-igf-admin__bulk { margin: 16px 0 8px; }
				.shd-igf-admin__posts { display: grid; grid-template-columns: repeat(auto-fill, minmax(112px, 1fr)); gap: 12px; margin: 0 0 16px; padding: 0; list-style: none; }
				.shd-igf-admin__posts li { position: relative; margin: 0; }
				.shd-igf-admin__posts label { display: block; cursor: pointer; }
				.shd-igf-admin__posts input { position: absolute; top: 6px; left: 6px; z-index: 1; margin: 0; }
				.shd-igf-admin__posts img { display: block; width: 100%; height: auto; aspect-ratio: 1; object-fit: cover; border-radius: 4px; box-shadow: 0 0 0 3px #2271b1; transition: opacity .15s, filter .15s, box-shadow .15s; }
				.shd-igf-admin__posts input:not(:checked) ~ img { opacity: .35; filter: grayscale(1); box-shadow: 0 0 0 1px #c3c4c7; }
				.shd-igf-admin__posts input:focus-visible ~ img { outline: 2px solid #1d2327; outline-offset: 4px; }
				.shd-igf-admin__post-meta { display: block; margin-top: 6px; font-size: 12px; line-height: 1.4; color: #50575e; }
			</style>

			<?php
			self::render_account_card();
			self::render_service_card();
			self::render_feed_card();
			self::render_posts_card();
			self::render_settings_card();
			?>
		</div>
		<script>
			document.addEventListener( 'click', function ( event ) {
				var button = event.target.closest && event.target.closest( '[data-shd-igf-check]' );
				if ( ! button ) {
					return;
				}
				var on = '1' === button.getAttribute( 'data-shd-igf-check' );
				document.querySelectorAll( '.shd-igf-admin__posts input[type=checkbox]' ).forEach( function ( box ) {
					box.checked = on;
				} );
			} );
		</script>
		<?php
	}

	/**
	 * Card: the "Login to Instagram" button, or the connected account.
	 */
	private static function render_account_card(): void {
		$connection = Options::get_connection();
		$connected  = '' !== $connection['token'];
		$post_url   = admin_url( 'admin-post.php' );
		?>
		<div class="card">
			<h2><?php esc_html_e( 'Instagram account', 'shd-instagram-feed' ); ?></h2>

			<?php if ( $connected ) : ?>
				<dl>
					<dt><?php esc_html_e( 'Account', 'shd-instagram-feed' ); ?></dt>
					<dd>
						<a href="<?php echo esc_url( 'https://www.instagram.com/' . rawurlencode( $connection['username'] ) . '/' ); ?>" target="_blank" rel="noopener">@<?php echo esc_html( $connection['username'] ); ?></a>
						<?php if ( '' !== $connection['account_type'] ) : ?>
							(<?php echo esc_html( ucwords( strtolower( str_replace( '_', ' ', $connection['account_type'] ) ) ) ); ?>)
						<?php endif; ?>
					</dd>
					<dt><?php esc_html_e( 'Access token', 'shd-instagram-feed' ); ?></dt>
					<dd>
						<?php
						if ( '' !== $connection['token_error'] ) {
							esc_html_e( 'Not valid any more', 'shd-instagram-feed' );
						} else {
							printf(
								/* translators: %s: date. */
								esc_html__( 'Valid until %s, renewed automatically every week', 'shd-instagram-feed' ),
								esc_html( wp_date( get_option( 'date_format' ), $connection['expires_at'] ) )
							);
						}
						?>
					</dd>
				</dl>
			<?php else : ?>
				<p><?php esc_html_e( 'Connect the Instagram account whose posts should appear on the website.', 'shd-instagram-feed' ); ?></p>
			<?php endif; ?>

			<div class="shd-igf-admin__actions">
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<input type="hidden" name="action" value="shd_igf_oauth_start">
					<?php wp_nonce_field( 'shd_igf_oauth_start' ); ?>
					<?php if ( $connected ) : ?>
						<input type="hidden" name="shd_igf_reconnect" value="1">
					<?php endif; ?>
					<?php submit_button( $connected ? __( 'Login to Instagram again', 'shd-instagram-feed' ) : __( 'Login to Instagram', 'shd-instagram-feed' ), $connected ? 'secondary' : 'primary hero', 'submit', false ); ?>
				</form>

				<?php if ( $connected ) : ?>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="shd-igf-admin__actions">
						<input type="hidden" name="action" value="shd_igf_disconnect">
						<?php wp_nonce_field( 'shd_igf_disconnect' ); ?>
						<?php submit_button( __( 'Disconnect', 'shd-instagram-feed' ), 'delete', 'submit', false ); ?>
						<label><input type="checkbox" name="shd_igf_delete_cache" value="1"> <?php esc_html_e( 'Also delete the cached posts and images', 'shd-instagram-feed' ); ?></label>
					</form>
				<?php endif; ?>
			</div>

			<details>
				<summary><?php esc_html_e( 'Advanced: connect with an access token', 'shd-instagram-feed' ); ?></summary>
				<p><?php esc_html_e( 'Only needed if the login button cannot be used. Generate a token in the Meta App Dashboard (Instagram → “API setup with Instagram business login” → “Generate token”) and paste it here.', 'shd-instagram-feed' ); ?></p>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<input type="hidden" name="action" value="shd_igf_connect">
					<?php wp_nonce_field( 'shd_igf_connect' ); ?>
					<p>
						<label for="shd-igf-token"><?php esc_html_e( 'Access token', 'shd-instagram-feed' ); ?></label><br>
						<input type="password" id="shd-igf-token" name="shd_igf_token" class="large-text code" autocomplete="off" spellcheck="false" required>
					</p>
					<?php submit_button( __( 'Connect with token', 'shd-instagram-feed' ), 'secondary', 'submit', false ); ?>
				</form>
			</details>
		</div>
		<?php
	}

	/**
	 * Card on the website that provides the login for other sites.
	 */
	private static function render_service_card(): void {
		if ( ! Connect_Service::is_enabled() ) {
			return;
		}

		$hosts = Connect_Service::allowed_hosts();
		?>
		<div class="card">
			<h2><?php esc_html_e( 'Instagram login service', 'shd-instagram-feed' ); ?></h2>
			<p><?php esc_html_e( 'This website provides the “Login to Instagram” button for all websites that use the plugin, because the Instagram app ID and secret are defined in its wp-config.php.', 'shd-instagram-feed' ); ?></p>
			<dl>
				<dt><?php esc_html_e( 'OAuth redirect URI', 'shd-instagram-feed' ); ?></dt>
				<dd>
					<code><?php echo esc_html( Connect_Service::redirect_uri() ); ?></code><br>
					<span class="description"><?php esc_html_e( 'Enter exactly this address in the Meta app under Instagram → “API setup with Instagram business login” → “Business login settings” → “OAuth redirect URIs”.', 'shd-instagram-feed' ); ?></span>
				</dd>
				<dt><?php esc_html_e( 'Allowed websites', 'shd-instagram-feed' ); ?></dt>
				<dd><?php echo esc_html( $hosts ? implode( ', ', $hosts ) : __( 'All websites that use the plugin (https)', 'shd-instagram-feed' ) ); ?></dd>
			</dl>
		</div>
		<?php
	}

	/**
	 * Card: feed status, sync now.
	 */
	private static function render_feed_card(): void {
		$connection = Options::get_connection();
		$status     = Options::get_status();
		$counts     = Feed::counts();
		$next_run   = wp_next_scheduled( Sync::HOOK );
		?>
		<div class="card">
			<h2><?php esc_html_e( 'Feed', 'shd-instagram-feed' ); ?></h2>
			<dl>
				<dt><?php esc_html_e( 'Cached posts', 'shd-instagram-feed' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></dd>
				<dt><?php esc_html_e( 'Shown on the website', 'shd-instagram-feed' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $counts['visible'] ) ); ?></dd>
				<dt><?php esc_html_e( 'Last update', 'shd-instagram-feed' ); ?></dt>
				<dd><?php echo esc_html( $status['last_success'] ? self::relative_time( $status['last_success'] ) : __( 'Never', 'shd-instagram-feed' ) ); ?></dd>
				<dt><?php esc_html_e( 'Next check', 'shd-instagram-feed' ); ?></dt>
				<dd><?php echo esc_html( $next_run ? self::relative_time( $next_run ) : __( 'Not scheduled', 'shd-instagram-feed' ) ); ?></dd>
				<?php if ( $status['pending'] ) : ?>
					<dt><?php esc_html_e( 'Waiting', 'shd-instagram-feed' ); ?></dt>
					<dd>
						<?php
						/* translators: %d: number of images. */
						echo esc_html( sprintf( _n( '%d image is being imported in the background', '%d images are being imported in the background', $status['pending'], 'shd-instagram-feed' ), $status['pending'] ) );
						?>
					</dd>
				<?php endif; ?>
				<?php if ( '' !== $status['last_error'] ) : ?>
					<dt><?php esc_html_e( 'Last error', 'shd-instagram-feed' ); ?></dt>
					<dd><?php echo esc_html( $status['last_error'] ); ?> (<?php echo esc_html( self::relative_time( $status['last_error_at'] ) ); ?>)</dd>
				<?php endif; ?>
			</dl>

			<?php if ( '' !== $connection['token'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="shd_igf_sync">
					<?php wp_nonce_field( 'shd_igf_sync' ); ?>
					<?php submit_button( __( 'Check for new posts now', 'shd-instagram-feed' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Then add the “Instagram Feed” widget to a page in Elementor.', 'shd-instagram-feed' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Card: choose which posts appear on the website.
	 */
	private static function render_posts_card(): void {
		$feed = Options::get_feed();
		if ( ! $feed['items'] ) {
			return;
		}

		$moderation = Options::get_moderation();
		$counts     = Feed::counts();
		$labels     = array(
			'video' => __( 'Video', 'shd-instagram-feed' ),
			'album' => __( 'Album', 'shd-instagram-feed' ),
		);
		?>
		<div class="card">
			<h2><?php esc_html_e( 'Posts on the website', 'shd-instagram-feed' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: number of posts shown, 2: number of posts. */
					esc_html( _n( 'Ticked posts appear in the Instagram Feed widget. %1$d of %2$d post is shown.', 'Ticked posts appear in the Instagram Feed widget. %1$d of %2$d posts are shown.', $counts['total'], 'shd-instagram-feed' ) ),
					(int) $counts['visible'],
					(int) $counts['total']
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shd_igf_moderation">
				<?php wp_nonce_field( 'shd_igf_moderation' ); ?>

				<fieldset>
					<legend><strong><?php esc_html_e( 'New posts from Instagram', 'shd-instagram-feed' ); ?></strong></legend>
					<label>
						<input type="radio" name="shd_igf_new_posts" value="show"<?php checked( 'show', $moderation['new_posts'] ); ?>>
						<?php esc_html_e( 'Show automatically. You only untick the posts you don’t want.', 'shd-instagram-feed' ); ?>
					</label><br>
					<label>
						<input type="radio" name="shd_igf_new_posts" value="hide"<?php checked( 'hide', $moderation['new_posts'] ); ?>>
						<?php esc_html_e( 'Only show them once you tick them. Ticked posts stay on the website even when they get older.', 'shd-instagram-feed' ); ?>
					</label>
				</fieldset>

				<p class="shd-igf-admin__bulk">
					<button type="button" class="button-link" data-shd-igf-check="1"><?php esc_html_e( 'Tick all', 'shd-instagram-feed' ); ?></button>
					&middot;
					<button type="button" class="button-link" data-shd-igf-check="0"><?php esc_html_e( 'Untick all', 'shd-instagram-feed' ); ?></button>
				</p>

				<ul class="shd-igf-admin__posts">
					<?php foreach ( $feed['items'] as $item ) : ?>
						<?php
						if ( ! is_array( $item ) || empty( $item['id'] ) ) {
							continue;
						}
						$id   = (string) $item['id'];
						$type = isset( $item['type'], $labels[ $item['type'] ] ) ? $labels[ $item['type'] ] : '';
						$date = ! empty( $item['timestamp'] ) ? wp_date( get_option( 'date_format' ), (int) $item['timestamp'] ) : '';
						?>
						<li>
							<label>
								<input type="checkbox" name="shd_igf_visible[]" value="<?php echo esc_attr( $id ); ?>"<?php checked( Options::is_visible( $id ) ); ?>>
								<?php echo wp_get_attachment_image( (int) $item['attachment_id'], 'thumbnail', false, array( 'loading' => 'lazy' ) ); ?>
								<span class="shd-igf-admin__post-meta"><?php echo esc_html( trim( $date . ( '' !== $type ? ' · ' . $type : '' ) ) ); ?></span>
							</label>
							<a class="shd-igf-admin__post-meta" href="<?php echo esc_url( $item['permalink'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View on Instagram', 'shd-instagram-feed' ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php submit_button( __( 'Save selection', 'shd-instagram-feed' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Card: feed settings.
	 */
	private static function render_settings_card(): void {
		?>
		<div class="card">
			<h2><?php esc_html_e( 'Settings', 'shd-instagram-feed' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * "5 minutes ago" / "in 5 minutes".
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function relative_time( int $timestamp ): string {
		$diff = human_time_diff( $timestamp, time() );
		return $timestamp > time()
			/* translators: %s: time span, e.g. "5 mins". */
			? sprintf( __( 'in %s', 'shd-instagram-feed' ), $diff )
			/* translators: %s: time span, e.g. "5 mins". */
			: sprintf( __( '%s ago', 'shd-instagram-feed' ), $diff );
	}
}
