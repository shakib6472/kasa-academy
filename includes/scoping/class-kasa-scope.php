<?php
/**
 * Group and organisation scoping.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Works out which groups, and therefore which children, a user is allowed to
 * see.
 *
 * Capabilities answer "may this kind of user see learner data at all". They do
 * not answer "which learners". A facilitator holds kasa_view_group_learners,
 * but that has to mean their learners, not every child on the platform. This
 * class is the only place that answers the second question. Everything else,
 * including every module added in a later milestone, calls in here.
 *
 * The rule is single-sourced on purpose. This platform holds data about
 * children, including guardian consent records. Getting the rule wrong in one
 * copy of it is not a display bug, it is a disclosure of a child's work to an
 * adult with no right to it. One implementation can be audited; five cannot.
 *
 * Everything here fails closed. An unrecognised user, a user with no relevant
 * capability, a partner with no organisation, a logged out visitor: all get an
 * empty array, which callers must treat as "no groups", never as "unfiltered".
 */
class Kasa_Scope {

	/**
	 * User meta key holding the organisation a user belongs to.
	 *
	 * The value is a kasa_organisation term ID. A term ID rather than a name
	 * or a slug, so renaming an organisation does not quietly detach every
	 * partner attached to it.
	 *
	 * Groups do not use this key. They carry the taxonomy term itself, which
	 * is what gives the Organisations screen its group counts.
	 *
	 * @var string
	 */
	const ORGANISATION_META = 'kasa_organisation_id';

	/**
	 * Post statuses a group must be in to be visible to a scoped user.
	 *
	 * Draft, pending and future groups are still being set up and are not
	 * shown to facilitators or partners. Administrators see every status.
	 *
	 * @var array
	 */
	const VISIBLE_GROUP_STATUSES = array( 'publish', 'private' );

	/**
	 * Per-request memo, keyed by user ID.
	 *
	 * Scoping is consulted repeatedly while rendering a single screen, and the
	 * answer cannot change mid-request.
	 *
	 * @var array
	 */
	private static $group_cache = array();

	/**
	 * Per-request memo of learner IDs, keyed by user ID.
	 *
	 * @var array
	 */
	private static $learner_cache = array();

	/**
	 * The group IDs a user may see.
	 *
	 * @param int $user_id Optional. Defaults to the current user.
	 * @return array List of group post IDs. Empty means no groups, never "all".
	 */
	public static function visible_group_ids( $user_id = 0 ) {
		$user_id = self::resolve_user_id( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		if ( isset( self::$group_cache[ $user_id ] ) ) {
			return self::$group_cache[ $user_id ];
		}

		$group_ids = self::calculate_visible_group_ids( $user_id );

		/**
		 * Filters the groups a user may see.
		 *
		 * Anything hooked here must only ever narrow the list. Widening it
		 * bypasses the isolation this class exists to provide.
		 *
		 * @param array $group_ids Group post IDs.
		 * @param int   $user_id   User the list belongs to.
		 */
		$group_ids = apply_filters( 'kasa_visible_group_ids', $group_ids, $user_id );

		$group_ids = self::clean_id_list( $group_ids );

		self::$group_cache[ $user_id ] = $group_ids;

		return $group_ids;
	}

	/**
	 * Decide the list, before caching and filtering.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	private static function calculate_visible_group_ids( $user_id ) {
		// Administrators are unrestricted.
		if ( self::is_unrestricted( $user_id ) ) {
			return self::all_group_ids();
		}

		/*
		 * Partner before facilitator, and deliberately not both.
		 *
		 * A partner is scoped by organisation, a facilitator by the groups
		 * they lead. Partners are also made LearnDash group leaders, because
		 * that is the only way LearnDash will populate their reporting, so a
		 * partner has both kinds of link and the two could disagree.
		 *
		 * Taking the union of them would mean a leader assignment made by
		 * mistake, on a group belonging to another organisation, silently
		 * widens what that partner can see. Taking the organisation alone
		 * means the same mistake shows them nothing extra. A misconfiguration
		 * that hides data is a support ticket; one that reveals another
		 * school's children is a safeguarding incident.
		 *
		 * Kasa_Scope::leadership_divergence() reports any such mismatch so it
		 * can be found and corrected rather than sitting there unnoticed.
		 */
		if ( user_can( $user_id, 'kasa_view_org_cohorts' ) ) {
			return self::organisation_group_ids( self::organisation_id( $user_id ) );
		}

		if ( user_can( $user_id, 'kasa_view_group_learners' ) ) {
			return self::leader_group_ids( $user_id );
		}

		// Learners, and anyone else, see no groups.
		return array();
	}

	/**
	 * Whether this user sees everything.
	 *
	 * Mirrors LearnDash, which treats LEARNDASH_ADMIN_CAPABILITY_CHECK
	 * (manage_options) as unrestricted. Using the same test keeps our screens
	 * and LearnDash's screens from disagreeing about who is an administrator.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_unrestricted( $user_id ) {
		$capability = defined( 'LEARNDASH_ADMIN_CAPABILITY_CHECK' ) && LEARNDASH_ADMIN_CAPABILITY_CHECK
			? LEARNDASH_ADMIN_CAPABILITY_CHECK
			: 'manage_options';

		return user_can( $user_id, $capability );
	}

	/**
	 * The organisation a user belongs to.
	 *
	 * @param int $user_id User ID.
	 * @return string Empty string when unset.
	 */
	public static function organisation_id( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return '';
		}

		return self::normalise_organisation_id( get_user_meta( $user_id, self::ORGANISATION_META, true ) );
	}

	/**
	 * Reduce an organisation identifier to a comparable string.
	 *
	 * @param mixed $value Raw meta value.
	 * @return string Empty string when the value is not usable.
	 */
	private static function normalise_organisation_id( $value ) {
		if ( is_array( $value ) || is_object( $value ) || is_bool( $value ) || null === $value ) {
			return '';
		}

		return trim( (string) $value );
	}

	/**
	 * The groups belonging to an organisation.
	 *
	 * @param string $organisation_id Organisation identifier.
	 * @return array
	 */
	public static function organisation_group_ids( $organisation_id ) {
		$organisation_id = self::normalise_organisation_id( $organisation_id );

		/*
		 * The guard that matters most in this file.
		 *
		 * A partner with no organisation set must see nothing. Falling through
		 * to the query below with an empty value would ask for "groups whose
		 * kasa_organisation_id is the empty string", and every group that was
		 * never tagged answers to that. One unset user meta field would hand a
		 * partner every cohort on the platform.
		 */
		if ( '' === $organisation_id ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'              => self::group_post_type(),
				'post_status'            => self::VISIBLE_GROUP_STATUSES,
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => self::ORGANISATION_META,
						'value'   => $organisation_id,
						'compare' => '=',
					),
				),
			)
		);

		return self::clean_id_list( $query->posts );
	}

	/**
	 * The groups a user leads, according to LearnDash.
	 *
	 * Delegates rather than reading the learndash_group_leaders_* user meta
	 * directly, so that group hierarchy, validation of deleted groups and any
	 * future change LearnDash makes are all inherited instead of reimplemented.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function leader_group_ids( $user_id ) {
		if ( ! function_exists( 'learndash_get_administrators_group_ids' ) ) {
			return array();
		}

		/*
		 * learndash_get_administrators_group_ids() returns every group on the
		 * site when the user is an administrator. Callers reaching this branch
		 * are not administrators, because is_unrestricted() is checked first,
		 * but pass true for $menu so the function returns only explicitly
		 * assigned groups and can never take the unrestricted path.
		 */
		return self::clean_id_list( learndash_get_administrators_group_ids( $user_id, true ) );
	}

	/**
	 * Every group on the site. Administrators only.
	 *
	 * @return array
	 */
	private static function all_group_ids() {
		$query = new WP_Query(
			array(
				'post_type'              => self::group_post_type(),
				'post_status'            => array( 'publish', 'pending', 'draft', 'future', 'private' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return self::clean_id_list( $query->posts );
	}

	/**
	 * May this user see this group?
	 *
	 * This is the check that stops someone editing a group ID in a URL.
	 *
	 * @param int $group_id Group post ID.
	 * @param int $user_id  Optional. Defaults to the current user.
	 * @return bool
	 */
	public static function can_see_group( $group_id, $user_id = 0 ) {
		$group_id = absint( $group_id );

		if ( ! $group_id ) {
			return false;
		}

		return in_array( $group_id, self::visible_group_ids( $user_id ), true );
	}

	/**
	 * The learners a user may see.
	 *
	 * @param int $user_id Optional. Defaults to the current user.
	 * @return array List of user IDs.
	 */
	public static function visible_learner_ids( $user_id = 0 ) {
		$user_id = self::resolve_user_id( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		if ( isset( self::$learner_cache[ $user_id ] ) ) {
			return self::$learner_cache[ $user_id ];
		}

		$learner_ids = array();

		if ( function_exists( 'learndash_get_groups_user_ids' ) ) {
			foreach ( self::visible_group_ids( $user_id ) as $group_id ) {
				$learner_ids = array_merge( $learner_ids, learndash_get_groups_user_ids( $group_id ) );
			}
		}

		$learner_ids = self::clean_id_list( $learner_ids );

		self::$learner_cache[ $user_id ] = $learner_ids;

		return $learner_ids;
	}

	/**
	 * May this user see this learner?
	 *
	 * This is the check that stops someone editing a user ID in a query string.
	 *
	 * @param int $learner_id Learner user ID.
	 * @param int $user_id    Optional. Defaults to the current user.
	 * @return bool
	 */
	public static function can_see_learner( $learner_id, $user_id = 0 ) {
		$learner_id = absint( $learner_id );

		if ( ! $learner_id ) {
			return false;
		}

		$user_id = self::resolve_user_id( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		// Everyone can see themselves.
		if ( $learner_id === $user_id ) {
			return true;
		}

		if ( self::is_unrestricted( $user_id ) ) {
			return true;
		}

		return in_array( $learner_id, self::visible_learner_ids( $user_id ), true );
	}

	/**
	 * Report where a partner's LearnDash leadership disagrees with their
	 * organisation.
	 *
	 * Scoping ignores leader assignments for partners, so a wrong one hides
	 * nothing and reveals nothing. It does still show up on LearnDash's own
	 * screens, which answer to LearnDash's rule rather than ours, so the
	 * mismatch needs to be visible rather than silent.
	 *
	 * @param int $user_id User ID.
	 * @return array {
	 *     @type array $leads_outside_organisation Groups led but not in the organisation.
	 *     @type array $organisation_not_led       Organisation groups they do not lead.
	 * }
	 */
	public static function leadership_divergence( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! user_can( $user_id, 'kasa_view_org_cohorts' ) ) {
			return array(
				'leads_outside_organisation' => array(),
				'organisation_not_led'       => array(),
			);
		}

		$organisation = self::organisation_group_ids( self::organisation_id( $user_id ) );
		$led          = self::leader_group_ids( $user_id );

		return array(
			'leads_outside_organisation' => array_values( array_diff( $led, $organisation ) ),
			'organisation_not_led'       => array_values( array_diff( $organisation, $led ) ),
		);
	}

	/**
	 * The LearnDash group post type slug.
	 *
	 * @return string
	 */
	private static function group_post_type() {
		return function_exists( 'learndash_get_post_type_slug' )
			? learndash_get_post_type_slug( 'group' )
			: 'groups';
	}

	/**
	 * Resolve a user ID argument, defaulting to the current user.
	 *
	 * @param int $user_id Supplied user ID.
	 * @return int Zero when there is no user.
	 */
	private static function resolve_user_id( $user_id ) {
		$user_id = absint( $user_id );

		if ( $user_id ) {
			return $user_id;
		}

		return absint( get_current_user_id() );
	}

	/**
	 * Force a list into unique positive integers.
	 *
	 * @param mixed $ids Raw list.
	 * @return array
	 */
	private static function clean_id_list( $ids ) {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$ids = array_map( 'absint', $ids );
		$ids = array_filter( $ids );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Drop the per-request memo.
	 *
	 * Needed by tests, and by any code that changes group membership and then
	 * asks about scope again in the same request.
	 *
	 * @param int $user_id Optional. Clears everything when omitted.
	 * @return void
	 */
	public static function flush_cache( $user_id = 0 ) {
		$user_id = absint( $user_id );

		if ( $user_id ) {
			unset( self::$group_cache[ $user_id ], self::$learner_cache[ $user_id ] );

			return;
		}

		self::$group_cache   = array();
		self::$learner_cache = array();
	}
}

/*
 * Function wrappers.
 *
 * The brief asks for a single helper that returns the group IDs a user may
 * see. These are that helper, in the shape most callers want.
 */

/**
 * The group IDs a user may see.
 *
 * @param int $user_id Optional. Defaults to the current user.
 * @return array
 */
function kasa_get_visible_group_ids( $user_id = 0 ) {
	return Kasa_Scope::visible_group_ids( $user_id );
}

/**
 * May this user see this group?
 *
 * @param int $group_id Group post ID.
 * @param int $user_id  Optional. Defaults to the current user.
 * @return bool
 */
function kasa_user_can_see_group( $group_id, $user_id = 0 ) {
	return Kasa_Scope::can_see_group( $group_id, $user_id );
}

/**
 * The learner user IDs a user may see.
 *
 * @param int $user_id Optional. Defaults to the current user.
 * @return array
 */
function kasa_get_visible_learner_ids( $user_id = 0 ) {
	return Kasa_Scope::visible_learner_ids( $user_id );
}

/**
 * May this user see this learner?
 *
 * @param int $learner_id Learner user ID.
 * @param int $user_id    Optional. Defaults to the current user.
 * @return bool
 */
function kasa_user_can_see_learner( $learner_id, $user_id = 0 ) {
	return Kasa_Scope::can_see_learner( $learner_id, $user_id );
}
