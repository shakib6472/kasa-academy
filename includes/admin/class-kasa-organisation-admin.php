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
 * Lets an administrator set the organisation on a partner and on a group.
 *
 * The organisation link is what scopes a partner to its own cohorts, and until
 * this existed the only way to set it was in the database. Both ends have to
 * carry the same value or the partner sees nothing, so both fields offer the
 * organisations already in use rather than relying on anyone typing the same
 * string twice.
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
	}

	/**
	 * Only an administrator sets these.
	 *
	 * A partner must never be able to retype their own organisation, since
	 * that is the whole of what limits them to their own children.
	 *
	 * @return bool
	 */
	private static function current_user_may_edit() {
		return current_user_can( 'kasa_manage_academy' );
	}

	/**
	 * Organisations already in use, for the suggestion list.
	 *
	 * @return array
	 */
	private static function known_organisations() {
		global $wpdb;

		$key = Kasa_Scope::ORGANISATION_META;

		$from_users = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != ''", $key )
		);

		$from_groups = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''", $key )
		);

		$all = array_merge( (array) $from_users, (array) $from_groups );
		$all = array_filter( array_map( 'trim', $all ) );
		$all = array_unique( $all );

		sort( $all );

		return $all;
	}

	/**
	 * The shared suggestion list markup.
	 *
	 * @param string $id Datalist element ID.
	 * @return void
	 */
	private static function render_datalist( $id ) {
		$known = self::known_organisations();

		if ( empty( $known ) ) {
			return;
		}

		echo '<datalist id="' . esc_attr( $id ) . '">';
		foreach ( $known as $organisation ) {
			echo '<option value="' . esc_attr( $organisation ) . '"></option>';
		}
		echo '</datalist>';
	}

	/**
	 * A small amount of Kasa colour, so these fields read as ours.
	 *
	 * Values come from the Elementor global kit, which is the site chrome
	 * palette. Deliberately not the --kasa-* course variables, which every
	 * course overrides for itself.
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
			.kasa-org-field {
				border-left: 3px solid #9CAF88;
				padding-left: 12px;
			}
			.kasa-org-field .description code {
				background: #F2F8F4;
				color: #203F2F;
			}
			.kasa-org-warning {
				color: #8a6100;
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

		$value      = get_user_meta( $user->ID, Kasa_Scope::ORGANISATION_META, true );
		$is_partner = user_can( $user->ID, 'kasa_view_org_cohorts' );

		self::render_styles();
		?>
		<h2><?php esc_html_e( 'Kasa Academy', 'kasa-academy' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr class="kasa-org-field">
				<th>
					<label for="kasa_organisation_id"><?php esc_html_e( 'Organisation', 'kasa-academy' ); ?></label>
				</th>
				<td>
					<?php wp_nonce_field( 'kasa_save_organisation', 'kasa_organisation_nonce' ); ?>
					<input type="text"
						name="kasa_organisation_id"
						id="kasa_organisation_id"
						list="kasa-organisation-list"
						value="<?php echo esc_attr( $value ); ?>"
						class="regular-text"
						autocomplete="off" />
					<?php self::render_datalist( 'kasa-organisation-list' ); ?>
					<p class="description">
						<?php esc_html_e( 'Which organisation this user belongs to. An Implementation Partner sees only the groups carrying the same value, so it must match the group exactly.', 'kasa-academy' ); ?>
					</p>
					<?php if ( $is_partner && '' === trim( (string) $value ) ) : ?>
						<p class="kasa-org-warning">
							<?php esc_html_e( 'This partner has no organisation set, so they can currently see no groups and no learners at all. That is the safe default rather than an error, but it does mean their account does nothing until this is filled in.', 'kasa-academy' ); ?>
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
		if ( ! self::current_user_may_edit() ) {
			return;
		}

		if ( ! isset( $_POST['kasa_organisation_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['kasa_organisation_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'kasa_save_organisation' ) ) {
			return;
		}

		$value = isset( $_POST['kasa_organisation_id'] )
			? sanitize_text_field( wp_unslash( $_POST['kasa_organisation_id'] ) )
			: '';

		$value = trim( $value );

		if ( '' === $value ) {
			delete_user_meta( $user_id, Kasa_Scope::ORGANISATION_META );
		} else {
			update_user_meta( $user_id, Kasa_Scope::ORGANISATION_META, $value );
		}

		Kasa_Scope::flush_cache( $user_id );
	}

	/**
	 * Add the organisation box to a group.
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
		$value    = get_post_meta( $post->ID, Kasa_Scope::ORGANISATION_META, true );
		$editable = self::current_user_may_edit();

		self::render_styles();

		wp_nonce_field( 'kasa_save_group_organisation', 'kasa_group_organisation_nonce' );
		?>
		<div class="kasa-org-field">
			<input type="text"
				name="kasa_organisation_id"
				id="kasa_group_organisation_id"
				list="kasa-group-organisation-list"
				value="<?php echo esc_attr( $value ); ?>"
				style="width:100%"
				autocomplete="off"
				<?php disabled( ! $editable ); ?> />
			<?php self::render_datalist( 'kasa-group-organisation-list' ); ?>
			<p class="description">
				<?php esc_html_e( 'Leave empty unless this group belongs to a partner organisation. An empty value means no partner can reach this group, which is the safe default.', 'kasa-academy' ); ?>
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

		if ( ! self::current_user_may_edit() ) {
			return;
		}

		if ( ! isset( $_POST['kasa_group_organisation_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['kasa_group_organisation_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'kasa_save_group_organisation' ) ) {
			return;
		}

		$value = isset( $_POST['kasa_organisation_id'] )
			? trim( sanitize_text_field( wp_unslash( $_POST['kasa_organisation_id'] ) ) )
			: '';

		if ( '' === $value ) {
			delete_post_meta( $post_id, Kasa_Scope::ORGANISATION_META );
		} else {
			update_post_meta( $post_id, Kasa_Scope::ORGANISATION_META, $value );
		}

		Kasa_Scope::flush_cache();
	}
}
