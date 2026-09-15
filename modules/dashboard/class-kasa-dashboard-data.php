<?php
/**
 * What the dashboard shows, gathered in one place.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads LearnDash so the views do not have to.
 *
 * Every method returns plain arrays. Nothing here echoes, and nothing here
 * decides who may see what: the group and cohort methods take their list of
 * groups from Kasa_Scope, which is the only place that answers that question.
 */
class Kasa_Dashboard_Data {

	/**
	 * The colour a course was given, falling back to the Academy defaults.
	 *
	 * Courses carry kasa_color_primary and kasa_color_secondary, set per course
	 * in the editor, and the course page already paints itself with them. The
	 * dashboard uses the same values so a programme card and the course it
	 * opens are recognisably the same thing.
	 *
	 * @param int $course_id Course.
	 * @return array primary, secondary, rgb, emoji.
	 */
	public static function course_colours( $course_id ) {
		$primary   = self::hex( get_post_meta( $course_id, 'kasa_color_primary', true ), '#6366F1' );
		$secondary = self::hex( get_post_meta( $course_id, 'kasa_color_secondary', true ), '#22D3EE' );
		$emoji     = (string) get_post_meta( $course_id, 'kasa_emoji', true );

		return array(
			'primary'   => $primary,
			'secondary' => $secondary,
			'rgb'       => self::hex_to_rgb( $primary ),
			'emoji'     => '' !== $emoji ? $emoji : '📘',
		);
	}

	/**
	 * Sanitise a hex colour.
	 *
	 * @param mixed  $value    Raw meta value.
	 * @param string $fallback Colour to use when the value is unusable.
	 * @return string
	 */
	private static function hex( $value, $fallback ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		return preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? $value : $fallback;
	}

	/**
	 * "99,102,241", for the translucent borders and shadows.
	 *
	 * @param string $hex Six digit hex colour.
	 * @return string
	 */
	private static function hex_to_rgb( $hex ) {
		$hex = ltrim( $hex, '#' );

		return implode(
			',',
			array(
				hexdec( substr( $hex, 0, 2 ) ),
				hexdec( substr( $hex, 2, 2 ) ),
				hexdec( substr( $hex, 4, 2 ) ),
			)
		);
	}

	/**
	 * How far a learner has got through a course.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 * @return array completed, total, percent, status, status_label.
	 */
	public static function course_progress( $user_id, $course_id ) {
		$completed = 0;
		$total     = 0;

		if ( function_exists( 'learndash_user_get_course_progress' ) ) {
			$summary = learndash_user_get_course_progress( $user_id, $course_id, 'summary' );

			if ( is_array( $summary ) ) {
				$completed = isset( $summary['completed'] ) ? (int) $summary['completed'] : 0;
				$total     = isset( $summary['total'] ) ? (int) $summary['total'] : 0;
			}
		}

		$status = function_exists( 'learndash_course_status' )
			? (string) learndash_course_status( $course_id, $user_id, true )
			: '';

		return array(
			'completed'    => $completed,
			'total'        => $total,
			'percent'      => $total > 0 ? (int) round( ( $completed / $total ) * 100 ) : 0,
			'status'       => $status,
			'status_label' => self::status_label( $status ),
		);
	}

	/**
	 * Words a child understands, rather than LearnDash's own labels.
	 *
	 * @param string $slug Status slug.
	 * @return string
	 */
	private static function status_label( $slug ) {
		$labels = array(
			'completed'   => __( 'Finished', 'kasa-academy' ),
			'in_progress' => __( 'In progress', 'kasa-academy' ),
			'not_started' => __( 'Not started yet', 'kasa-academy' ),
		);

		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : __( 'In progress', 'kasa-academy' );
	}

	/**
	 * The one thing this learner should do next in a course.
	 *
	 * LearnDash has learndash_user_progress_get_first_incomplete_step(), and it
	 * is the right starting point, but it can hand back the course itself
	 * rather than a step inside it. That happens on a course whose progress is
	 * uneven, for instance a quiz finished while a lesson before it is not, and
	 * "continue" pointing at the page you are already on is no help at all. So
	 * when the answer is not a real step, the course's own step list is walked
	 * for the first one not yet done.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 * @return array|null id, title, url, or null when the course is finished.
	 */
	public static function next_step( $user_id, $course_id ) {
		$step_id = 0;

		if ( function_exists( 'learndash_user_progress_get_first_incomplete_step' ) ) {
			$step_id = (int) learndash_user_progress_get_first_incomplete_step( $user_id, $course_id );
		}

		if ( $step_id === (int) $course_id ) {
			$step_id = 0;
		}

		if ( ! $step_id ) {
			$step_id = self::first_unfinished_step( $user_id, $course_id );
		}

		if ( ! $step_id ) {
			return null;
		}

		return array(
			'id'    => $step_id,
			'title' => get_the_title( $step_id ),
			'url'   => (string) get_permalink( $step_id ),
		);
	}

	/**
	 * Walk the course's steps and return the first one not completed.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 * @return int Step post ID, or 0.
	 */
	private static function first_unfinished_step( $user_id, $course_id ) {
		if ( ! function_exists( 'learndash_user_get_course_progress' ) ) {
			return 0;
		}

		// The 'co' shape is keyed "post_type:id" and ordered as the course is.
		$steps = learndash_user_get_course_progress( $user_id, $course_id, 'co' );

		if ( ! is_array( $steps ) ) {
			return 0;
		}

		foreach ( $steps as $key => $done ) {
			if ( $done ) {
				continue;
			}

			$parts = explode( ':', (string) $key );
			$id    = isset( $parts[1] ) ? absint( $parts[1] ) : 0;

			if ( $id ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Every programme this learner is actually enrolled in.
	 *
	 * A programme they are not enrolled in never appears, whether they reached
	 * it through a group or individually.
	 *
	 * @param int $user_id Learner.
	 * @return array
	 */
	public static function learner_programmes( $user_id ) {
		if ( ! function_exists( 'learndash_user_get_enrolled_courses' ) ) {
			return array();
		}

		$course_ids = array_map( 'absint', (array) learndash_user_get_enrolled_courses( $user_id ) );
		$course_ids = array_filter( array_unique( $course_ids ) );

		$programmes = array();

		foreach ( $course_ids as $course_id ) {
			if ( 'publish' !== get_post_status( $course_id ) ) {
				continue;
			}

			$programmes[] = array_merge(
				array(
					'course_id' => $course_id,
					'title'     => get_the_title( $course_id ),
					'url'       => (string) get_permalink( $course_id ),
					'next_step' => self::next_step( $user_id, $course_id ),
				),
				self::course_colours( $course_id ),
				self::course_progress( $user_id, $course_id )
			);
		}

		// The one they are furthest through comes first, so the card they are
		// most likely to want is the one they see first.
		usort(
			$programmes,
			function ( $a, $b ) {
				if ( $a['percent'] === $b['percent'] ) {
					return strcasecmp( $a['title'], $b['title'] );
				}

				return $b['percent'] - $a['percent'];
			}
		);

		return $programmes;
	}

	/**
	 * The single next thing to do, across every programme.
	 *
	 * Deciding for them is the point. A child looking at four cards and
	 * choosing between them is a child who does nothing, so the dashboard picks
	 * the programme they are furthest into and offers that one step.
	 *
	 * @param array $programmes Result of learner_programmes().
	 * @return array|null
	 */
	public static function headline_next_step( $programmes ) {
		foreach ( $programmes as $programme ) {
			if ( ! empty( $programme['next_step'] ) && 'completed' !== $programme['status'] ) {
				return array(
					'course' => $programme['title'],
					'step'   => $programme['next_step'],
				);
			}
		}

		return null;
	}

	/**
	 * The groups a facilitator leads, or the cohorts a partner owns.
	 *
	 * The list of groups comes from Kasa_Scope and nowhere else, so a group
	 * outside this user's scope cannot appear here however it is reached.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function groups_for( $user_id ) {
		$group_ids = kasa_get_visible_group_ids( $user_id );
		$groups    = array();

		foreach ( $group_ids as $group_id ) {
			$learner_ids = function_exists( 'learndash_get_groups_user_ids' )
				? array_map( 'absint', learndash_get_groups_user_ids( $group_id ) )
				: array();

			$course_ids = function_exists( 'learndash_group_enrolled_courses' )
				? array_map( 'absint', learndash_group_enrolled_courses( $group_id ) )
				: array();

			$roster      = self::group_roster( $learner_ids, $course_ids );
			$totals      = $roster['totals'];
			$first_course = $course_ids ? reset( $course_ids ) : 0;
			$colours      = $first_course
				? self::course_colours( $first_course )
				: array( 'primary' => '#4E6B44', 'secondary' => '#9CAF88', 'rgb' => '78,107,68', 'emoji' => '🌿' );

			$groups[] = array_merge(
				array(
					'group_id'    => $group_id,
					'title'       => get_the_title( $group_id ),
					'learners'    => count( $learner_ids ),
					'course_name' => $first_course ? get_the_title( $first_course ) : '',
					'url'         => admin_url( 'admin.php?page=group_admin_page&group_id=' . $group_id ),
					'roster'      => $roster['learners'],
				),
				$colours,
				$totals
			);
		}

		return $groups;
	}

	/**
	 * Average progress across a group, and how many have not begun.
	 *
	 * @param array $learner_ids Learner user IDs.
	 * @param array $course_ids  Courses the group is enrolled in.
	 * @return array percent, not_started, completed.
	 */
	private static function group_roster( $learner_ids, $course_ids ) {
		$empty_totals = array(
			'percent'     => 0,
			'not_started' => count( $learner_ids ),
			'completed'   => 0,
		);

		if ( empty( $learner_ids ) ) {
			return array(
				'totals'   => $empty_totals,
				'learners' => array(),
			);
		}

		// A group with learners but no course still has a roster: knowing who is
		// in the group is useful before anything has been assigned to them.
		if ( empty( $course_ids ) ) {
			cache_users( $learner_ids );

			return array(
				'totals'   => $empty_totals,
				'learners' => self::name_only_roster( $learner_ids ),
			);
		}

		// One query for the whole cohort instead of two per learner. Without
		// this, a facilitator with three groups of thirty pays sixty round
		// trips before any progress is read, on a connection where that is the
		// difference between a slow page and an unusable one.
		cache_users( $learner_ids );

		$sum         = 0;
		$readings    = 0;
		$not_started = 0;
		$completed   = 0;
		$learners    = array();

		foreach ( $learner_ids as $learner_id ) {
			$learner_sum   = 0;
			$learner_count = 0;
			$all_done      = true;
			$done_courses  = 0;

			foreach ( $course_ids as $course_id ) {
				$progress = self::course_progress( $learner_id, $course_id );

				$learner_sum += $progress['percent'];
				$learner_count++;

				if ( 'completed' === $progress['status'] ) {
					$done_courses++;
				} else {
					$all_done = false;
				}
			}

			$learner_percent = $learner_count ? (int) round( $learner_sum / $learner_count ) : 0;

			$sum += $learner_percent;
			$readings++;

			if ( 0 === $learner_percent ) {
				$not_started++;
			}

			if ( $all_done ) {
				$completed++;
			}

			$status = 'in_progress';

			if ( $all_done ) {
				$status = 'completed';
			} elseif ( 0 === $learner_percent ) {
				$status = 'not_started';
			}

			$learners[] = array(
				'id'           => (int) $learner_id,
				'name'         => self::learner_name( $learner_id ),
				'percent'      => $learner_percent,
				'status'       => $status,
				'status_label' => self::status_label( $status ),
				'done_courses' => $done_courses,
				'courses'      => $learner_count,
			);
		}

		usort(
			$learners,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'totals'   => array(
				'percent'     => $readings ? (int) round( $sum / $readings ) : 0,
				'not_started' => $not_started,
				'completed'   => $completed,
			),
			'learners' => $learners,
		);
	}

	/**
	 * A roster for a group that has no course yet.
	 *
	 * @param array $learner_ids Learner user IDs.
	 * @return array
	 */
	private static function name_only_roster( $learner_ids ) {
		$learners = array();

		foreach ( $learner_ids as $learner_id ) {
			$learners[] = array(
				'id'           => (int) $learner_id,
				'name'         => self::learner_name( $learner_id ),
				'percent'      => 0,
				'status'       => 'not_started',
				'status_label' => __( 'No programme yet', 'kasa-academy' ),
				'done_courses' => 0,
				'courses'      => 0,
			);
		}

		usort(
			$learners,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $learners;
	}

	/**
	 * What to call a learner on the roster.
	 *
	 * The user cache has already been primed for the whole group, so this costs
	 * nothing per learner. Falls back to the login only when there is no display
	 * name at all, and to a plain marker when the account has gone, so a deleted
	 * user leaves a gap in the list rather than an empty row.
	 *
	 * @param int $learner_id Learner.
	 * @return string
	 */
	private static function learner_name( $learner_id ) {
		$user = get_userdata( $learner_id );

		if ( ! $user ) {
			return __( 'Account removed', 'kasa-academy' );
		}

		$name = trim( (string) $user->display_name );

		return '' !== $name ? $name : (string) $user->user_login;
	}

	/* ------------------------------------------------------------------ */
	/* Badges                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Every badge on the site, marked earned or not for this learner.
	 *
	 * Badges are GamiPress achievements, and GamiPress may not be there. None of
	 * its functions are wrapped in function_exists() by the plugin itself, and
	 * its post types unregister the moment it is deactivated, so every call here
	 * is guarded and any absence returns an empty array rather than a fatal. The
	 * dashboard then simply has no badge section, which is the correct outcome
	 * on a site that does not award badges.
	 *
	 * @param int $user_id Learner.
	 * @return array
	 */
	public static function badges( $user_id ) {
		$types = self::badge_types();

		if ( empty( $types ) ) {
			return array();
		}

		// Every badge, not a capped page of them. A cap would be applied by the
		// query, before the earned ones are sorted to the front, so a badge a
		// learner had actually won could fall off the end — and the "3 of 8 won"
		// line, counted from what survived, would quietly disagree with itself.
		// Badge sets are small by nature and get_posts() primes the meta cache
		// for all of them in one query.
		$achievements = gamipress_get_achievements(
			array(
				'post_type'   => $types,
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => array( 'menu_order' => 'ASC', 'date' => 'ASC' ),
			)
		);

		if ( ! is_array( $achievements ) || empty( $achievements ) ) {
			return array();
		}

		$earned    = self::earned_badge_dates( $user_id, $types );
		$hidden    = self::hidden_badge_ids( $types );
		$type_art  = self::achievement_type_images( $types );
		$badges    = array();

		foreach ( $achievements as $achievement ) {
			if ( ! $achievement instanceof WP_Post ) {
				continue;
			}

			$id = (int) $achievement->ID;

			// A hidden achievement is meant to stay secret until it is won.
			if ( in_array( $id, $hidden, true ) && ! isset( $earned[ $id ] ) ) {
				continue;
			}

			$badges[] = array(
				'id'         => $id,
				'title'      => get_the_title( $achievement ),
				'excerpt'    => self::badge_description( $achievement ),
				'image'      => self::badge_image( $id, $type_art ),
				'emoji'      => self::badge_emoji( $id ),
				'primary'    => self::hex( get_post_meta( $id, 'kasa_color_primary', true ), '#4E6B44' ),
				'earned'     => isset( $earned[ $id ] ),
				'earned_on'  => isset( $earned[ $id ] ) ? $earned[ $id ] : 0,
			);
		}

		// Won ones first, so a learner sees what they have before what they have not.
		usort(
			$badges,
			function ( $a, $b ) {
				if ( $a['earned'] !== $b['earned'] ) {
					return $a['earned'] ? -1 : 1;
				}

				return strcasecmp( $a['title'], $b['title'] );
			}
		);

		return $badges;
	}

	/**
	 * The achievement type slugs that are usable right now.
	 *
	 * A type registered in GamiPress but whose post type has not been registered
	 * yet would make get_posts() return nothing useful, so both are required.
	 *
	 * @return array
	 */
	private static function badge_types() {
		if ( ! function_exists( 'gamipress_get_achievement_types' ) || ! function_exists( 'gamipress_get_achievements' ) ) {
			return array();
		}

		$types = gamipress_get_achievement_types();

		if ( ! is_array( $types ) || empty( $types ) ) {
			return array();
		}

		$slugs = array();

		foreach ( array_keys( $types ) as $slug ) {
			if ( post_type_exists( $slug ) ) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * Achievement ID to the time it was earned, for one learner.
	 *
	 * gamipress_get_user_achievements() returns one row per earning, not per
	 * achievement, and it keeps the original keys so the array can be sparse.
	 * Both are handled here rather than at the call site.
	 *
	 * @param int   $user_id Learner.
	 * @param array $types   Achievement type slugs.
	 * @return array
	 */
	private static function earned_badge_dates( $user_id, $types ) {
		if ( ! $user_id || ! function_exists( 'gamipress_get_user_achievements' ) ) {
			return array();
		}

		// Deliberately not passing 'display' => true. That argument makes
		// GamiPress drop earnings for hidden achievements, which would mean a
		// badge marked hidden could never be revealed once it was won: badges()
		// only shows a hidden badge when it appears here, and with 'display' on
		// it never can. Its other two effects — skipping deleted and
		// unpublished achievements — are already covered, because badges()
		// matches these rows against a list of published posts and ignores
		// anything else.
		$rows = gamipress_get_user_achievements(
			array(
				'user_id'          => (int) $user_id,
				'achievement_type' => $types,
				'groupby'          => 'post_id',
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$dates = array();

		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->post_id ) ) {
				continue;
			}

			$dates[ (int) $row->post_id ] = isset( $row->date_earned ) ? (int) $row->date_earned : 0;
		}

		return $dates;
	}

	/**
	 * Achievements marked hidden, which are not shown until they are won.
	 *
	 * @param array $types Achievement type slugs.
	 * @return array
	 */
	private static function hidden_badge_ids( $types ) {
		if ( ! function_exists( 'gamipress_get_hidden_achievement_ids' ) ) {
			return array();
		}

		$hidden = array();

		foreach ( $types as $type ) {
			$ids = gamipress_get_hidden_achievement_ids( $type );

			if ( is_array( $ids ) ) {
				$hidden = array_merge( $hidden, array_map( 'absint', $ids ) );
			}
		}

		return $hidden;
	}

	/**
	 * The fallback picture for each achievement type, looked up once.
	 *
	 * An achievement type may carry a featured image that stands in for every
	 * badge of that type. Resolving it needs a query, so it is done once per
	 * type here rather than once per badge inside the loop.
	 *
	 * @param array $types Achievement type slugs.
	 * @return array Slug to attachment ID.
	 */
	private static function achievement_type_images( $types ) {
		$images = array();

		foreach ( $types as $type ) {
			$post = get_page_by_path( $type, OBJECT, 'achievement-type' );

			$images[ $type ] = $post instanceof WP_Post ? (int) get_post_thumbnail_id( $post->ID ) : 0;
		}

		return $images;
	}

	/**
	 * A badge's picture, if it has one.
	 *
	 * GamiPress's own helper returns an <img> tag rather than a URL, and can
	 * return false, so the attachment is resolved directly with core functions.
	 * Falling back to the achievement type's image matches what GamiPress does.
	 *
	 * @param int   $badge_id Achievement post ID.
	 * @param array $type_art Type slug to attachment ID, from achievement_type_images().
	 * @return string URL, or '' when there is no picture.
	 */
	private static function badge_image( $badge_id, $type_art = array() ) {
		$thumb_id = (int) get_post_thumbnail_id( $badge_id );

		if ( ! $thumb_id ) {
			$type     = get_post_type( $badge_id );
			$thumb_id = isset( $type_art[ $type ] ) ? (int) $type_art[ $type ] : 0;
		}

		if ( ! $thumb_id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $thumb_id, 'medium' );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * The character shown when a badge has no picture.
	 *
	 * Badges here are drawn rather than uploaded: an emoji on the badge's own
	 * colour needs no image file, cannot go missing when media is moved between
	 * servers, and stays sharp at any size. A real picture, once one is set,
	 * still wins.
	 *
	 * @param int $badge_id Achievement post ID.
	 * @return string
	 */
	private static function badge_emoji( $badge_id ) {
		$emoji = (string) get_post_meta( $badge_id, 'kasa_badge_emoji', true );

		return '' !== $emoji ? $emoji : '🏅';
	}

	/**
	 * One line saying what a badge is for.
	 *
	 * @param WP_Post $achievement Achievement post.
	 * @return string
	 */
	private static function badge_description( $achievement ) {
		$text = has_excerpt( $achievement ) ? get_the_excerpt( $achievement ) : $achievement->post_content;
		$text = wp_strip_all_tags( strip_shortcodes( (string) $text ) );

		return wp_trim_words( $text, 14, '…' );
	}

	/* ------------------------------------------------------------------ */
	/* Certificates                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * The certificate for each programme that has one.
	 *
	 * learndash_get_course_certificate_link() returns an empty string for five
	 * different reasons, among them "no certificate is configured" and "this
	 * learner has not finished the course". Those two need opposite treatment —
	 * one is nothing to show, the other is something to work towards — so the
	 * two conditions are tested here rather than inferred from the empty string.
	 *
	 * A course with no certificate configured produces no entry at all, which is
	 * what makes this safe on a site where certificates were never set up.
	 *
	 * @param int   $user_id    Learner.
	 * @param array $programmes Result of learner_programmes().
	 * @return array
	 */
	public static function certificates( $user_id, $programmes ) {
		if ( ! $user_id || empty( $programmes ) ) {
			return array();
		}

		$certificates = array();

		foreach ( $programmes as $programme ) {
			$course_id      = (int) $programme['course_id'];
			$certificate_id = self::certificate_for_course( $course_id );

			if ( ! $certificate_id ) {
				continue;
			}

			$link = '';

			if ( function_exists( 'learndash_get_course_certificate_link' ) ) {
				$link = (string) learndash_get_course_certificate_link( $course_id, $user_id );
			}

			$certificates[] = array(
				'course_id' => $course_id,
				'course'    => $programme['title'],
				'title'     => get_the_title( $certificate_id ),
				'url'       => $link,
				'earned'    => '' !== $link,
				'percent'   => (int) $programme['percent'],
				'primary'   => $programme['primary'],
				'secondary' => $programme['secondary'],
				'rgb'       => $programme['rgb'],
				'emoji'     => $programme['emoji'],
			);
		}

		return $certificates;
	}

	/**
	 * The certificate assigned to a course, if it is a usable one.
	 *
	 * LearnDash keeps this setting in two places and its own reader looks at
	 * only one of them, so the flat meta key is read as a fallback for a course
	 * whose settings were written by something other than LearnDash itself. A
	 * certificate that has been deleted, unpublished, or is not a certificate at
	 * all counts as no certificate.
	 *
	 * @param int $course_id Course.
	 * @return int Certificate post ID, or 0.
	 */
	private static function certificate_for_course( $course_id ) {
		if ( ! post_type_exists( 'sfwd-certificates' ) ) {
			return 0;
		}

		$certificate_id = 0;

		if ( function_exists( 'learndash_get_setting' ) ) {
			$certificate_id = (int) learndash_get_setting( $course_id, 'certificate' );
		}

		if ( ! $certificate_id ) {
			$certificate_id = (int) get_post_meta( $course_id, '_ld_certificate', true );
		}

		if ( ! $certificate_id ) {
			return 0;
		}

		if ( 'sfwd-certificates' !== get_post_type( $certificate_id ) ) {
			return 0;
		}

		if ( 'publish' !== get_post_status( $certificate_id ) ) {
			return 0;
		}

		return $certificate_id;
	}
}
