<?php
/**
 * Scoped progress export.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A progress export a facilitator or a partner can actually use.
 *
 * LearnDash has its own group export, and it is not usable here for two
 * reasons. Its endpoint begins with
 *
 *     if ( ! current_user_can( LEARNDASH_ADMIN_CAPABILITY_CHECK ) ) { wp_die(); }
 *
 * which is manage_options, so a facilitator clicking the Export Progress
 * button LearnDash renders for them gets a silent nothing. And it takes the
 * group from the request without checking the caller has any claim to it, so
 * granting them the capability would not be safe either.
 *
 * The capability matrix promises "Export progress for own groups" to both the
 * Facilitator and the Implementation Partner, so this provides it: the same
 * scope rules as every other screen, resolved through Kasa_Scope, and nothing
 * in the file that the matrix withholds. No quiz answers, no written
 * reflections, no email addresses; a partner is entitled to a roster and
 * completion status and that is what the file contains.
 */
class Kasa_Export {

	/**
	 * Screen slug.
	 *
	 * @var string
	 */
	const SLUG = 'kasa-academy-export';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_kasa_export_progress', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_head', array( __CLASS__, 'hide_learndash_export_buttons' ) );
		add_action( 'admin_init', array( __CLASS__, 'block_learndash_export_fallback' ), 4 );
	}

	/**
	 * Who may export.
	 *
	 * @param int $user_id Optional user ID.
	 * @return bool
	 */
	public static function user_may_export( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		return $user_id && user_can( $user_id, 'kasa_export_group_progress' );
	}

	/**
	 * Build the rows for a set of groups.
	 *
	 * Every group is checked against the caller's scope before anything is
	 * read from it, so a group ID arriving from a request cannot widen the
	 * result.
	 *
	 * @param array $group_ids Requested group IDs. Empty means every group in scope.
	 * @param int   $user_id   User the export is for.
	 * @return array Rows, each a flat array matching self::headers().
	 */
	public static function rows( $group_ids, $user_id ) {
		$visible = kasa_get_visible_group_ids( $user_id );

		if ( empty( $group_ids ) ) {
			$group_ids = $visible;
		} else {
			$group_ids = array_intersect( array_map( 'absint', $group_ids ), $visible );
		}

		$rows = array();

		foreach ( $group_ids as $group_id ) {
			$group_name = get_the_title( $group_id );
			$courses    = function_exists( 'learndash_group_enrolled_courses' )
				? array_map( 'absint', learndash_group_enrolled_courses( $group_id ) )
				: array();
			$learners   = function_exists( 'learndash_get_groups_user_ids' )
				? array_map( 'absint', learndash_get_groups_user_ids( $group_id ) )
				: array();

			foreach ( $learners as $learner_id ) {
				$learner = get_userdata( $learner_id );

				if ( ! $learner instanceof WP_User ) {
					continue;
				}

				$name = trim( $learner->first_name . ' ' . $learner->last_name );

				if ( '' === $name ) {
					$name = $learner->display_name;
				}

				if ( empty( $courses ) ) {
					$rows[] = array( $group_name, $name, '', '', '', '' );
					continue;
				}

				foreach ( $courses as $course_id ) {
					$rows[] = array(
						$group_name,
						$name,
						get_the_title( $course_id ),
						self::progress_percentage( $learner_id, $course_id ) . '%',
						self::course_status( $learner_id, $course_id ),
						self::completed_date( $learner_id, $course_id ),
					);
				}
			}
		}

		return $rows;
	}

	/**
	 * Column headings.
	 *
	 * @return array
	 */
	public static function headers() {
		return array(
			__( 'Group', 'kasa-academy' ),
			__( 'Learner', 'kasa-academy' ),
			__( 'Course', 'kasa-academy' ),
			__( 'Progress', 'kasa-academy' ),
			__( 'Status', 'kasa-academy' ),
			__( 'Completed', 'kasa-academy' ),
		);
	}

	/**
	 * How far through a course a learner is.
	 *
	 * Read from the progress LearnDash itself stores, rather than recounting
	 * steps, so the number in the file is the number on the screen.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 * @return int Whole percent.
	 */
	private static function progress_percentage( $user_id, $course_id ) {
		$progress = get_user_meta( $user_id, '_sfwd-course_progress', true );

		if ( ! is_array( $progress ) || ! isset( $progress[ $course_id ]['total'] ) ) {
			return 0;
		}

		$total = (int) $progress[ $course_id ]['total'];

		if ( $total < 1 ) {
			return 0;
		}

		return (int) round( ( (int) $progress[ $course_id ]['completed'] / $total ) * 100 );
	}

	/**
	 * Not started, In Progress or Completed.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 * @return string
	 */
	private static function course_status( $user_id, $course_id ) {
		if ( ! function_exists( 'learndash_course_status' ) ) {
			return '';
		}

		return (string) learndash_course_status( $course_id, $user_id );
	}

	/**
	 * When the course was finished, if it was.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 * @return string
	 */
	private static function completed_date( $user_id, $course_id ) {
		if ( ! function_exists( 'learndash_user_get_course_completed_date' ) ) {
			return '';
		}

		$stamp = learndash_user_get_course_completed_date( $user_id, $course_id );

		return $stamp ? gmdate( 'Y-m-d', (int) $stamp ) : '';
	}

	/**
	 * Turn rows into CSV.
	 *
	 * @param array $rows Rows.
	 * @return string
	 */
	public static function to_csv( $rows ) {
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return '';
		}

		fputcsv( $handle, self::headers() );

		foreach ( $rows as $row ) {
			fputcsv( $handle, $row );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// A byte order mark, so Excel opens names with accents correctly. West
		// African learner names routinely have them.
		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Send the file.
	 *
	 * @return void
	 */
	public static function handle_download() {
		if ( ! self::user_may_export() ) {
			wp_die(
				esc_html__( 'You do not have permission to export progress.', 'kasa-academy' ),
				esc_html__( 'Access denied', 'kasa-academy' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'kasa_export_progress' );

		$requested = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		if ( isset( $_POST['group_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
			$requested = array( absint( wp_unslash( $_POST['group_id'] ) ) );
		}

		$user_id = get_current_user_id();

		/*
		 * A named group that is not in scope is refused rather than quietly
		 * exported as nothing, so a tampering attempt is visible instead of
		 * looking like an empty group.
		 */
		if ( ! empty( $requested ) && ! kasa_user_can_see_group( $requested[0], $user_id ) ) {
			wp_die(
				esc_html__( 'That group is not one of yours.', 'kasa-academy' ),
				esc_html__( 'Access denied', 'kasa-academy' ),
				array( 'response' => 403 )
			);
		}

		$csv = self::to_csv( self::rows( $requested, $user_id ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="kasa-progress-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'Content-Length: ' . strlen( $csv ) );

		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body.
		exit;
	}

	/**
	 * The export screen.
	 *
	 * @return void
	 */
	public static function render_screen() {
		if ( ! self::user_may_export() ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'kasa-academy' ),
				esc_html__( 'Access denied', 'kasa-academy' ),
				array( 'response' => 403 )
			);
		}

		$user_id   = get_current_user_id();
		$group_ids = kasa_get_visible_group_ids( $user_id );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Progress export', 'kasa-academy' ); ?></h1>

			<p style="max-width:70ch">
				<?php esc_html_e( 'A spreadsheet of the learners in your groups, with how far through each course they are. It contains names, courses, progress and completion dates only, and never quiz answers or written reflections.', 'kasa-academy' ); ?>
			</p>

			<?php if ( empty( $group_ids ) ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'You have no groups yet, so there is nothing to export.', 'kasa-academy' ); ?>
				</p></div>
				<?php return; ?>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:760px;margin-top:16px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Group', 'kasa-academy' ); ?></th>
						<th style="width:110px"><?php esc_html_e( 'Learners', 'kasa-academy' ); ?></th>
						<th style="width:170px"><?php esc_html_e( 'Export', 'kasa-academy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $group_ids as $group_id ) : ?>
						<tr>
							<td><strong><?php echo esc_html( get_the_title( $group_id ) ); ?></strong></td>
							<td><?php echo esc_html( (string) count( learndash_get_groups_user_ids( $group_id ) ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'kasa_export_progress' ); ?>
									<input type="hidden" name="action" value="kasa_export_progress">
									<input type="hidden" name="group_id" value="<?php echo esc_attr( (string) $group_id ); ?>">
									<?php submit_button( __( 'Download CSV', 'kasa-academy' ), 'secondary', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( count( $group_ids ) > 1 ) : ?>
				<p style="margin-top:18px">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'kasa_export_progress' ); ?>
						<input type="hidden" name="action" value="kasa_export_progress">
						<?php submit_button( __( 'Download all my groups', 'kasa-academy' ), 'primary', 'submit', false ); ?>
					</form>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Hide LearnDash's export buttons from anyone they do not work for.
	 *
	 * LearnDash builds those links as a string and echoes them, with no filter
	 * to remove them, so this hides them by the class LearnDash puts on them.
	 * Leaving them visible would be worse than removing the feature: a
	 * facilitator clicks Export Progress, nothing happens, nothing explains
	 * why, and they have no way to know a working export exists elsewhere.
	 *
	 * Administrators keep theirs, since theirs works.
	 *
	 * @return void
	 */
	public static function hide_learndash_export_buttons() {
		if ( Kasa_Scope::is_unrestricted( get_current_user_id() ) ) {
			return;
		}

		if ( ! self::user_may_export() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current screen.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'group_admin_page' !== $page ) {
			return;
		}
		?>
		<style>
			.learndash-data-group-reports-button { display: none !important; }
		</style>
		<script>
			document.addEventListener( 'DOMContentLoaded', function () {
				var cell = document.querySelector( '.column-group_actions, td.group_actions' );
				if ( ! cell ) { return; }
				document.querySelectorAll( '.column-group_actions, td.group_actions' ).forEach( function ( td ) {
					var link = document.createElement( 'a' );
					link.href = <?php echo wp_json_encode( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>;
					link.textContent = <?php echo wp_json_encode( __( 'Export progress', 'kasa-academy' ) ); ?>;
					td.appendChild( document.createTextNode( ' | ' ) );
					td.appendChild( link );
				} );
			} );
		</script>
		<?php
	}

	/**
	 * Refuse LearnDash's non-JavaScript export links too.
	 *
	 * The button is only one of two shapes LearnDash renders. When its data
	 * upgrade settings are incomplete it falls back to plain links carrying
	 * courses_export_submit or quiz_export_submit, which the CSS above would
	 * not hide. Those go to the same administrator-only endpoint, so they are
	 * refused here rather than left as another control that does nothing.
	 *
	 * @return void
	 */
	public static function block_learndash_export_fallback() {
		if ( Kasa_Scope::is_unrestricted( get_current_user_id() ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the request to decide access.
		$is_export = isset( $_GET['courses_export_submit'] ) || isset( $_GET['quiz_export_submit'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $is_export ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
