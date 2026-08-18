<?php
/**
 * The Kasa Academy admin menu.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A home of our own in wp-admin.
 *
 * Everything this plugin adds lives under one top-level menu rather than being
 * scattered through LearnDash's. Organisations sat in the LearnDash menu while
 * it was the only screen we had; with the permissions matrix joining it, and
 * applications and seasons due in later milestones, borrowing another plugin's
 * menu would blur which plugin owns what.
 */
class Kasa_Admin_Menu {

	/**
	 * Top level menu slug.
	 *
	 * @var string
	 */
	const SLUG = 'kasa-academy';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_kasa_download_matrix', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_kasa_write_matrix', array( __CLASS__, 'handle_write' ) );
		add_action( 'admin_init', array( __CLASS__, 'refresh_file_when_stale' ), 30 );
	}

	/**
	 * Option recording which roles version the file on disk describes.
	 *
	 * @var string
	 */
	const FILE_VERSION_OPTION = 'kasa_academy_matrix_file_version';

	/**
	 * Regenerate PERMISSIONS.md when the capability map has moved on.
	 *
	 * A generated document that somebody has to remember to regenerate is a
	 * hand-written document with extra steps. Tying it to KASA_ROLES_VERSION,
	 * which has to change anyway for a capability change to reach the
	 * database, means the file is refreshed by the same act that changes the
	 * permissions.
	 *
	 * Failure is not reported. A read-only plugin directory is a sensible way
	 * to run a production site, and the screen still offers the download.
	 *
	 * @return void
	 */
	public static function refresh_file_when_stale() {
		if ( get_option( self::FILE_VERSION_OPTION ) === KASA_ROLES_VERSION && file_exists( Kasa_Permissions_Matrix::file_path() ) ) {
			return;
		}

		if ( Kasa_Permissions_Matrix::write_file() ) {
			update_option( self::FILE_VERSION_OPTION, KASA_ROLES_VERSION );
		}
	}

	/**
	 * Build the menu.
	 *
	 * @return void
	 */
	public static function register() {
		// The top level entry is the export, because that is the one screen
		// here a facilitator or a partner may open. Hanging the menu off an
		// administrator only capability would hide the whole thing from them,
		// export included.
		add_menu_page(
			__( 'Kasa Academy', 'kasa-academy' ),
			__( 'Kasa Academy', 'kasa-academy' ),
			'kasa_export_group_progress',
			Kasa_Export::SLUG,
			array( 'Kasa_Export', 'render_screen' ),
			'dashicons-groups',
			3
		);

		add_submenu_page(
			Kasa_Export::SLUG,
			__( 'Progress export', 'kasa-academy' ),
			__( 'Progress export', 'kasa-academy' ),
			'kasa_export_group_progress',
			Kasa_Export::SLUG,
			array( 'Kasa_Export', 'render_screen' )
		);

		add_submenu_page(
			Kasa_Export::SLUG,
			__( 'Permissions matrix', 'kasa-academy' ),
			__( 'Permissions matrix', 'kasa-academy' ),
			'kasa_manage_academy',
			self::SLUG,
			array( __CLASS__, 'render_matrix_screen' )
		);

		// The taxonomy screen is a WordPress page, not one of ours, so it is
		// added by URL rather than with a callback.
		add_submenu_page(
			Kasa_Export::SLUG,
			__( 'Organisations', 'kasa-academy' ),
			__( 'Organisations', 'kasa-academy' ),
			'kasa_manage_academy',
			Kasa_Organisation::admin_url()
		);
	}

	/**
	 * Send the generated matrix as a download.
	 *
	 * @return void
	 */
	public static function handle_download() {
		if ( ! current_user_can( 'kasa_manage_academy' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'kasa-academy' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'kasa_matrix' );

		$markdown = Kasa_Permissions_Matrix::to_markdown();

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="kasa-academy-permissions.md"' );
		header( 'Content-Length: ' . strlen( $markdown ) );

		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text file body.
		exit;
	}

	/**
	 * Write the matrix beside the plugin.
	 *
	 * @return void
	 */
	public static function handle_write() {
		if ( ! current_user_can( 'kasa_manage_academy' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'kasa-academy' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'kasa_matrix' );

		$written = Kasa_Permissions_Matrix::write_file();

		wp_safe_redirect(
			add_query_arg(
				'kasa_written',
				$written ? '1' : '0',
				admin_url( 'admin.php?page=' . self::SLUG )
			)
		);

		exit;
	}

	/**
	 * Colours from the Elementor global kit, which is the site chrome palette.
	 *
	 * Not the per-course --kasa-* variables, which every course overrides for
	 * itself and which would make this screen change colour depending on which
	 * course was edited last.
	 *
	 * @return void
	 */
	private static function render_styles() {
		?>
		<style>
			.kasa-screen { --sage:#9CAF88; --gold:#E8A93C; --cream:#F2F8F4; --ink:#203F2F; }
			.kasa-screen .kasa-lede {
				border-left:4px solid var(--sage);
				background:var(--cream);
				color:var(--ink);
				padding:14px 18px;
				margin:16px 0 24px;
				max-width:900px;
			}
			.kasa-screen h2 {
				color:var(--ink);
				border-bottom:2px solid var(--sage);
				padding-bottom:6px;
				margin-top:34px;
			}
			.kasa-matrix { border-collapse:collapse; margin-bottom:8px; max-width:1000px; }
			.kasa-matrix th, .kasa-matrix td {
				border:1px solid #dcdcde;
				padding:7px 12px;
				text-align:left;
				vertical-align:top;
			}
			.kasa-matrix thead th { background:var(--ink); color:#fff; font-weight:600; }
			.kasa-matrix tbody tr:nth-child(even) { background:#fafafa; }
			.kasa-matrix td.kasa-yes, .kasa-matrix td.kasa-no { text-align:center; width:120px; }
			.kasa-matrix td.kasa-yes { color:#1f6b3a; font-weight:600; }
			.kasa-matrix td.kasa-no  { color:#b0b0b0; }
			.kasa-matrix code { background:transparent; padding:0; font-size:11px; color:#666; }
			.kasa-note { color:#50575e; max-width:900px; margin:4px 0 0 18px; }
			.kasa-actions { margin:20px 0 8px; }
			.kasa-source {
				background:#1d2327; color:#e6e6e6; padding:16px; overflow:auto;
				max-height:420px; max-width:1000px; font-size:12px; line-height:1.6;
			}
		</style>
		<?php
	}

	/**
	 * The permissions matrix screen.
	 *
	 * @return void
	 */
	public static function render_matrix_screen() {
		if ( ! current_user_can( 'kasa_manage_academy' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'kasa-academy' ), '', array( 'response' => 403 ) );
		}

		$capabilities = kasa_academy_capability_definitions();
		$roles        = kasa_academy_role_definitions();

		self::render_styles();
		?>
		<div class="wrap kasa-screen">
			<h1><?php esc_html_e( 'Permissions matrix', 'kasa-academy' ); ?></h1>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a redirect result, not acting on it.
			if ( isset( $_GET['kasa_written'] ) ) {
				$ok = '1' === sanitize_text_field( wp_unslash( $_GET['kasa_written'] ) );
				printf(
					'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
					$ok ? 'success' : 'error',
					$ok
						? esc_html(
							sprintf(
								/* translators: %s: file path. */
								__( 'Written to %s', 'kasa-academy' ),
								str_replace( ABSPATH, '', Kasa_Permissions_Matrix::file_path() )
							)
						)
						: esc_html__( 'Could not write the file. The plugin directory is not writable, which is normal on a locked down server. Use Download instead.', 'kasa-academy' )
				);
			}
			?>

			<div class="kasa-lede">
				<p style="margin:0 0 8px">
					<strong><?php esc_html_e( 'This page is generated from the code.', 'kasa-academy' ); ?></strong>
					<?php esc_html_e( 'It is built from the same capability map the role installer reads, so it cannot describe permissions the plugin does not actually enforce. A permissions document written by hand is correct on the day it is written and quietly wrong afterwards.', 'kasa-academy' ); ?>
				</p>
				<p style="margin:0">
					<?php
					printf(
						/* translators: 1: plugin version, 2: roles version. */
						esc_html__( 'Plugin version %1$s, roles version %2$s. To change anything here, edit the capability map and raise KASA_ROLES_VERSION.', 'kasa-academy' ),
						esc_html( KASA_ACADEMY_VERSION ),
						esc_html( KASA_ROLES_VERSION )
					);
					?>
				</p>
			</div>

			<h2><?php esc_html_e( 'The four roles', 'kasa-academy' ); ?></h2>
			<table class="kasa-matrix">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Role', 'kasa-academy' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'kasa-academy' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'kasa-academy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $roles as $slug => $role ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $role['display_name'] ); ?></strong></td>
							<td><code><?php echo esc_html( $slug ); ?></code></td>
							<td><?php echo esc_html( $role['description'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$groups = array();

			foreach ( $capabilities as $definition ) {
				if ( ! in_array( $definition['group'], $groups, true ) ) {
					$groups[] = $definition['group'];
				}
			}

			foreach ( $groups as $group ) :
				?>
				<h2><?php echo esc_html( $group ); ?></h2>
				<table class="kasa-matrix">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Capability', 'kasa-academy' ); ?></th>
							<?php foreach ( $roles as $role ) : ?>
								<th style="text-align:center"><?php echo esc_html( $role['display_name'] ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $capabilities as $capability => $definition ) :
							if ( $definition['group'] !== $group ) {
								continue;
							}
							?>
							<tr>
								<td>
									<?php echo esc_html( $definition['label'] ); ?><br>
									<code><?php echo esc_html( $capability ); ?></code>
								</td>
								<?php
								foreach ( $roles as $role ) :
									$has = in_array( $capability, $role['caps'], true );
									?>
									<td class="<?php echo $has ? 'kasa-yes' : 'kasa-no'; ?>">
										<?php echo $has ? esc_html__( 'Yes', 'kasa-academy' ) : '—'; ?>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php
				foreach ( $capabilities as $definition ) :
					if ( $definition['group'] !== $group || empty( $definition['description'] ) ) {
						continue;
					}
					?>
					<p class="kasa-note">
						<strong><?php echo esc_html( $definition['label'] ); ?></strong> —
						<?php echo esc_html( $definition['description'] ); ?>
					</p>
					<?php
				endforeach;
			endforeach;
			?>

			<h2><?php esc_html_e( 'The document', 'kasa-academy' ); ?></h2>
			<p><?php esc_html_e( 'The same content as a markdown file, for handing to the client.', 'kasa-academy' ); ?></p>

			<div class="kasa-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<?php wp_nonce_field( 'kasa_matrix' ); ?>
					<input type="hidden" name="action" value="kasa_download_matrix">
					<?php submit_button( __( 'Download markdown', 'kasa-academy' ), 'primary', 'submit', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:6px">
					<?php wp_nonce_field( 'kasa_matrix' ); ?>
					<input type="hidden" name="action" value="kasa_write_matrix">
					<?php
					submit_button(
						sprintf(
							/* translators: %s: file name. */
							__( 'Write %s', 'kasa-academy' ),
							'PERMISSIONS.md'
						),
						'secondary',
						'submit',
						false
					);
					?>
				</form>
			</div>

			<pre class="kasa-source"><?php echo esc_html( Kasa_Permissions_Matrix::to_markdown() ); ?></pre>
		</div>
		<?php
	}
}
