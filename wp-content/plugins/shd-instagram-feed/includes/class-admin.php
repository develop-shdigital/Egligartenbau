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

	const APP_GROUP = 'shd_igf_app';

	const META_APPS = 'https://developers.facebook.com/apps/';

	const LOGIN_DOCS = 'https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/business-login';

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
	 * Settings API registration: feed settings and Instagram app credentials
	 * (separate groups, because they are saved by separate forms).
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

		register_setting(
			self::APP_GROUP,
			Options::APP,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Options::class, 'sanitize_app' ),
				'default'           => array(),
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
			esc_html__( 'The newest posts are kept with their images. Older images are deleted automatically.', 'shd-instagram-feed' )
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
	 * "Log in with Instagram": send the browser to Instagram's login.
	 */
	public static function handle_oauth_start(): void {
		self::verify_request( 'shd_igf_oauth_start' );

		$reconnect = ! empty( $_POST['shd_igf_reconnect'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and capability checked in verify_request().
		$url       = Connector::authorize_url( get_current_user_id(), $reconnect );

		if ( is_wp_error( $url ) ) {
			self::redirect( 'error', $url->get_error_message() );
		}

		add_filter( 'allowed_redirect_hosts', array( self::class, 'allow_instagram_redirect' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Allow the redirect to Instagram's login page.
	 *
	 * @param array $hosts Allowed hosts.
	 * @return array
	 */
	public static function allow_instagram_redirect( array $hosts ): array {
		$hosts[] = 'www.instagram.com';
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
				__( 'Instagram no longer accepts the access token (%s). Log in with Instagram again. Until then the site keeps showing the last synced posts.', 'shd-instagram-feed' ),
				$connection['token_error']
			);
		}

		if ( $connection['expires_at'] && $connection['expires_at'] - time() < 7 * DAY_IN_SECONDS ) {
			return sprintf(
				/* translators: %s: date. */
				__( 'The access token expires on %s and could not be renewed automatically. Log in with Instagram again.', 'shd-instagram-feed' ),
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
					<p><?php esc_html_e( 'The Instagram login link has expired or was already used. Please click “Log in with Instagram” again.', 'shd-instagram-feed' ); ?></p>
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
				.shd-igf-admin__thumbs { display: flex; flex-wrap: wrap; gap: 6px; margin: 12px 0 4px; }
				.shd-igf-admin__thumbs img { display: block; width: 64px; height: 64px; object-fit: cover; border-radius: 2px; }
				.shd-igf-admin__actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 1em 0; }
				.shd-igf-admin__actions form { margin: 0; }
				.shd-igf-admin details { margin: 1em 0; }
				.shd-igf-admin summary { cursor: pointer; font-weight: 600; }
				.shd-igf-admin__copy { display: flex; gap: 8px; align-items: center; margin: 6px 0; }
				.shd-igf-admin__copy input { flex: 1; }
				.shd-igf-admin ol li { margin-bottom: 8px; }
			</style>

			<?php
			self::render_account_card();
			self::render_app_card();
			self::render_feed_card();
			self::render_settings_card();
			?>
		</div>
		<script>
			document.addEventListener( 'click', function ( event ) {
				var button = event.target.closest && event.target.closest( '[data-shd-igf-copy]' );
				var input = button && document.getElementById( button.getAttribute( 'data-shd-igf-copy' ) );
				if ( ! input ) {
					return;
				}
				var done = function () { button.textContent = button.getAttribute( 'data-copied' ); };
				input.select();
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( input.value ).then( done );
				} else {
					document.execCommand( 'copy' );
					done();
				}
			} );
		</script>
		<?php
	}

	/**
	 * Card: connected account, "Log in with Instagram", token fallback.
	 */
	private static function render_account_card(): void {
		$connection = Options::get_connection();
		$connected  = '' !== $connection['token'];
		$app_ready  = Options::app_is_configured();
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
			<?php elseif ( $app_ready ) : ?>
				<p><?php esc_html_e( 'Log in with the Instagram account whose posts should appear on the website. Instagram asks you to allow read access to the profile and posts. The plugin never posts anything.', 'shd-instagram-feed' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'To connect with one click, set up the Instagram app below once. Or connect with an access token.', 'shd-instagram-feed' ); ?></p>
			<?php endif; ?>

			<div class="shd-igf-admin__actions">
				<?php if ( $app_ready ) : ?>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>">
						<input type="hidden" name="action" value="shd_igf_oauth_start">
						<?php wp_nonce_field( 'shd_igf_oauth_start' ); ?>
						<?php if ( $connected ) : ?>
							<input type="hidden" name="shd_igf_reconnect" value="1">
						<?php endif; ?>
						<?php submit_button( $connected ? __( 'Reconnect with Instagram', 'shd-instagram-feed' ) : __( 'Log in with Instagram', 'shd-instagram-feed' ), $connected ? 'secondary' : 'primary large', 'submit', false ); ?>
					</form>
				<?php endif; ?>

				<?php if ( $connected ) : ?>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="shd-igf-admin__actions">
						<input type="hidden" name="action" value="shd_igf_disconnect">
						<?php wp_nonce_field( 'shd_igf_disconnect' ); ?>
						<?php submit_button( __( 'Disconnect', 'shd-instagram-feed' ), 'delete', 'submit', false ); ?>
						<label><input type="checkbox" name="shd_igf_delete_cache" value="1"> <?php esc_html_e( 'Also delete the cached posts and images', 'shd-instagram-feed' ); ?></label>
					</form>
				<?php endif; ?>
			</div>

			<details<?php echo ( ! $app_ready && ! $connected ) ? ' open' : ''; ?>>
				<summary><?php esc_html_e( 'Connect with an access token instead', 'shd-instagram-feed' ); ?></summary>
				<ol>
					<li><?php esc_html_e( 'Make sure the Instagram account is a professional account (Business or Creator). You can switch in the Instagram app under Settings → Account type and tools.', 'shd-instagram-feed' ); ?></li>
					<li><?php esc_html_e( 'In the Meta App Dashboard, open your app → Instagram → “API setup with Instagram business login”, add the Instagram account and click “Generate token”.', 'shd-instagram-feed' ); ?></li>
					<li><?php esc_html_e( 'Paste the token here. The plugin renews it automatically.', 'shd-instagram-feed' ); ?></li>
				</ol>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<input type="hidden" name="action" value="shd_igf_connect">
					<?php wp_nonce_field( 'shd_igf_connect' ); ?>
					<p>
						<label for="shd-igf-token"><strong><?php esc_html_e( 'Access token', 'shd-instagram-feed' ); ?></strong></label><br>
						<input type="password" id="shd-igf-token" name="shd_igf_token" class="large-text code" autocomplete="off" spellcheck="false" required>
					</p>
					<?php submit_button( $connected ? __( 'Replace token', 'shd-instagram-feed' ) : __( 'Connect with token', 'shd-instagram-feed' ), 'secondary', 'submit', false ); ?>
				</form>
			</details>
		</div>
		<?php
	}

	/**
	 * Card: Instagram app credentials and redirect URI for the login button.
	 */
	private static function render_app_card(): void {
		$app      = Options::get_app();
		$ready    = Options::app_is_configured();
		$redirect = Connector::redirect_uri();
		$secure   = 'https' === wp_parse_url( $redirect, PHP_URL_SCHEME );
		$stored   = get_option( Options::APP, array() );
		$saved    = is_array( $stored ) && ! empty( $stored['app_secret'] );
		?>
		<div class="card">
			<h2><?php esc_html_e( 'Instagram app', 'shd-instagram-feed' ); ?></h2>
			<p><?php esc_html_e( 'The login button uses an app in the Meta App Dashboard. You set it up once; the same app can be used for several websites.', 'shd-instagram-feed' ); ?></p>

			<p><strong><?php esc_html_e( 'OAuth redirect URI for this website', 'shd-instagram-feed' ); ?></strong></p>
			<div class="shd-igf-admin__copy">
				<input type="text" id="shd-igf-redirect" class="large-text code" value="<?php echo esc_attr( $redirect ); ?>" readonly>
				<button type="button" class="button" data-shd-igf-copy="shd-igf-redirect" data-copied="<?php esc_attr_e( 'Copied', 'shd-instagram-feed' ); ?>"><?php esc_html_e( 'Copy', 'shd-instagram-feed' ); ?></button>
			</div>
			<?php if ( ! $secure ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Instagram only redirects to secure (https) addresses. Switch the website to https before using the login button.', 'shd-instagram-feed' ); ?></p></div>
			<?php endif; ?>

			<details<?php echo $ready ? '' : ' open'; ?>>
				<summary><?php esc_html_e( 'Setup steps', 'shd-instagram-feed' ); ?></summary>
				<ol>
					<li>
						<?php
						printf(
							/* translators: %s: link to the Meta App Dashboard. */
							esc_html__( 'In the %s, create an app of the type “Business” with the Instagram use case, or open your existing app.', 'shd-instagram-feed' ),
							'<a href="' . esc_url( self::META_APPS ) . '" target="_blank" rel="noopener">' . esc_html__( 'Meta App Dashboard', 'shd-instagram-feed' ) . '</a>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Open Instagram → “API setup with Instagram business login” → “Set up Instagram business login” → “Business login settings” and add the redirect URI above under “OAuth redirect URIs”.', 'shd-instagram-feed' ); ?></li>
					<li><?php esc_html_e( 'Copy the “Instagram app ID” and “Instagram app secret” from the same page into the fields below and save.', 'shd-instagram-feed' ); ?></li>
					<li><?php esc_html_e( 'Add the Instagram account to the app (“Generate access tokens” → “Add account”). Accounts that are not added can only log in once Meta has granted the app Advanced Access (App Review).', 'shd-instagram-feed' ); ?></li>
				</ol>
				<p><a href="<?php echo esc_url( self::LOGIN_DOCS ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Meta’s guide: Business Login for Instagram', 'shd-instagram-feed' ); ?></a></p>
			</details>

			<?php if ( $app['id_constant'] && $app['secret_constant'] ) : ?>
				<p><?php esc_html_e( 'The app ID and secret are defined in wp-config.php.', 'shd-instagram-feed' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
					<?php settings_fields( self::APP_GROUP ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="shd-igf-app-id"><?php esc_html_e( 'Instagram app ID', 'shd-instagram-feed' ); ?></label></th>
							<td>
								<?php if ( $app['id_constant'] ) : ?>
									<?php esc_html_e( 'Defined in wp-config.php', 'shd-instagram-feed' ); ?>
								<?php else : ?>
									<input type="text" id="shd-igf-app-id" name="<?php echo esc_attr( Options::APP ); ?>[app_id]" value="<?php echo esc_attr( $app['app_id'] ); ?>" class="regular-text code" inputmode="numeric" autocomplete="off">
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="shd-igf-app-secret"><?php esc_html_e( 'Instagram app secret', 'shd-instagram-feed' ); ?></label></th>
							<td>
								<?php if ( $app['secret_constant'] ) : ?>
									<?php esc_html_e( 'Defined in wp-config.php', 'shd-instagram-feed' ); ?>
								<?php else : ?>
									<input type="password" id="shd-igf-app-secret" name="<?php echo esc_attr( Options::APP ); ?>[app_secret]" value="" class="regular-text code" autocomplete="new-password" spellcheck="false"<?php echo $saved ? ' placeholder="••••••••••••"' : ''; ?>>
									<p class="description">
										<?php
										if ( $saved ) {
											esc_html_e( 'Saved. Leave empty to keep it.', 'shd-instagram-feed' );
											echo ' ';
										}
										esc_html_e( 'To keep the secret out of the database, define SHD_IGF_APP_ID and SHD_IGF_APP_SECRET in wp-config.php instead.', 'shd-instagram-feed' );
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save app settings', 'shd-instagram-feed' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Card: feed status, preview, sync now.
	 */
	private static function render_feed_card(): void {
		$connection = Options::get_connection();
		$status     = Options::get_status();
		$feed       = Options::get_feed();
		$next_run   = wp_next_scheduled( Sync::HOOK );
		?>
		<div class="card">
			<h2><?php esc_html_e( 'Feed', 'shd-instagram-feed' ); ?></h2>
			<dl>
				<dt><?php esc_html_e( 'Cached posts', 'shd-instagram-feed' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( count( $feed['items'] ) ) ); ?></dd>
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

			<?php if ( $feed['items'] ) : ?>
				<div class="shd-igf-admin__thumbs">
					<?php foreach ( array_slice( $feed['items'], 0, 16 ) as $item ) : ?>
						<a href="<?php echo esc_url( $item['permalink'] ); ?>" target="_blank" rel="noopener">
							<?php echo wp_get_attachment_image( (int) $item['attachment_id'], 'thumbnail', false, array( 'loading' => 'lazy' ) ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

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
