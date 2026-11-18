<?php
/**
 * The Instagram page in the admin menu: approve new posts, choose the posts
 * on the website, log in with Instagram (or paste a token), sync on demand,
 * settings, status, admin notices and the Dashboard widget.
 *
 * @package SHD\InstagramFeed
 */

namespace SHD\InstagramFeed;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
final class Admin {

	const PAGE = Connector::PAGE;

	const CAPABILITY = Access::CAP;

	/** Posts shown as thumbnails in the Dashboard widget. */
	const DASHBOARD_THUMBS = 6;

	/**
	 * Register admin hooks.
	 */
	public static function init(): void {
		add_action( 'admin_init', array( Access::class, 'install_role' ) );
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_menu', array( self::class, 'limit_menu' ), 999 );
		add_action( 'admin_page_access_denied', array( self::class, 'redirect_old_url' ) );
		add_action( 'load-index.php', array( self::class, 'redirect_dashboard' ) );
		add_action( 'wp_dashboard_setup', array( self::class, 'add_dashboard_widget' ) );
		add_action( 'admin_post_shd_igf_oauth_start', array( self::class, 'handle_oauth_start' ) );
		add_action( 'admin_post_shd_igf_connect', array( self::class, 'handle_connect' ) );
		add_action( 'admin_post_shd_igf_disconnect', array( self::class, 'handle_disconnect' ) );
		add_action( 'admin_post_shd_igf_sync', array( self::class, 'handle_sync' ) );
		add_action( 'admin_post_shd_igf_review', array( self::class, 'handle_review' ) );
		add_action( 'admin_post_shd_igf_moderation', array( self::class, 'handle_moderation' ) );
		add_action( 'admin_post_shd_igf_settings', array( self::class, 'handle_settings' ) );
		add_action( 'admin_notices', array( self::class, 'connection_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * "Instagram" in the admin menu, with the number of posts waiting for
	 * approval like the Comments menu.
	 */
	public static function add_page(): void {
		$awaiting = current_user_can( self::CAPABILITY ) ? Feed::counts()['awaiting'] : 0;
		$title    = esc_html__( 'Instagram', 'shd-instagram-feed' );

		if ( $awaiting ) {
			$title .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
				(int) $awaiting,
				esc_html( number_format_i18n( $awaiting ) ),
				esc_html(
					sprintf(
						/* translators: %s: number of posts. */
						_n( '%s post waiting for approval', '%s posts waiting for approval', $awaiting, 'shd-instagram-feed' ),
						number_format_i18n( $awaiting )
					)
				)
			);
		}

		$hook = add_menu_page(
			__( 'Instagram Feed', 'shd-instagram-feed' ),
			$title,
			self::CAPABILITY,
			self::PAGE,
			array( self::class, 'render_page' ),
			'dashicons-instagram',
			26
		);

		if ( $hook ) {
			// Self-heal options and the cron event whenever someone opens the page.
			add_action( 'load-' . $hook, array( Options::class, 'install' ) );
			add_action( 'load-' . $hook, array( Sync::class, 'schedule' ) );
		}
	}

	/**
	 * Instagram managers only need this page: hide the Dashboard (their
	 * profile stays available).
	 */
	public static function limit_menu(): void {
		if ( Access::is_limited() ) {
			remove_menu_page( 'index.php' );
		}
	}

	/**
	 * Instagram managers land on this page instead of the Dashboard, e.g.
	 * right after logging in.
	 */
	public static function redirect_dashboard(): void {
		if ( Access::is_limited() ) {
			wp_safe_redirect( Connector::page_url() );
			exit;
		}
	}

	/**
	 * Up to 0.5.0 the page was under Settings: forward old links and bookmarks.
	 */
	public static function redirect_old_url(): void {
		global $pagenow;

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only a redirect.
		if ( 'options-general.php' === $pagenow && self::PAGE === $page && current_user_can( self::CAPABILITY ) ) {
			wp_safe_redirect( Connector::page_url() );
			exit;
		}
	}

	/**
	 * Link to the page from the plugins list.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public static function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( Connector::page_url() ), esc_html__( 'Settings', 'shd-instagram-feed' ) )
		);
		return $links;
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
	 * Approve a post, hide it, or approve all posts that were listed.
	 */
	public static function handle_review(): void {
		self::verify_request( 'shd_igf_review' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce and capability checked in verify_request().
		$approve = isset( $_POST['shd_igf_approve'] ) ? sanitize_text_field( wp_unslash( $_POST['shd_igf_approve'] ) ) : '';
		$reject  = isset( $_POST['shd_igf_reject'] ) ? sanitize_text_field( wp_unslash( $_POST['shd_igf_reject'] ) ) : '';
		$all     = ! empty( $_POST['shd_igf_approve_all'] );
		$listed  = isset( $_POST['shd_igf_listed'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['shd_igf_listed'] ) ) : array();
		// phpcs:enable

		$in_feed = Options::feed_ids();

		if ( $all ) {
			// Only posts the user saw: a post that arrived meanwhile keeps waiting.
			$ids = array_values( array_filter( array_intersect( $in_feed, $listed ), array( Options::class, 'is_awaiting' ) ) );
		} else {
			$ids = array_values( array_intersect( $in_feed, array( '' !== $approve ? $approve : $reject ) ) );
		}

		if ( ! $ids ) {
			self::redirect( 'warning', __( 'This post is no longer in the feed. Nothing was changed.', 'shd-instagram-feed' ) );
		}

		if ( $all || '' !== $approve ) {
			Options::set_visibility( $ids );
			self::redirect(
				'success',
				1 === count( $ids )
					? __( 'Approved. The post is now shown on the website.', 'shd-instagram-feed' )
					: sprintf(
						/* translators: %d: number of posts. */
						_n( '%d post approved. It is now shown on the website.', '%d posts approved. They are now shown on the website.', count( $ids ), 'shd-instagram-feed' ),
						count( $ids )
					)
			);
		}

		Options::set_visibility( array(), $ids );
		self::redirect( 'success', __( 'Done. The post is not shown on the website. You can still show it under “Posts on the website”.', 'shd-instagram-feed' ) );
	}

	/**
	 * Save which of the listed posts appear on the website: ticked posts are
	 * shown. Posts that wait for approval are not part of this form.
	 */
	public static function handle_moderation(): void {
		self::verify_request( 'shd_igf_moderation' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce and capability checked in verify_request().
		$listed = isset( $_POST['shd_igf_listed'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['shd_igf_listed'] ) ) : array();
		$ticked = isset( $_POST['shd_igf_visible'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['shd_igf_visible'] ) ) : array();
		// phpcs:enable

		$listed = array_values( array_intersect( Options::feed_ids(), $listed ) );
		$ticked = array_values( array_intersect( $listed, $ticked ) );

		Options::set_visibility( $ticked, array_diff( $listed, $ticked ) );

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
	 * Save the settings: approval of new posts, the email for the current
	 * user, posts to keep, sync interval.
	 */
	public static function handle_settings(): void {
		self::verify_request( 'shd_igf_settings' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce and capability checked in verify_request().
		$approval = ! isset( $_POST['shd_igf_new_posts'] ) || 'show' !== sanitize_key( wp_unslash( $_POST['shd_igf_new_posts'] ) );
		$notify   = ! empty( $_POST['shd_igf_notify'] );
		$settings = Options::sanitize_settings(
			array(
				'keep'     => isset( $_POST['shd_igf_keep'] ) ? absint( wp_unslash( $_POST['shd_igf_keep'] ) ) : null,
				'interval' => isset( $_POST['shd_igf_interval'] ) ? sanitize_key( wp_unslash( $_POST['shd_igf_interval'] ) ) : null,
			)
		);
		// phpcs:enable

		Options::set_approval_required( $approval );
		Notifier::set_wants_email( get_current_user_id(), $notify );
		update_option( Options::SETTINGS, $settings, false );
		Sync::schedule();

		self::redirect( 'success', __( 'Settings saved.', 'shd-instagram-feed' ) );
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
	 * Show a message on the page after a redirect.
	 *
	 * @param string $type    `success`, `warning` or `error`.
	 * @param string $message Message.
	 */
	private static function redirect( string $type, string $message ): void {
		Connector::notify( get_current_user_id(), $type, $message );
		wp_safe_redirect( Connector::page_url() );
		exit;
	}

	/**
	 * Warn the people who manage the feed when it can no longer update.
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
			esc_url( Connector::page_url() ),
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
	 * Render the page.
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
				.shd-igf-admin .form-table th { width: 170px; }
				.shd-igf-admin .form-table td > p.description { margin-top: 2px; }
				.shd-igf-admin fieldset p.description { margin: 0 0 8px 24px; }
				.shd-igf-admin__count { display: inline-block; box-sizing: border-box; min-width: 20px; margin-left: 4px; padding: 0 6px; border-radius: 10px; background: #d63638; color: #fff; font-size: 12px; font-weight: 600; line-height: 20px; text-align: center; vertical-align: 1px; }
				.shd-igf-admin__review { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 24px 16px; margin: 16px 0; padding: 0; list-style: none; }
				.shd-igf-admin__review li { display: flex; flex-direction: column; gap: 6px; margin: 0; }
				.shd-igf-admin__review img { display: block; width: 100%; height: auto; aspect-ratio: 1; object-fit: cover; border-radius: 4px; }
				.shd-igf-admin__review p { margin: 0; }
				.shd-igf-admin__caption { display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden; }
				.shd-igf-admin__review-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: auto !important; padding-top: 4px; }
				.shd-igf-admin__bulk { margin: 16px 0 8px; }
				.shd-igf-admin__posts { display: grid; grid-template-columns: repeat(auto-fill, minmax(112px, 1fr)); gap: 12px; margin: 0 0 16px; padding: 0; list-style: none; }
				.shd-igf-admin__posts li { position: relative; margin: 0; }
				.shd-igf-admin__posts label { display: block; cursor: pointer; }
				.shd-igf-admin__posts input { position: absolute; top: 6px; left: 6px; z-index: 1; margin: 0; }
				.shd-igf-admin__posts img { display: block; width: 100%; height: auto; aspect-ratio: 1; object-fit: cover; border-radius: 4px; box-shadow: 0 0 0 3px #2271b1; transition: opacity .15s, filter .15s, box-shadow .15s; }
				.shd-igf-admin__posts input:not(:checked) ~ img { opacity: .35; filter: grayscale(1); box-shadow: 0 0 0 1px #c3c4c7; }
				.shd-igf-admin__posts input:focus-visible ~ img { outline: 2px solid #1d2327; outline-offset: 4px; }
				.shd-igf-admin__post-meta { display: block; margin-top: 6px; font-size: 12px; line-height: 1.4; color: #50575e; }
				.shd-igf-admin__review .shd-igf-admin__post-meta { margin: 0; }
				.shd-igf-admin__people { margin: 1em 0; }
				.shd-igf-admin__people li { margin-bottom: 4px; }
			</style>

			<?php
			self::render_review_card();
			self::render_posts_card();
			self::render_account_card();
			self::render_feed_card();
			self::render_settings_card();
			self::render_access_card();
			self::render_service_card();
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
	 * Card: new posts that wait for approval.
	 */
	private static function render_review_card(): void {
		$items = Feed::awaiting_items();
		if ( ! $items ) {
			return;
		}

		$count = count( $items );
		?>
		<div class="card" id="shd-igf-review">
			<h2>
				<?php esc_html_e( 'Waiting for approval', 'shd-instagram-feed' ); ?>
				<span class="shd-igf-admin__count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
			</h2>
			<p><?php echo esc_html( _n( 'This post is new on Instagram. It only appears on the website after you approve it.', 'These posts are new on Instagram. They only appear on the website after you approve them.', $count, 'shd-instagram-feed' ) ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shd_igf_review">
				<?php wp_nonce_field( 'shd_igf_review' ); ?>

				<ul class="shd-igf-admin__review">
					<?php foreach ( $items as $item ) : ?>
						<?php
						$id      = (string) $item['id'];
						$meta_id = 'shd-igf-review-' . $id;
						$summary = Feed::summary( $item, 300 );
						?>
						<li>
							<input type="hidden" name="shd_igf_listed[]" value="<?php echo esc_attr( $id ); ?>">
							<?php echo wp_get_attachment_image( (int) $item['attachment_id'], 'medium', false, array( 'loading' => 'lazy' ) ); ?>
							<p class="shd-igf-admin__post-meta" id="<?php echo esc_attr( $meta_id ); ?>"><?php echo esc_html( self::post_meta( $item ) ); ?></p>
							<?php if ( '' !== $summary ) : ?>
								<p class="shd-igf-admin__caption"><?php echo esc_html( $summary ); ?></p>
							<?php endif; ?>
							<p><a href="<?php echo esc_url( $item['permalink'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View on Instagram', 'shd-instagram-feed' ); ?></a></p>
							<p class="shd-igf-admin__review-actions">
								<button type="submit" class="button button-primary" name="shd_igf_approve" value="<?php echo esc_attr( $id ); ?>" aria-describedby="<?php echo esc_attr( $meta_id ); ?>"><?php esc_html_e( 'Approve', 'shd-instagram-feed' ); ?></button>
								<button type="submit" class="button" name="shd_igf_reject" value="<?php echo esc_attr( $id ); ?>" aria-describedby="<?php echo esc_attr( $meta_id ); ?>"><?php esc_html_e( 'Don’t show', 'shd-instagram-feed' ); ?></button>
							</p>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php if ( $count > 1 ) : ?>
					<p>
						<button type="submit" class="button button-primary" name="shd_igf_approve_all" value="1">
							<?php
							/* translators: %d: number of posts (always more than one). */
							echo esc_html( sprintf( _n( 'Approve %d post', 'Approve all %d posts', $count, 'shd-instagram-feed' ), $count ) );
							?>
						</button>
					</p>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Card: choose which of the reviewed posts appear on the website.
	 */
	private static function render_posts_card(): void {
		if ( ! Options::get_feed()['items'] ) {
			return;
		}

		$items   = array();
		$visible = 0;
		foreach ( Options::get_feed()['items'] as $item ) {
			if ( is_array( $item ) && ! empty( $item['id'] ) && ! Options::is_awaiting( (string) $item['id'] ) ) {
				$items[] = $item;
				$visible = Options::is_visible( (string) $item['id'] ) ? $visible + 1 : $visible;
			}
		}
		?>
		<div class="card" id="shd-igf-posts">
			<h2><?php esc_html_e( 'Posts on the website', 'shd-instagram-feed' ); ?></h2>

			<?php if ( ! $items ) : ?>
				<p><?php esc_html_e( 'No post is approved yet. Approve the posts that should appear on the website above.', 'shd-instagram-feed' ); ?></p>
			<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: 1: number of posts shown, 2: number of posts. */
					esc_html( _n( 'Ticked posts appear in the Instagram Feed widget. %1$d of %2$d post is shown.', 'Ticked posts appear in the Instagram Feed widget. %1$d of %2$d posts are shown.', count( $items ), 'shd-instagram-feed' ) ),
					(int) $visible,
					(int) count( $items )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shd_igf_moderation">
				<?php wp_nonce_field( 'shd_igf_moderation' ); ?>

				<p class="shd-igf-admin__bulk">
					<button type="button" class="button-link" data-shd-igf-check="1"><?php esc_html_e( 'Tick all', 'shd-instagram-feed' ); ?></button>
					&middot;
					<button type="button" class="button-link" data-shd-igf-check="0"><?php esc_html_e( 'Untick all', 'shd-instagram-feed' ); ?></button>
				</p>

				<ul class="shd-igf-admin__posts">
					<?php foreach ( $items as $item ) : ?>
						<?php $id = (string) $item['id']; ?>
						<li>
							<input type="hidden" name="shd_igf_listed[]" value="<?php echo esc_attr( $id ); ?>">
							<label>
								<input type="checkbox" name="shd_igf_visible[]" value="<?php echo esc_attr( $id ); ?>"<?php checked( Options::is_visible( $id ) ); ?>>
								<?php echo wp_get_attachment_image( (int) $item['attachment_id'], 'thumbnail', false, array( 'loading' => 'lazy' ) ); ?>
								<span class="shd-igf-admin__post-meta"><?php echo esc_html( self::post_meta( $item ) ); ?></span>
							</label>
							<a class="shd-igf-admin__post-meta" href="<?php echo esc_url( $item['permalink'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View on Instagram', 'shd-instagram-feed' ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php submit_button( __( 'Save selection', 'shd-instagram-feed' ), 'primary', 'submit', false ); ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * "12 November 2026 · Album".
	 *
	 * @param array $item Post.
	 * @return string
	 */
	private static function post_meta( array $item ): string {
		$labels = array(
			'video' => __( 'Video', 'shd-instagram-feed' ),
			'album' => __( 'Album', 'shd-instagram-feed' ),
		);
		$parts  = array();

		if ( ! empty( $item['timestamp'] ) ) {
			$parts[] = wp_date( (string) get_option( 'date_format' ), (int) $item['timestamp'] );
		}
		if ( isset( $item['type'], $labels[ $item['type'] ] ) ) {
			$parts[] = $labels[ $item['type'] ];
		}
		return implode( ' · ', $parts );
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
		if ( ! Connect_Service::is_enabled() || ! current_user_can( 'manage_options' ) ) {
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
				<?php if ( Options::requires_approval() ) : ?>
					<dt><?php esc_html_e( 'Waiting for approval', 'shd-instagram-feed' ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( $counts['awaiting'] ) ); ?></dd>
				<?php endif; ?>
				<dt><?php esc_html_e( 'Last update', 'shd-instagram-feed' ); ?></dt>
				<dd><?php echo esc_html( $status['last_success'] ? self::relative_time( $status['last_success'] ) : __( 'Never', 'shd-instagram-feed' ) ); ?></dd>
				<dt><?php esc_html_e( 'Next check', 'shd-instagram-feed' ); ?></dt>
				<dd><?php echo esc_html( $next_run ? self::relative_time( $next_run ) : __( 'Not scheduled', 'shd-instagram-feed' ) ); ?></dd>
				<?php if ( $status['pending'] ) : ?>
					<dt><?php esc_html_e( 'Images', 'shd-instagram-feed' ); ?></dt>
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
			<?php if ( current_user_can( 'edit_pages' ) ) : ?>
				<p class="description"><?php esc_html_e( 'Then add the “Instagram Feed” widget to a page in Elementor.', 'shd-instagram-feed' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Card: approval of new posts, email, posts to keep, sync interval.
	 */
	private static function render_settings_card(): void {
		$settings = Options::get_settings();
		$approval = Options::requires_approval();
		$user     = wp_get_current_user();
		$labels   = array(
			'hourly'     => __( 'Every hour', 'shd-instagram-feed' ),
			'twicedaily' => __( 'Twice a day', 'shd-instagram-feed' ),
			'daily'      => __( 'Once a day', 'shd-instagram-feed' ),
		);
		?>
		<div class="card">
			<h2><?php esc_html_e( 'Settings', 'shd-instagram-feed' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shd_igf_settings">
				<?php wp_nonce_field( 'shd_igf_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'New posts from Instagram', 'shd-instagram-feed' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'New posts from Instagram', 'shd-instagram-feed' ); ?></legend>
								<label>
									<input type="radio" name="shd_igf_new_posts" value="hide"<?php checked( $approval ); ?>>
									<?php esc_html_e( 'Wait for approval (recommended)', 'shd-instagram-feed' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'New posts only appear on the website after someone approves them on this page. Approved posts stay on the website even when they get older.', 'shd-instagram-feed' ); ?></p>
								<label>
									<input type="radio" name="shd_igf_new_posts" value="show"<?php checked( ! $approval ); ?>>
									<?php esc_html_e( 'Show automatically', 'shd-instagram-feed' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'New posts appear on the website right away. You can still hide single posts.', 'shd-instagram-feed' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email', 'shd-instagram-feed' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="shd_igf_notify" value="1"<?php checked( Notifier::wants_email( $user->ID ) ); ?>>
								<?php esc_html_e( 'Email me when new posts are waiting', 'shd-instagram-feed' ); ?>
							</label>
							<p class="description">
								<?php
								printf(
									/* translators: %s: email address. */
									esc_html__( 'Sent to %s when new posts are waiting for approval. This setting only applies to you.', 'shd-instagram-feed' ),
									esc_html( $user->user_email )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="shd-igf-keep"><?php esc_html_e( 'Posts to keep', 'shd-instagram-feed' ); ?></label></th>
						<td>
							<input type="number" id="shd-igf-keep" name="shd_igf_keep" value="<?php echo (int) $settings['keep']; ?>" min="1" max="<?php echo (int) Options::MAX_POSTS; ?>" step="1" class="small-text">
							<p class="description"><?php esc_html_e( 'The newest posts are kept with their images; older images are deleted automatically. Approved posts are kept in addition.', 'shd-instagram-feed' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="shd-igf-interval"><?php esc_html_e( 'Check for new posts', 'shd-instagram-feed' ); ?></label></th>
						<td>
							<select id="shd-igf-interval" name="shd_igf_interval">
								<?php foreach ( $labels as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $settings['interval'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'shd-instagram-feed' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Card for administrators: who can manage Instagram.
	 */
	private static function render_access_card(): void {
		if ( ! current_user_can( 'list_users' ) ) {
			return;
		}

		$managers = get_users(
			array(
				'role'    => Access::ROLE,
				'orderby' => 'display_name',
			)
		);
		?>
		<div class="card" id="shd-igf-access">
			<h2><?php esc_html_e( 'Who can manage Instagram', 'shd-instagram-feed' ); ?></h2>
			<p><?php esc_html_e( 'Administrators, and users with the role “Instagram Manager”. Instagram managers only see this page and their own profile, nothing else of the website. They approve new posts, can log in to Instagram and get an email when new posts are waiting.', 'shd-instagram-feed' ); ?></p>

			<?php if ( $managers ) : ?>
				<ul class="shd-igf-admin__people">
					<?php foreach ( $managers as $manager ) : ?>
						<?php $edit = get_edit_user_link( $manager->ID ); ?>
						<li>
							<?php if ( $edit ) : ?>
								<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $manager->display_name ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $manager->display_name ); ?>
							<?php endif; ?>
							&lt;<?php echo esc_html( $manager->user_email ); ?>&gt; –
							<?php echo esc_html( Notifier::wants_email( $manager->ID ) ? __( 'gets emails', 'shd-instagram-feed' ) : __( 'no emails', 'shd-instagram-feed' ) ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><em><?php esc_html_e( 'Nobody has this role yet.', 'shd-instagram-feed' ); ?></em></p>
			<?php endif; ?>

			<?php if ( current_user_can( 'create_users' ) ) : ?>
				<p class="shd-igf-admin__actions">
					<a class="button" href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>"><?php esc_html_e( 'Add user', 'shd-instagram-feed' ); ?></a>
					<span class="description"><?php esc_html_e( 'Choose the role “Instagram Manager”. For an existing user, change the role under Users.', 'shd-instagram-feed' ); ?></span>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Dashboard widget for everyone who manages the feed.
	 */
	public static function add_dashboard_widget(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		wp_add_dashboard_widget( 'shd_igf_dashboard', __( 'Instagram', 'shd-instagram-feed' ), array( self::class, 'render_dashboard_widget' ) );
	}

	/**
	 * Dashboard widget: posts waiting for approval, feed status.
	 */
	public static function render_dashboard_widget(): void {
		$connection = Options::get_connection();
		$counts     = Feed::counts();
		$status     = Options::get_status();
		$url        = Connector::page_url();

		if ( '' === $connection['token'] && ! $counts['total'] ) {
			?>
			<p><?php esc_html_e( 'No Instagram account is connected yet.', 'shd-instagram-feed' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Connect Instagram', 'shd-instagram-feed' ); ?></a></p>
			<?php
			return;
		}

		$awaiting = Feed::awaiting_items();
		?>
		<style>
			.shd-igf-dash__thumbs { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0 12px; padding: 0; list-style: none; }
			.shd-igf-dash__thumbs li { margin: 0; }
			.shd-igf-dash__thumbs img { display: block; width: 56px; height: 56px; object-fit: cover; border-radius: 3px; }
			.shd-igf-dash__meta { color: #50575e; }
		</style>
		<?php if ( $awaiting ) : ?>
			<p>
				<strong>
					<?php
					/* translators: %d: number of posts. */
					echo esc_html( sprintf( _n( '%d new post is waiting for approval.', '%d new posts are waiting for approval.', count( $awaiting ), 'shd-instagram-feed' ), count( $awaiting ) ) );
					?>
				</strong>
				<?php echo esc_html( _n( 'It appears on the website once it is approved.', 'They appear on the website once they are approved.', count( $awaiting ), 'shd-instagram-feed' ) ); ?>
			</p>
			<ul class="shd-igf-dash__thumbs">
				<?php foreach ( array_slice( $awaiting, 0, self::DASHBOARD_THUMBS ) as $item ) : ?>
					<li><?php echo wp_get_attachment_image( (int) $item['attachment_id'], 'thumbnail', false, array( 'loading' => 'lazy' ) ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p><a class="button button-primary" href="<?php echo esc_url( $url . '#shd-igf-review' ); ?>"><?php esc_html_e( 'Review posts', 'shd-instagram-feed' ); ?></a></p>
		<?php elseif ( Options::requires_approval() ) : ?>
			<p><?php esc_html_e( 'No new posts are waiting for approval.', 'shd-instagram-feed' ); ?></p>
		<?php endif; ?>

		<p class="shd-igf-dash__meta">
			<?php
			$parts = array(
				sprintf(
					/* translators: %d: number of posts. */
					_n( '%d post on the website', '%d posts on the website', $counts['visible'], 'shd-instagram-feed' ),
					$counts['visible']
				),
			);
			if ( '' !== $connection['username'] ) {
				array_unshift( $parts, '@' . $connection['username'] );
			}
			if ( $status['last_success'] ) {
				/* translators: %s: e.g. "5 mins ago". */
				$parts[] = sprintf( __( 'last update %s', 'shd-instagram-feed' ), self::relative_time( $status['last_success'] ) );
			}
			echo esc_html( implode( ' · ', $parts ) );
			?>
			· <a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Manage', 'shd-instagram-feed' ); ?></a>
		</p>
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
