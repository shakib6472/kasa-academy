<?php
/**
 * Admin UI for the organisation link.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picks an organisation for a partner, and for a group.
 *
 * Both ends are a select drawn from the same list of organisations, because
 * both ends have to agree exactly. Anything typed rather than chosen is a
 * partner who silently sees nothing, which looks like a broken account rather
 * than a mistyped field.
 *
 * Organisations themselves are created on their own screen, which appears in
 * the LearnDash menu as Organisations. Both fields link to it, so nobody has
 * to go looking for where the list comes from.
 */
class Kasa_Organisation_Admin {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_user_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_user_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_field' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_group_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_group_field' ), 10, 2 );

		add_filter( 'manage_users_columns', array( __CLASS__, 'add_users_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_users_column' ), 10, 3 );
	}

	/**
	 * Only an administrator sets these.
	 *
	 * A partner must never be able to change their own organisation, since
	 * that is the whole of what limits them to their own children.
	 *
	 * @return bool
	 */
	private static function current_user_may_edit() {
		return current_user_can( 'kasa_manage_academy' );
	}

	/**
	 * Where organisations are created.
	 *
	 * @return string
	 */
	private static function manage_url() {
		return admin_url( Kasa_Organisation::admin_url() );
	}

	/**
	 * The select, shared by both screens.
	 *
	 * @param string $name     Field name.
	 * @param int    $selected Currently selected term ID.
	 * @param bool   $enabled  Whether the field may be changed.
	 * @return void
	 */
	private static function render_select( $name, $selected, $enabled ) {
		$organisations = Kasa_Organisation::all();

		if ( empty( $organisations ) ) {
			printf(
				'<p><em>%s</em> <a href="%s">%s</a></p>',
				esc_html__( 'No organisations exist yet.', 'kasa-academy' ),
				esc_url( self::manage_url() ),
				esc_html__( 'Add the first one', 'kasa-academy' )
			);

			return;
		}

		printf(
			'<select name="%s" id="%s" %s>',
			esc_attr( $name ),
			esc_attr( $name ),
			$enabled ? '' : 'disabled'
		);

		printf(
			'<option value="0">%s</option>',
			esc_html__( '— none —', 'kasa-academy' )
		);

		foreach ( $organisations as $organisation ) {
			printf(
				'<option value="%d" %s>%s</option>',
				absint( $organisation->term_id ),
				selected( absint( $selected ), absint( $organisation->term_id ), false ),
				esc_html( $organisation->name )
			);
		}

		echo '</select>';
	}

	/**
	 * A little Kasa colour, so these read as ours.
	 *
	 * Taken from the Elementor global kit, which is the site chrome palette,
	 * and deliberately not the per-course --kasa-* variables.
	 *
	 * @return void
	 */
	private static function render_styles() {
		static $done = false;

		if ( $done ) {
			return;
		}

		$done = true;
		?>
		<style>
			.kasa-org-field { border-left: 3px solid #9CAF88; padding-left: 12px; }
			.kasa-org-warning {
				color: #6b4c00;
				background: #FDF6E7;
				border-left: 3px solid #E8A93C;
				padding: 8px 12px;
				margin-top: 8px;
			}
		</style>
		<?php
	}

	/**
	 * The organisation field on a user profile.
	 *
	 * @param WP_User $user User being edited.
	 * @return void
	 */
	public static function render_user_field( $user ) {
		if ( ! self::current_user_may_edit() ) {
			return;
		}

		$selected   = Kasa_Scope::organisation_id( $user->ID );
		$is_partner = user_can( $user->ID, 'kasa_view_org_cohorts' );

		self::render_styles();
		?>
		<h2><?php esc_html_e( 'Kasa Academy', 'kasa-academy' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr class="kasa-org-field">
				<th><label for="kasa_organisation_id"><?php esc_html_e( 'Organisation', 'kasa-academy' ); ?></label></th>
				<td>
					<?php wp_nonce_field( 'kasa_save_organisation', 'kasa_organisation_nonce' ); ?>
					<?php self::render_select( 'kasa_organisation_id', $selected, true ); ?>
					<p class="description">
						<?php esc_html_e( 'An Implementation Partner sees only the groups belonging to this organisation, and no others.', 'kasa-academy' ); ?>
						<a href="<?php echo esc_url( self::manage_url() ); ?>"><?php esc_html_e( 'Manage organisations', 'kasa-academy' ); ?></a>
					</p>
					<?php if ( $is_partner && ! $selected ) : ?>
						<p class="kasa-org-warning">
							<?php esc_html_e( 'This partner has no organisation, so they can currently see no groups and no learners at all. That is the safe default rather than an error, but their account does nothing until an organisation is chosen.', 'kasa-academy' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the profile field.
	 *
	 * @param int $user_id User being saved.
	 * @return void
	 */
	public static function save_user_field( $user_id ) {
		if ( ! self::current_user_may_edit() || ! isset( $_POST['kasa_organisation_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['kasa_organisation_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'kasa_save_organisation' ) ) {
			return;
		}

		$term_id = isset( $_POST['kasa_organisation_id'] ) ? absint( wp_unslash( $_POST['kasa_organisation_id'] ) ) : 0;

		// Never store an organisation that does not exist. Scoping would treat
		// it as unset anyway, but a stale value in the database invites
		// somebody to trust it later.
		if ( ! $term_id || ! Kasa_Organisation::exists( $term_id ) ) {
			delete_user_meta( $user_id, Kasa_Scope::ORGANISATION_META );
		} else {
			update_user_meta( $user_id, Kasa_Scope::ORGANISATION_META, $term_id );
		}

		Kasa_Scope::flush_cache( $user_id );
	}

	/**
	 * Add the organisation box to a group.
	 *
	 * The taxonomy's own box is switched off in Kasa_Organisation, so this is
	 * the only one. A group belongs to one organisation, and the default box
	 * would allow several.
	 *
	 * @return void
	 */
	public static function add_group_meta_box() {
		if ( ! function_exists( 'learndash_get_post_type_slug' ) ) {
			return;
		}

		add_meta_box(
			'kasa-organisation',
			__( 'Kasa organisation', 'kasa-academy' ),
			array( __CLASS__, 'render_group_field' ),
			learndash_get_post_type_slug( 'group' ),
			'side',
			'default'
		);
	}

	/**
	 * The organisation field on a group.
	 *
	 * @param WP_Post $post Group being edited.
	 * @return void
	 */
	public static function render_group_field( $post ) {
		self::render_styles();

		wp_nonce_field( 'kasa_save_group_organisation', 'kasa_group_organisation_nonce' );
		?>
		<div class="kasa-org-field">
			<?php self::render_select( 'kasa_organisation_id', Kasa_Organisation::for_group( $post->ID ), self::current_user_may_edit() ); ?>
			<p class="description">
				<?php esc_html_e( 'Leave this as none unless the group belongs to a partner organisation. None means no partner can reach the group, which is the safe default.', 'kasa-academy' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Save the group field.
	 *
	 * @param int     $post_id Post being saved.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save_group_field( $post_id, $post ) {
		if ( ! function_exists( 'learndash_get_post_type_slug' ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post || learndash_get_post_type_slug( 'group' ) !== $post->post_type ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! self::current_user_may_edit() || ! isset( $_POST['kasa_group_organisation_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['kasa_group_organisation_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'kasa_save_group_organisation' ) ) {
			return;
		}

		$term_id = isset( $_POST['kasa_organisation_id'] ) ? absint( wp_unslash( $_POST['kasa_organisation_id'] ) ) : 0;

		Kasa_Organisation::set_for_group( $post_id, $term_id );

		Kasa_Scope::flush_cache();
	}

	/**
	 * Show the organisation on the users list.
	 *
	 * A partner with the wrong organisation, or none, is the failure that is
	 * hardest to spot from the outside, so it is worth being visible without
	 * opening each profile.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_users_column( $columns ) {
		if ( current_user_can( 'kasa_manage_academy' ) ) {
			$columns['kasa_organisation'] = __( 'Organisation', 'kasa-academy' );
		}

		return $columns;
	}

	/**
	 * Fill the users list column.
	 *
	 * @param string $output      Existing output.
	 * @param string $column_name Column being rendered.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public static function render_users_column( $output, $column_name, $user_id ) {
		if ( 'kasa_organisation' !== $column_name ) {
			return $output;
		}

		$term_id = Kasa_Scope::organisation_id( $user_id );

		if ( $term_id ) {
			return esc_html( Kasa_Organisation::name( $term_id ) );
		}

		if ( user_can( $user_id, 'kasa_view_org_cohorts' ) ) {
			return '<span style="color:#b32d2e">' . esc_html__( 'none, sees nothing', 'kasa-academy' ) . '</span>';
		}

		return '<span aria-hidden="true">—</span>';
	}
}
