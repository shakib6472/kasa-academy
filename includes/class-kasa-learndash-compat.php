<?php
/**
 * Makes LearnDash's admin screens aware of the Kasa roles.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LearnDash compatibility.
 *
 * LearnDash decides who *is* a group leader by capability, which is why
 * granting the group_leader capability to kasa_partner works everywhere it
 * counts. Its admin screens, however, decide who may be *chosen* as a group
 * leader by role. The two disagree, and this class settles it.
 *
 * Everything here is a filter on a LearnDash hook. No LearnDash file is
 * edited, so a LearnDash update cannot undo any of it.
 */
class Kasa_LearnDash_Compat {

	/**
	 * The selector LearnDash uses to pick group leaders.
	 *
	 * @var string
	 */
	const GROUP_LEADER_SELECTOR = 'Learndash_Binary_Selector_Group_Leaders';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'learndash_binary_selector_args', array( __CLASS__, 'add_kasa_leader_roles' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'ensure_group_member_management' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'advanced_level_notice' ) );
		add_filter( 'learndash_group_leader_has_cap_filter', array( __CLASS__, 'restrict_group_editing' ), 10, 4 );
		add_action( 'pre_get_posts', array( __CLASS__, 'scope_admin_group_queries' ) );
	}

	/**
	 * Hold LearnDash's own admin screens to the Kasa scope.
	 *
	 * LearnDash decides which groups to show a group leader with
	 * learndash_get_administrators_group_ids(), which reads the
	 * learndash_group_leaders_* user meta and nothing else. For a facilitator
	 * that agrees with us. For a partner it does not, because a partner is
	 * scoped by organisation while their leader assignments exist only so that
	 * LearnDash will populate their reporting.
	 *
	 * The two disagreeing is not theoretical. Assign a partner as leader of a
	 * group belonging to another organisation, which is one wrong click on the
	 * group screen, and the Group Administration page at
	 * admin.php?page=group_admin_page lists that group for them, complete with
	 * List Users and Export Progress. Our own screens would refuse it; theirs
	 * did not.
	 *
	 * There is no filter on learndash_get_administrators_group_ids(), but the
	 * screens all reach the database through WP_Query with post__in, so
	 * narrowing that covers every one of them at once rather than each screen
	 * being patched as somebody notices it.
	 *
	 * @param WP_Query $query Query about to run.
	 * @return void
	 */
	public static function scope_admin_group_queries( $query ) {
		if ( ! is_admin() || ! $query instanceof WP_Query ) {
			return;
		}

		/*
		 * Scoping answers "which groups may this user see" by running a group
		 * query of its own. Filtering that query would call scoping again,
		 * which would run the query again, until the request dies. The flag is
		 * set by Kasa_Scope on its own lookups; the static below catches any
		 * other nested case.
		 */
		if ( $query->get( 'kasa_scope_query' ) ) {
			return;
		}

		static $running = false;

		if ( $running ) {
			return;
		}

		if ( ! function_exists( 'learndash_get_post_type_slug' ) ) {
			return;
		}

		$group_type = learndash_get_post_type_slug( 'group' );
		$queried    = $query->get( 'post_type' );

		$is_group_query = is_array( $queried )
			? in_array( $group_type, $queried, true )
			: $group_type === $queried;

		if ( ! $is_group_query ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id || Kasa_Scope::is_unrestricted( $user_id ) ) {
			return;
		}

		// Only narrow the roles that are supposed to see groups at all.
		// Anyone else is refused before a query is reached.
		if ( ! user_can( $user_id, 'kasa_view_group_learners' ) ) {
			return;
		}

		$running = true;
		$allowed = kasa_get_visible_group_ids( $user_id );
		$running = false;

		$existing = $query->get( 'post__in' );

		if ( ! empty( $existing ) && is_array( $existing ) ) {
			$allowed = array_intersect( $allowed, array_map( 'absint', $existing ) );
		}

		// An empty post__in is ignored by WP_Query, which would return
		// everything. Zero is a post ID that cannot exist, so it returns
		// nothing, which is what an empty scope has to mean.
		$query->set( 'post__in', empty( $allowed ) ? array( 0 ) : array_values( $allowed ) );
	}

	/**
	 * Keep partners out of the group editing capabilities.
	 *
	 * Turning on group leader management is what lets a facilitator add and
	 * remove learners, which the matrix requires. LearnDash applies it to
	 * everyone holding the group_leader capability, and partners hold it
	 * because that is the only way their reporting works. Left alone, the
	 * setting would hand partners "Add or remove learners from own groups",
	 * which the matrix gives them as No.
	 *
	 * LearnDash offers this filter at each of those decision points, so the
	 * distinction is drawn here rather than by withholding the setting from
	 * facilitators too.
	 *
	 * The gate is kasa_manage_group_members, not the role name, so this keeps
	 * agreeing with the capability map rather than drifting from it.
	 *
	 * @param bool    $allow Whether LearnDash would allow the capability.
	 * @param array   $cap   Capabilities being checked.
	 * @param array   $args  Arguments accompanying the check.
	 * @param WP_User $user  User being checked.
	 * @return bool
	 */
	public static function restrict_group_editing( $allow, $cap, $args, $user ) {
		if ( ! $allow || ! $user instanceof WP_User ) {
			return $allow;
		}

		if ( user_can( $user->ID, 'manage_options' ) ) {
			return $allow;
		}

		return user_can( $user->ID, 'kasa_manage_group_members' );
	}

	/**
	 * Turn on group member management, and keep it on the safe setting.
	 *
	 * The matrix gives facilitators "Add or remove learners from own groups".
	 * No capability delivers that. LearnDash gates it on a setting, and
	 * learndash_get_group_leader_manage_users() returns nothing at all while
	 * manage_users_enabled is empty, so the capability sits there doing
	 * nothing until this is switched on.
	 *
	 * The second half matters more than the first. That setting has two
	 * levels, basic and advanced, and in ld-groups.php:
	 *
	 *     if ( 'advanced' === learndash_get_group_leader_manage_users()
	 *       || 'advanced' === learndash_get_group_leader_manage_groups() ) {
	 *         return true;
	 *     }
	 *
	 * sits inside learndash_check_group_leader_course_user_intersect(), which
	 * is what limits a group leader to the children in their own groups. On
	 * advanced that limit stops applying, and every group leader, partners
	 * included, can reach every child's work on the platform.
	 *
	 * So advanced is corrected back to basic, loudly rather than silently, and
	 * a site that genuinely wants it can say so through the filter.
	 *
	 * Written through LearnDash's settings API, because a plain update_option()
	 * is discarded by their nonce check. See Kasa_Roles for the detail.
	 *
	 * @return void
	 */
	public static function ensure_group_member_management() {
		if ( ! class_exists( 'LearnDash_Settings_Section' ) ) {
			return;
		}

		$section = 'LearnDash_Settings_Section_Groups_Group_Leader_User';

		// manage_users_enabled lets a group leader add and remove learners.
		// manage_groups_enabled lets them open the group at all, which is where
		// that member list lives. Both are needed for the one matrix row, and
		// on basic each is still scoped to the groups they actually lead.
		// Neither grants creating a new group, which the matrix denies.
		foreach ( array( 'manage_users_enabled', 'manage_groups_enabled' ) as $toggle ) {
			if ( 'yes' !== LearnDash_Settings_Section::get_section_setting( $section, $toggle ) ) {
				LearnDash_Settings_Section::set_section_setting( $section, $toggle, 'yes' );
			}
		}

		/**
		 * Filters whether the advanced group leader user management level is
		 * permitted.
		 *
		 * Returning true disables a child safety guard. Read the docblock
		 * above before using it.
		 *
		 * @param bool $allow Whether to allow the advanced level.
		 */
		if ( apply_filters( 'kasa_allow_advanced_group_leader_management', false ) ) {
			return;
		}

		foreach ( array( 'manage_users_capabilities', 'manage_groups_capabilities' ) as $field ) {
			if ( 'advanced' === LearnDash_Settings_Section::get_section_setting( $section, $field ) ) {
				LearnDash_Settings_Section::set_section_setting( $section, $field, 'basic' );

				update_option( 'kasa_academy_advanced_level_corrected', $field );
			}
		}
	}

	/**
	 * Say so when the advanced level was corrected.
	 *
	 * Overriding an administrator's setting without telling them would be
	 * worse than the setting itself.
	 *
	 * @return void
	 */
	public static function advanced_level_notice() {
		$field = get_option( 'kasa_academy_advanced_level_corrected' );

		if ( ! $field || ! current_user_can( 'kasa_manage_academy' ) ) {
			return;
		}

		delete_option( 'kasa_academy_advanced_level_corrected' );

		echo '<div class="notice notice-warning is-dismissible"><p><strong>';
		esc_html_e( 'Kasa Academy', 'kasa-academy' );
		echo '</strong> ';
		esc_html_e( 'The LearnDash group leader management level was set back to Basic. On Advanced, LearnDash stops limiting a group leader to their own groups, which would let every facilitator and partner open any child\'s quiz answers and uploaded work anywhere on the platform.', 'kasa-academy' );
		echo '</p></div>';
	}

	/**
	 * Let Kasa roles that hold the group_leader capability be picked as group
	 * leaders.
	 *
	 * Without this the Group Leaders box on a group is built by
	 * class-learndash-admin-binary-selector-group-leaders.php with
	 *
	 *     $args['role__in'] = array( 'group_leader', 'administrator' );
	 *
	 * which is a role filter. A kasa_partner holds the group_leader
	 * capability but not the group_leader role, so partners never appear in
	 * the list.
	 *
	 * That is not merely cosmetic, and it is the reason this fix is not
	 * optional. Saving a group calls learndash_set_groups_administrators(),
	 * which treats the submitted list as the complete truth:
	 *
	 *     $group_leaders_remove = array_diff( $group_leaders_old, $group_leaders_intersect );
	 *     foreach ( $group_leaders_remove as $user_id ) {
	 *         ld_update_leader_group_access( $user_id, $group_id, true );
	 *     }
	 *
	 * A partner who cannot appear in the list can never be in the submitted
	 * list either, so every save of that group silently strips the partner's
	 * leadership. Their LearnDash reporting then empties out with nothing on
	 * screen to say why.
	 *
	 * The roles are read from the capability map rather than hard coded, so a
	 * future role granted the group_leader capability is handled without
	 * anyone remembering to come back here.
	 *
	 * @param array  $args           Selector arguments.
	 * @param string $selector_class Selector class name.
	 * @return array
	 */
	public static function add_kasa_leader_roles( $args, $selector_class ) {
		if ( self::GROUP_LEADER_SELECTOR !== $selector_class ) {
			return $args;
		}

		// LearnDash only sets role__in when it is not showing a fixed list of
		// user IDs. When it is, leave the fixed list alone.
		if ( ! isset( $args['role__in'] ) || ! is_array( $args['role__in'] ) ) {
			return $args;
		}

		foreach ( self::leader_capable_roles() as $role ) {
			if ( ! in_array( $role, $args['role__in'], true ) ) {
				$args['role__in'][] = $role;
			}
		}

		return $args;
	}

	/**
	 * Kasa roles that carry the LearnDash group_leader capability.
	 *
	 * @return array Role slugs.
	 */
	private static function leader_capable_roles() {
		$roles = array();

		if ( ! function_exists( 'kasa_academy_role_definitions' ) ) {
			return $roles;
		}

		foreach ( kasa_academy_role_definitions() as $slug => $definition ) {
			if ( empty( $definition['caps'] ) || ! is_array( $definition['caps'] ) ) {
				continue;
			}

			if ( in_array( 'group_leader', $definition['caps'], true ) ) {
				$roles[] = $slug;
			}
		}

		return $roles;
	}
}
