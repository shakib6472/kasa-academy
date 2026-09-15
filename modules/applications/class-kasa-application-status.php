<?php
/**
 * Where someone's application has got to.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The seven states an application can be in, and how to ask for one.
 *
 * There is no application system yet — the brief put the forms and the review
 * screen outside Milestone 2, and nothing has been built since. So this asks
 * for a status and, today, nobody answers. That is the whole design:
 *
 *   - the seven states and their wording live here, complete, now
 *   - the answer comes from the kasa_application_status filter
 *   - with nothing hooked to it the dashboard shows a fallback that points at
 *     the programmes, rather than claiming anything about an application
 *
 * When the application system arrives it hooks that one filter and all seven
 * states light up without this file or the dashboard changing.
 *
 * The distinction that matters: not_applied is a fact about a person we have
 * records for. No answer at all is not the same thing, and must never be
 * rendered as "you have not applied" — for all we know they applied on paper
 * last month.
 */
class Kasa_Application_Status {

	/**
	 * The states, in the order an application moves through them.
	 *
	 * @var array
	 */
	const STATES = array(
		'not_applied',
		'submitted',
		'under_review',
		'interview',
		'accepted',
		'waitlisted',
		'declined',
	);

	/**
	 * Where someone is sent when there is nothing to report.
	 *
	 * @var string
	 */
	const FALLBACK_OPTION = 'kasa_academy_application_url';

	/**
	 * This user's application, if anything can tell us about it.
	 *
	 * @param int $user_id Optional. Defaults to the current user.
	 * @return array|null state, since, note. Null when nothing is connected.
	 */
	public static function for_user( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'kasa_view_own_application' ) ) {
			return null;
		}

		/**
		 * Filters the application status for one user.
		 *
		 * @param array|null $status  state, and optionally since and note.
		 * @param int        $user_id Whose application.
		 */
		$status = apply_filters( 'kasa_application_status', null, $user_id );

		if ( ! is_array( $status ) || empty( $status['state'] ) ) {
			return null;
		}

		$state = sanitize_key( $status['state'] );

		// An unrecognised state is the same as no answer. Rendering a status we
		// do not have wording for would tell a child something we did not mean.
		if ( ! in_array( $state, self::STATES, true ) ) {
			return null;
		}

		return array(
			'state' => $state,
			'since' => isset( $status['since'] ) ? absint( $status['since'] ) : 0,
			'note'  => isset( $status['note'] ) ? sanitize_text_field( (string) $status['note'] ) : '',
		);
	}

	/**
	 * How each state is put to a child.
	 *
	 * Plain, short, and never cruel. "Not this time" is a real outcome for a
	 * fourteen year old who wanted this, so it says what happened and what they
	 * can do next, in that order.
	 *
	 * @return array
	 */
	public static function wording() {
		return array(
			'not_applied'  => array(
				'pill'  => __( 'Not applied yet', 'kasa-academy' ),
				'head'  => __( 'Fancy joining a programme?', 'kasa-academy' ),
				'body'  => __( 'Have a look at what is running and put your name forward.', 'kasa-academy' ),
				'mark'  => '&#128075;',
				'tone'  => 'open',
				'cta'   => true,
			),
			'submitted'    => array(
				'pill'  => __( 'Sent', 'kasa-academy' ),
				'head'  => __( 'Your application is in', 'kasa-academy' ),
				'body'  => __( 'We have it safely. There is nothing you need to do right now.', 'kasa-academy' ),
				'mark'  => '&#128228;',
				'tone'  => 'waiting',
				'cta'   => false,
			),
			'under_review' => array(
				'pill'  => __( 'Being read', 'kasa-academy' ),
				'head'  => __( 'Someone is reading it', 'kasa-academy' ),
				'body'  => __( 'A facilitator is going through your application now.', 'kasa-academy' ),
				'mark'  => '&#128269;',
				'tone'  => 'waiting',
				'cta'   => false,
			),
			'interview'    => array(
				'pill'  => __( 'Let us talk', 'kasa-academy' ),
				'head'  => __( 'We would like to meet you', 'kasa-academy' ),
				'body'  => __( 'Watch for a message about when. Bring your questions.', 'kasa-academy' ),
				'mark'  => '&#128172;',
				'tone'  => 'action',
				'cta'   => false,
			),
			'accepted'     => array(
				'pill'  => __( 'Accepted', 'kasa-academy' ),
				'head'  => __( 'You are in', 'kasa-academy' ),
				'body'  => __( 'Welcome to the Academy. Your programme will appear on this dashboard.', 'kasa-academy' ),
				'mark'  => '&#127881;',
				'tone'  => 'good',
				'cta'   => false,
			),
			'waitlisted'   => array(
				'pill'  => __( 'Waiting list', 'kasa-academy' ),
				'head'  => __( 'You are on the waiting list', 'kasa-academy' ),
				'body'  => __( 'There was not a place this time. If one opens, you are next.', 'kasa-academy' ),
				'mark'  => '&#9203;',
				'tone'  => 'waiting',
				'cta'   => false,
			),
			'declined'     => array(
				'pill'  => __( 'Not this time', 'kasa-academy' ),
				'head'  => __( 'Not this time', 'kasa-academy' ),
				'body'  => __( 'This round did not work out. You can apply again when the next one opens, and we would like you to.', 'kasa-academy' ),
				'mark'  => '&#127793;',
				'tone'  => 'closed',
				'cta'   => true,
			),
		);
	}

	/**
	 * What the dashboard says while no application system is connected.
	 *
	 * @return array
	 */
	public static function fallback() {
		return array(
			'pill' => __( 'Applications', 'kasa-academy' ),
			'head' => __( 'Applications open soon', 'kasa-academy' ),
			'body' => __( 'When they do, you will be able to follow yours right here. In the meantime, see what is running.', 'kasa-academy' ),
			'mark' => '&#128220;',
			'tone' => 'open',
			'cta'  => true,
		);
	}

	/**
	 * Where the button goes.
	 *
	 * There is no application form yet, so this points at the programmes, which
	 * is where somebody wanting to apply should look first. The option lets an
	 * administrator move it to the real form the day it exists, without a code
	 * change.
	 *
	 * @return string
	 */
	public static function action_url() {
		$stored = (string) get_option( self::FALLBACK_OPTION, '' );

		if ( '' !== $stored ) {
			return esc_url_raw( $stored );
		}

		return (string) apply_filters( 'kasa_application_action_url', home_url( '/programs/' ) );
	}

	/**
	 * The words on the button.
	 *
	 * @return string
	 */
	public static function action_label() {
		return __( 'See the programmes', 'kasa-academy' );
	}
}
