<?php
/**
 * Settings page: connect the Instagram account, sync on demand, settings,
 * status and admin notices.
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

	const TOKEN_DOCS = 'https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/get-started';

	/**
	 * Register admin hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
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
			// Self-heal the cron event whenever someone opens the page.
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
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Settings', 'shd-instagram-feed' ) )
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

		$client  = new Api_Client( $token );
		$profile = $client->get_profile();

		if ( is_wp_error( $profile ) ) {
			self::redirect(
				'error',
				sprintf(
					/* translators: %s: error message from Instagram. */
					__( 'Instagram did not accept this token: %s', 'shd-instagram-feed' ),
					$profile->get_error_message()
				)
			);
		}

		$user_id = isset( $profile['user_id'] ) ? $profile['user_id'] : ( isset( $profile['id'] ) ? $profile['id'] : '' );

		Options::save_connection(
			array(
				'token'        => $token,
				'user_id'      => preg_replace( '/[^0-9A-Za-z_]/', '', (string) $user_id ),
				'username'     => preg_replace( '/[^A-Za-z0-9._]/', '', isset( $profile['username'] ) ? (string) $profile['username'] : '' ),
				'account_type' => sanitize_text_field( isset( $profile['account_type'] ) ? (string) $profile['account_type'] : '' ),
				'connected_at' => time(),
				'refreshed_at' => 0,
				// Tokens generated in the App Dashboard last 60 days at most.
				'expires_at'   => time() + 60 * DAY_IN_SECONDS,
				'token_error'  => '',
			)
		);
		Sync::schedule();

		$connection = Options::get_connection();
		$message    = sprintf(
			/* translators: %s: Instagram username. */
			__( 'Connected to @%s.', 'shd-instagram-feed' ),
			$connection['username']
		);

		list( $type, $summary ) = self::sync_message( Sync::run() );

		self::redirect( $type, $message . ' ' . $summary );
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

		list( $type, $message ) = self::sync_message( Sync::run() );
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
	 * Human-readable result of a sync.
	 *
	 * @param array|\WP_Error $result Result of Sync::run().
	 * @return array{0:string,1:string} Notice type and message.
	 */
	private static function sync_message( $result ): array {
		if ( is_wp_error( $result ) ) {
			return array(
				'error',
				sprintf(
					/* translators: %s: error message. */
					__( 'Sync failed: %s', 'shd-instagram-feed' ),
					$result->get_error_message()
				),
			);
		}

		$parts = array(
			sprintf(
				/* translators: %d: number of posts. */
				_n( '%d post is up to date.', '%d posts are up to date.', $result['fetched'], 'shd-instagram-feed' ),
				$result['fetched']
			),
		);

		if ( $result['imported'] ) {
			$parts[] = sprintf(
				/* translators: %d: number of images. */
				_n( '%d new image imported.', '%d new images imported.', $result['imported'], 'shd-instagram-feed' ),
				$result['imported']
			);
		}
		if ( $result['pending'] ) {
			$parts[] = sprintf(
				/* translators: %d: number of images. */
				_n( '%d more image will be imported in the background within a few minutes.', '%d more images will be imported in the background within a few minutes.', $result['pending'], 'shd-instagram-feed' ),
				$result['pending']
			);
		}
		if ( $result['failed'] ) {
			$parts[] = sprintf(
				/* translators: %d: number of images. */
				_n( '%d image could not be imported and will be retried.', '%d images could not be imported and will be retried.', $result['failed'], 'shd-instagram-feed' ),
				$result['failed']
			);
		}

		return array( $result['failed'] ? 'warning' : 'success', implode( ' ', $parts ) );
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
		set_transient(
			'shd_igf_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Settings page URL.
	 *
	 * @return string
	 */
	private static function page_url(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE );
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
			esc_url( self::page_url() ),
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
				__( 'Instagram no longer accepts the access token (%s). Generate a new token and connect again. Until then the site keeps showing the last synced posts.', 'shd-instagram-feed' ),
				$connection['token_error']
			);
		}

		if ( $connection['expires_at'] && $connection['expires_at'] - time() < 7 * DAY_IN_SECONDS ) {
			return sprintf(
				/* translators: %s: date. */
				__( 'The access token expires on %s and could not be renewed automatically. Generate a new token and connect again.', 'shd-instagram-feed' ),
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

		$connection = Options::get_connection();
		$status     = Options::get_status();
		$feed       = Options::get_feed();
		$connected  = '' !== $connection['token'];
		$notice     = get_transient( 'shd_igf_notice_' . get_current_user_id() );
		$problem    = self::connection_problem();
		$next_run   = wp_next_scheduled( Sync::HOOK );

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
				.shd-igf-admin__actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
				.shd-igf-admin__actions form { margin: 0; }
			</style>

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
					<p><?php esc_html_e( 'Connect an Instagram professional account (Business or Creator) by pasting an access token from the Meta App Dashboard. See the steps below.', 'shd-instagram-feed' ); ?></p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="shd_igf_connect">
					<?php wp_nonce_field( 'shd_igf_connect' ); ?>
					<p>
						<label for="shd-igf-token"><strong><?php echo $connected ? esc_html__( 'New access token', 'shd-instagram-feed' ) : esc_html__( 'Access token', 'shd-instagram-feed' ); ?></strong></label><br>
						<input type="password" id="shd-igf-token" name="shd_igf_token" class="large-text code" autocomplete="off" spellcheck="false" required>
					</p>
					<?php submit_button( $connected ? __( 'Replace token', 'shd-instagram-feed' ) : __( 'Connect account', 'shd-instagram-feed' ), $connected ? 'secondary' : 'primary', 'submit', false ); ?>
				</form>

				<?php if ( $connected ) : ?>
					<hr>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="shd-igf-admin__actions">
						<input type="hidden" name="action" value="shd_igf_disconnect">
						<?php wp_nonce_field( 'shd_igf_disconnect' ); ?>
						<label><input type="checkbox" name="shd_igf_delete_cache" value="1"> <?php esc_html_e( 'Also delete the cached posts and images', 'shd-instagram-feed' ); ?></label>
						<?php submit_button( __( 'Disconnect', 'shd-instagram-feed' ), 'delete', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>

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

				<?php if ( $connected ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="shd_igf_sync">
						<?php wp_nonce_field( 'shd_igf_sync' ); ?>
						<?php submit_button( __( 'Check for new posts now', 'shd-instagram-feed' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>

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

			<div class="card">
				<h2><?php esc_html_e( 'How to get an access token', 'shd-instagram-feed' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Make sure the Instagram account is a professional account (Business or Creator). You can switch in the Instagram app under Settings → Account type and tools.', 'shd-instagram-feed' ); ?></li>
					<li><?php esc_html_e( 'On developers.facebook.com, create an app of the type “Business” with the Instagram use case.', 'shd-instagram-feed' ); ?></li>
					<li><?php esc_html_e( 'In the app dashboard, open Instagram → “API setup with Instagram business login”, add the Instagram account and click “Generate token”.', 'shd-instagram-feed' ); ?></li>
					<li><?php esc_html_e( 'Copy the token, paste it above and click “Connect account”. The plugin renews the token automatically, so you only do this once.', 'shd-instagram-feed' ); ?></li>
				</ol>
				<p>
					<a href="<?php echo esc_url( self::TOKEN_DOCS ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Meta’s guide: Instagram API with Instagram Login', 'shd-instagram-feed' ); ?></a>
				</p>
				<p class="description"><?php esc_html_e( 'Then add the “Instagram Feed” widget to a page in Elementor.', 'shd-instagram-feed' ); ?></p>
			</div>
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
