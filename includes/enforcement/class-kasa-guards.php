<?php
/**
 * Runtime access guards.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blocks access that capabilities alone cannot block.
 *
 * Two of the rules in the capability matrix cannot be expressed as a
 * capability, because the code that decides them never looks at one. This
 * class supplies the missing decision, and it always decides by asking
 * Kasa_Scope rather than by working anything out for itself.
 */
class Kasa_Guards {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'guard_learner_work' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'guard_admin_access' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'guard_group_id_requests' ), 2 );
		add_action( 'admin_init', array( __CLASS__, 'guard_quiz_statistics_screen' ), 3 );

		// Before LearnDash's own handler, so a refused request never reaches it.
		add_action( 'wp_ajax_wp_pro_quiz_admin_ajax', array( __CLASS__, 'guard_quiz_statistics_ajax' ), 1 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'filter_admin_bar' ) );
		add_filter( 'learndash_quiz_essay_get_download_url', array( __CLASS__, 'filter_essay_download_url' ), 10, 2 );
		add_filter( 'register_post_type_args', array( __CLASS__, 'restrict_group_creation' ), 20, 2 );

		// Priority 1, so this runs before ProPanel's own handler at 10 and can
		// refuse the request before any email is sent.
		add_action( 'wp_ajax_learndash_propanel_email_users', array( __CLASS__, 'guard_propanel_email' ), 1 );
	}

	/**
	 * Stop ProPanel emailing children outside the sender's own groups.
	 *
	 * ProPanel's ajax_email_users() checks that the caller is a group leader of
	 * something, and then takes the recipient list straight from the request:
	 *
	 *     $user_ids = isset( $_POST['user_ids'] ) ? $_POST['user_ids'] : null;
	 *     ...
	 *     $user_ids = array_map( 'intval', explode( ',', $user_ids ) );
	 *
	 * Nothing between there and email_users() checks that those users are in
	 * any group the sender leads. Any facilitator or partner can therefore
	 * post arbitrary user IDs and email any account on the platform, including
	 * children in another facilitator's group.
	 *
	 * That is not a data disclosure, so it did not show up while checking who
	 * can read what, but on a platform for minors, contacting a child outside
	 * your own cohort is the safeguarding problem that reading their work is.
	 *
	 * Only the explicitly listed recipients are checked here. When the request
	 * carries no user_ids, ProPanel builds the list from its own reporting
	 * query, which is already scoped to the caller's groups.
	 *
	 * @return void
	 */
	public static function guard_propanel_email() {
		if ( Kasa_Scope::is_unrestricted( get_current_user_id() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- ProPanel verifies its own nonce; this only inspects the payload.
		$raw = isset( $_POST['user_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['user_ids'] ) ) : '';

		if ( '' === $raw ) {
			return;
		}

		$requested = array_filter( array_map( 'absint', explode( ',', $raw ) ) );

		if ( empty( $requested ) ) {
			return;
		}

		$outside = array_diff( $requested, kasa_get_visible_learner_ids() );

		if ( empty( $outside ) ) {
			return;
		}

		wp_send_json_error(
			array(
				'message' => __( 'Some of those recipients are not in your groups, so nothing was sent.', 'kasa-academy' ),
			),
			403
		);
	}

	/**
	 * Only an administrator creates groups.
	 *
	 * The matrix gives "Create a new group" to administrators alone, but the
	 * setting that lets a facilitator manage their own group's members also
	 * gives them edit_groups, and LearnDash registers the group post type with
	 *
	 *     'create_posts' => 'edit_groups'
	 *
	 * so the same capability that opens the groups list also opens Add New.
	 * The two cannot be separated by taking a capability away, because the
	 * list screen needs the one that would have to go.
	 *
	 * Changing what create_posts maps to solves it in the place WordPress
	 * intends. It hides the Add New button as well as blocking the screen, so
	 * nobody is offered something that would only fail.
	 *
	 * @param array  $args      Post type arguments.
	 * @param string $post_type Post type slug.
	 * @return array
	 */
	public static function restrict_group_creation( $args, $post_type ) {
		if ( ! function_exists( 'learndash_get_post_type_slug' ) ) {
			return $args;
		}

		if ( learndash_get_post_type_slug( 'group' ) !== $post_type ) {
			return $args;
		}

		if ( current_user_can( 'kasa_manage_academy' ) ) {
			return $args;
		}

		if ( ! isset( $args['capabilities'] ) || ! is_array( $args['capabilities'] ) ) {
			$args['capabilities'] = array();
		}

		$args['capabilities']['create_posts'] = 'do_not_allow';

		return $args;
	}

	/**
	 * The post types that hold a child's own work.
	 *
	 * In LearnDash an essay is an open response quiz answer, which is what the
	 * matrix calls a written reflection. An assignment is an uploaded piece of
	 * work. Both are the child's own words or files.
	 *
	 * @return array Post type slugs.
	 */
	private static function learner_work_post_types() {
		if ( ! function_exists( 'learndash_get_post_type_slug' ) ) {
			return array();
		}

		return array(
			learndash_get_post_type_slug( 'essay' ),
			learndash_get_post_type_slug( 'assignment' ),
		);
	}

	/**
	 * Stop anyone reading a child's work who has no right to it.
	 *
	 * LearnDash guards both post types with the same shape of check, in
	 * learndash_essay_permissions() and learndash_assignment_permissions():
	 *
	 *     learndash_is_admin_user() || author || learndash_is_group_leader_user()
	 *
	 * and learndash_is_group_leader_user() is user_can( $user, 'group_leader' ).
	 * A partner carries that capability, because it is the only way LearnDash
	 * will populate their reporting, so LearnDash lets partners read the
	 * reflections and uploaded work of children in their own cohorts. The
	 * capability matrix says partners must not.
	 *
	 * Capabilities cannot fix this on their own. Both post types are registered
	 * public and publicly_queryable, and their custom post statuses are public
	 * too, so WP_Query never reaches a meta capability check and map_meta_cap
	 * is never consulted. The decision has to be made here.
	 *
	 * This guard is deliberately stricter than LearnDash's, never looser.
	 *
	 * @return void
	 */
	public static function guard_learner_work() {
		$post_types = self::learner_work_post_types();

		if ( empty( $post_types ) || ! is_singular( $post_types ) ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( self::may_read_learner_work( $post, get_current_user_id() ) ) {
			return;
		}

		self::deny();
	}

	/**
	 * Whether a user may read one piece of learner work.
	 *
	 * @param WP_Post $post    Essay or assignment.
	 * @param int     $user_id User ID.
	 * @return bool
	 */
	private static function may_read_learner_work( $post, $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		// A child reading back their own work.
		if ( absint( $post->post_author ) === $user_id ) {
			return true;
		}

		if ( Kasa_Scope::is_unrestricted( $user_id ) ) {
			return true;
		}

		/*
		 * Both halves are required, and neither is enough alone.
		 *
		 * The capability answers "may this kind of user read a child's own
		 * words at all", which excludes partners and learners. The scope check
		 * answers "is this particular child one of theirs", which stops a
		 * facilitator reading the work of another facilitator's group by
		 * changing the URL.
		 */
		return user_can( $user_id, 'kasa_view_group_detail' )
			&& kasa_user_can_see_learner( $post->post_author, $user_id );
	}

	/**
	 * Do not hand out a download link for work the viewer may not read.
	 *
	 * Defence in depth. LearnDash protects the file endpoint itself with a
	 * nonce and nothing else; its can_be_downloaded() is
	 * `return is_user_logged_in();`. The nonce is what makes a download URL
	 * usable, and it is only ever printed onto a page, so guard_learner_work()
	 * above already stops the wrong person receiving one. This closes the case
	 * where some other screen renders the link.
	 *
	 * @param string $url     Download URL.
	 * @param int    $post_id Essay post ID.
	 * @return string
	 */
	public static function filter_essay_download_url( $url, $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		return self::may_read_learner_work( $post, get_current_user_id() ) ? $url : '';
	}

	/**
	 * Keep learners out of wp-admin.
	 *
	 * Definition of Done point 7. Worth noting that WordPress itself redirects
	 * /dashboard/ and /admin/ to wp-admin, so a curious child reaches it by
	 * guessing rather than by being linked there.
	 *
	 * Facilitators and partners keep their admin access. Narrowing that to
	 * their own group screens is LearnDash's own group leader restriction,
	 * which is already in force.
	 *
	 * @return void
	 */
	public static function guard_admin_access() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( self::admin_request_is_exempt() ) {
			return;
		}

		if ( self::may_use_admin( get_current_user_id() ) ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * Refuse any admin request naming a group the user may not see.
	 *
	 * Narrowing the group list query is not enough, because the screens that
	 * show one group take its ID from the request instead of from a list.
	 * LearnDash's Group Administration page is the clearest case: with
	 * admin.php?page=group_admin_page&group_id=N, changing N walks straight
	 * into another group's roster, names and email addresses included. The
	 * group never appears in the user's own list, so the interface looks
	 * correctly locked; only editing the URL reveals it, which is why the
	 * brief insists on testing that way rather than by clicking around.
	 *
	 * Written against the group_id parameter itself rather than against that
	 * one screen, so a different LearnDash screen, or a later Kasa module,
	 * taking the same parameter is covered without anyone remembering to come
	 * back and add it.
	 *
	 * @return void
	 */
	public static function guard_group_id_requests() {
		if ( self::admin_request_is_exempt() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id || Kasa_Scope::is_unrestricted( $user_id ) ) {
			return;
		}

		// Roles with no group access at all are already turned away by
		// guard_admin_access().
		if ( ! user_can( $user_id, 'kasa_view_group_learners' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading an identifier to decide access, not acting on a submission.
		$raw = null;

		if ( isset( $_GET['group_id'] ) ) {
			$raw = wp_unslash( $_GET['group_id'] );
		} elseif ( isset( $_POST['group_id'] ) ) {
			$raw = wp_unslash( $_POST['group_id'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( null === $raw || is_array( $raw ) ) {
			return;
		}

		$group_id = absint( $raw );

		// Zero and non-numeric values are not a group, and screens use them to
		// mean "no group chosen".
		if ( ! $group_id ) {
			return;
		}

		if ( kasa_user_can_see_group( $group_id, $user_id ) ) {
			return;
		}

		wp_die(
			esc_html__( 'You do not have access to that group.', 'kasa-academy' ),
			esc_html__( 'Access denied', 'kasa-academy' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * The learner who made a quiz attempt.
	 *
	 * A quiz attempt is identified everywhere by its statistic_ref_id, which
	 * carries no group and no course. This is the only thing that turns that
	 * number back into a person, and therefore the only thing that lets the
	 * scope rules apply to it.
	 *
	 * @param int $ref_id LearnDash statistic_ref_id.
	 * @return int Learner user ID, or 0 when the attempt does not exist.
	 */
	private static function learner_for_quiz_attempt( $ref_id ) {
		global $wpdb;

		$ref_id = absint( $ref_id );

		if ( ! $ref_id ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}learndash_pro_quiz_statistic_ref WHERE statistic_ref_id = %d",
				$ref_id
			)
		);
	}

	/**
	 * Whether the current user is one this guard has to narrow.
	 *
	 * @return bool
	 */
	private static function is_scoped_reviewer() {
		$user_id = get_current_user_id();

		if ( ! $user_id || Kasa_Scope::is_unrestricted( $user_id ) ) {
			return false;
		}

		return user_can( $user_id, 'wpProQuiz_show' );
	}

	/**
	 * Keep the Advanced Quiz screen to reviewing a facilitator's own learners.
	 *
	 * The matrix gives a facilitator "See a learner's quiz answers, own
	 * groups". LearnDash keeps those answers on its Advanced Quiz screen, which
	 * it registers with the wpProQuiz_show capability, so reaching them at all
	 * means holding that capability. It opens far more than the answers: the
	 * same page routes to the quiz builder, the question editor, import and
	 * export, and the global quiz settings, none of which a facilitator may
	 * touch under "Create or edit courses, lessons, quizzes — No".
	 *
	 * So the capability is granted and the page is narrowed here to the one
	 * module it was granted for. Everything else on it is refused.
	 *
	 * @return void
	 */
	public static function guard_quiz_statistics_screen() {
		if ( self::admin_request_is_exempt() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the requested screen to decide access.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		// Compared without case, and deliberately not through sanitize_key():
		// that lowercases its input, so ldAdvQuiz becomes ldadvquiz and the
		// comparison never matches. The guard then does nothing at all, while
		// looking exactly like a guard.
		if ( 0 !== strcasecmp( 'ldAdvQuiz', $page ) ) {
			return;
		}

		if ( ! self::is_scoped_reviewer() ) {
			return;
		}

		$module = isset( $_GET['module'] ) ? sanitize_text_field( wp_unslash( $_GET['module'] ) ) : 'overallView';

		if ( 'statistics' !== $module ) {
			wp_die(
				esc_html__( 'That part of the quiz screen is for administrators. You can review your own learners\' quiz answers from your group.', 'kasa-academy' ),
				esc_html__( 'Access denied', 'kasa-academy' ),
				array( 'response' => 403 )
			);
		}

		// When one attempt is named, it has to belong to one of their learners.
		$ref_id = 0;

		foreach ( array( 'ref_id', 'refId', 'statistic_ref_id' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$ref_id = absint( wp_unslash( $_GET[ $key ] ) );
				break;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $ref_id ) {
			return;
		}

		self::deny_unless_own_learner( self::learner_for_quiz_attempt( $ref_id ) );
	}

	/**
	 * The same rule for the requests that actually carry the answers back.
	 *
	 * The statistics screen is a shell. The attempt, its score and every
	 * individual answer arrive over admin-ajax, addressed by refId and userId,
	 * so guarding only the page would leave the data one request away. These
	 * are the calls a tampering user would edit.
	 *
	 * @return void
	 */
	public static function guard_quiz_statistics_ajax() {
		if ( ! self::is_scoped_reviewer() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- LearnDash verifies its own nonce; this only reads the payload to decide access.
		$func = isset( $_POST['func'] ) ? sanitize_text_field( wp_unslash( $_POST['func'] ) ) : '';

		if ( 0 !== strpos( $func, 'statistic' ) ) {
			return;
		}

		// Resetting somebody's attempt is destroying a record of a child's
		// work. Administrators only, whoever it belongs to.
		if ( in_array( $func, array( 'statisticReset', 'statisticResetNew' ), true ) ) {
			self::deny_ajax();
		}

		$data = isset( $_POST['data'] ) && is_array( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$learners = array();

		if ( ! empty( $data['refId'] ) ) {
			$learner = self::learner_for_quiz_attempt( $data['refId'] );

			// An attempt that cannot be resolved to a person cannot be checked
			// against the scope rules, so it is refused rather than guessed at.
			if ( ! $learner ) {
				self::deny_ajax();
			}

			$learners[] = $learner;
		}

		if ( ! empty( $data['userId'] ) ) {
			$learners[] = absint( $data['userId'] );
		}

		/*
		 * A statistics call naming nobody asks for the whole quiz's attempts,
		 * across every group on the platform. There is no way to narrow that
		 * to one facilitator's learners without rewriting LearnDash's own
		 * listing, so it is refused. Facilitators reach an attempt through
		 * their group, where the learner is already known.
		 */
		if ( empty( $learners ) ) {
			self::deny_ajax();
		}

		foreach ( $learners as $learner ) {
			self::deny_unless_own_learner( $learner, true );
		}
	}

	/**
	 * Refuse unless the learner is inside the current user's scope.
	 *
	 * @param int  $learner_id Learner user ID.
	 * @param bool $is_ajax    Whether to answer as AJAX.
	 * @return void
	 */
	private static function deny_unless_own_learner( $learner_id, $is_ajax = false ) {
		$learner_id = absint( $learner_id );

		if ( $learner_id && kasa_user_can_see_learner( $learner_id, get_current_user_id() ) ) {
			return;
		}

		if ( $is_ajax ) {
			self::deny_ajax();
		}

		wp_die(
			esc_html__( 'That quiz attempt belongs to a learner outside your groups.', 'kasa-academy' ),
			esc_html__( 'Access denied', 'kasa-academy' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * End an AJAX request without giving anything away.
	 *
	 * @return void
	 */
	private static function deny_ajax() {
		wp_send_json_error(
			array( 'message' => __( 'That is outside your groups.', 'kasa-academy' ) ),
			403
		);
	}

	/**
	 * Requests that must keep working even for a learner.
	 *
	 * Loginly and LearnDash both post to these from the front end. Blocking
	 * them would break sign in and course interaction rather than protect
	 * anything, because neither renders an admin screen.
	 *
	 * @return bool
	 */
	private static function admin_request_is_exempt() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		global $pagenow;

		return in_array( $pagenow, array( 'admin-ajax.php', 'admin-post.php' ), true );
	}

	/**
	 * Whether a user has any business in wp-admin.
	 *
	 * Facilitators and partners are allowed in because the capability matrix
	 * says so: "Access wp-admin — Limited, own group screens only" for a
	 * facilitator, "Limited, own cohort screens only" for a partner. Limited is
	 * what they get; every screen outside their own groups is refused, and the
	 * verification suite checks each one.
	 *
	 * There is a reasonable argument for letting neither of them near wp-admin
	 * at all once they have a front end panel of their own, and this is the one
	 * place that would change. It is not this milestone's call to make, because
	 * the panel does not exist yet and shutting the door today would leave a
	 * facilitator unable to add a learner to their own group, which the matrix
	 * requires. The filter below exists so the switch is a line of
	 * configuration rather than an edit to this file.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function may_use_admin( $user_id ) {
		$allowed = Kasa_Scope::is_unrestricted( $user_id )
			|| user_can( $user_id, 'kasa_view_group_learners' );

		/**
		 * Filters whether a user may reach wp-admin.
		 *
		 * Return false for facilitators and partners once their front end
		 * panel replaces the admin screens they use today.
		 *
		 * @param bool $allowed Whether wp-admin is permitted.
		 * @param int  $user_id User being checked.
		 */
		return (bool) apply_filters( 'kasa_may_use_admin', $allowed, $user_id );
	}

	/**
	 * Hide the admin bar from anyone who cannot use wp-admin.
	 *
	 * Cosmetic next to the redirect above, but a toolbar full of links that
	 * all bounce back to the home page is a confusing thing to show a child.
	 *
	 * @param bool $show Whether to show the bar.
	 * @return bool
	 */
	public static function filter_admin_bar( $show ) {
		if ( ! is_user_logged_in() ) {
			return $show;
		}

		return self::may_use_admin( get_current_user_id() ) ? $show : false;
	}

	/**
	 * Send someone away from content they may not see.
	 *
	 * Matches what LearnDash does when it refuses, so a denial looks the same
	 * whichever layer refused it.
	 *
	 * @return void
	 */
	private static function deny() {
		/**
		 * Filters where a blocked request is sent.
		 *
		 * @param string $url Redirect target.
		 */
		$url = apply_filters( 'kasa_denied_redirect_url', home_url( '/' ) );

		wp_safe_redirect( $url );
		exit;
	}
}
