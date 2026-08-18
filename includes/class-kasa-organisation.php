<?php
/**
 * The organisation registry.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Organisations, as a real thing rather than a typed-in string.
 *
 * An organisation is a school or partner body that registers a cohort of
 * children. It links a partner user to the groups they may see, so it decides
 * which children's data reaches which adult.
 *
 * It is a taxonomy because that is the WordPress answer to "a controlled list
 * somebody has to maintain". WordPress supplies the whole management screen,
 * the create, rename and delete flow, and the group count column, none of
 * which we then have to write or keep working.
 *
 * The alternative was a free text field on both ends, and that was the wrong
 * answer for a reason worth recording: the two ends have to match exactly, a
 * typo on either produces a partner who silently sees nothing, and nothing
 * anywhere tells you which organisations are supposed to exist.
 */
class Kasa_Organisation {

	/**
	 * Taxonomy name.
	 *
	 * @var string
	 */
	const TAXONOMY = 'kasa_organisation';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ), 20 );

		add_filter( 'parent_file', array( __CLASS__, 'keep_kasa_menu_open' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'highlight_submenu_item' ) );
	}

	/**
	 * Where organisations are managed.
	 *
	 * @return string
	 */
	public static function admin_url() {
		return 'edit-tags.php?taxonomy=' . self::TAXONOMY . '&post_type=' . self::group_post_type();
	}

	/**
	 * The group post type slug.
	 *
	 * @return string
	 */
	private static function group_post_type() {
		return function_exists( 'learndash_get_post_type_slug' )
			? learndash_get_post_type_slug( 'group' )
			: 'groups';
	}

	/**
	 * Whether the current screen is the organisations screen.
	 *
	 * @return bool
	 */
	private static function is_organisation_screen() {
		global $pagenow;

		if ( 'edit-tags.php' !== $pagenow && 'term.php' !== $pagenow ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current screen, not acting on it.
		return isset( $_GET['taxonomy'] ) && self::TAXONOMY === sanitize_key( wp_unslash( $_GET['taxonomy'] ) );
	}

	/**
	 * Keep the Kasa Academy menu open while managing organisations.
	 *
	 * The organisations screen is a WordPress taxonomy page reached through a
	 * submenu entry we add by URL. WordPress works out which menu to highlight
	 * from the current file, and edit-tags.php belongs to no plugin, so
	 * without this the whole menu closes and the person loses their place.
	 *
	 * @param string $parent_file Current parent menu file.
	 * @return string
	 */
	public static function keep_kasa_menu_open( $parent_file ) {
		return self::is_organisation_screen() ? Kasa_Admin_Menu::SLUG : $parent_file;
	}

	/**
	 * Highlight the Organisations item while on it.
	 *
	 * @param string $submenu_file Current submenu file.
	 * @return string
	 */
	public static function highlight_submenu_item( $submenu_file ) {
		return self::is_organisation_screen() ? self::admin_url() : $submenu_file;
	}

	/**
	 * Register the taxonomy against the LearnDash group post type.
	 *
	 * Runs at priority 20 on init, after LearnDash has registered its own post
	 * types at the default priority. Registering a taxonomy against a post
	 * type that does not exist yet quietly does nothing.
	 *
	 * @return void
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			self::group_post_type(),
			array(
				'labels'            => array(
					'name'          => __( 'Organisations', 'kasa-academy' ),
					'singular_name' => __( 'Organisation', 'kasa-academy' ),
					'menu_name'     => __( 'Organisations', 'kasa-academy' ),
					'all_items'     => __( 'All organisations', 'kasa-academy' ),
					'edit_item'     => __( 'Edit organisation', 'kasa-academy' ),
					'view_item'     => __( 'View organisation', 'kasa-academy' ),
					'update_item'   => __( 'Update organisation', 'kasa-academy' ),
					'add_new_item'  => __( 'Add a new organisation', 'kasa-academy' ),
					'new_item_name' => __( 'Organisation name', 'kasa-academy' ),
					'search_items'  => __( 'Search organisations', 'kasa-academy' ),
					'not_found'     => __( 'No organisations yet.', 'kasa-academy' ),
				),
				// Hierarchical so WordPress gives the term management screen
				// rather than the free-typing tag box. Nothing nests in
				// practice, but the tag box would reintroduce exactly the
				// typo problem this replaces.
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_in_nav_menus' => false,
				'show_admin_column' => true,
				'show_in_quick_edit' => false,
				// Our own box replaces this one, so a group can only ever
				// carry a single organisation.
				'meta_box_cb'       => false,
				'show_in_rest'      => false,
				'rewrite'           => false,
				'capabilities'      => array(
					'manage_terms' => 'kasa_manage_academy',
					'edit_terms'   => 'kasa_manage_academy',
					'delete_terms' => 'kasa_manage_academy',
					'assign_terms' => 'kasa_manage_academy',
				),
			)
		);
	}

	/**
	 * Every organisation, for a select box.
	 *
	 * @return WP_Term[]
	 */
	public static function all() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * The organisation a group belongs to.
	 *
	 * @param int $group_id Group post ID.
	 * @return int Term ID, or 0.
	 */
	public static function for_group( $group_id ) {
		$terms = wp_get_object_terms( absint( $group_id ), self::TAXONOMY, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}

		return absint( $terms[0] );
	}

	/**
	 * Set, or clear, the organisation on a group.
	 *
	 * @param int $group_id Group post ID.
	 * @param int $term_id  Term ID, or 0 to clear.
	 * @return void
	 */
	public static function set_for_group( $group_id, $term_id ) {
		$group_id = absint( $group_id );
		$term_id  = absint( $term_id );

		if ( ! $group_id ) {
			return;
		}

		if ( ! $term_id || ! self::exists( $term_id ) ) {
			wp_set_object_terms( $group_id, array(), self::TAXONOMY );

			return;
		}

		wp_set_object_terms( $group_id, array( $term_id ), self::TAXONOMY );
	}

	/**
	 * Whether a term ID is a real organisation.
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	public static function exists( $term_id ) {
		$term_id = absint( $term_id );

		if ( ! $term_id ) {
			return false;
		}

		$term = get_term( $term_id, self::TAXONOMY );

		return $term instanceof WP_Term;
	}

	/**
	 * A readable name for a term ID.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	public static function name( $term_id ) {
		$term = get_term( absint( $term_id ), self::TAXONOMY );

		return $term instanceof WP_Term ? $term->name : '';
	}
}
