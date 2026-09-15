<?php
/**
 * The capability map.
 *
 * This file is the single source of truth for who can do what. The role
 * installer reads it, and the written permissions matrix handed to the client
 * is generated from it, so the document can never drift away from the code.
 *
 * Nothing here executes on load. It returns data.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Role slugs, named so the rest of the plugin never types a raw string.
 */
define( 'KASA_ROLE_LEARNER', 'kasa_learner' );
define( 'KASA_ROLE_FACILITATOR', 'group_leader' );
define( 'KASA_ROLE_PARTNER', 'kasa_partner' );
define( 'KASA_ROLE_ADMIN', 'administrator' );

/**
 * Every capability this plugin manages, with the metadata the generated
 * matrix needs.
 *
 * `integration` marks a capability owned by another plugin that we grant on
 * purpose. Those are listed separately in the generated document so a reader
 * can tell a Kasa permission from a LearnDash wiring detail.
 *
 * @return array
 */
function kasa_academy_capability_definitions() {
	return array(

		/* ---------------------------------------------------------------- */
		/* Learning                                                          */
		/* ---------------------------------------------------------------- */

		'kasa_view_dashboard'             => array(
			'label'       => 'View own dashboard',
			'group'       => 'Learning',
			'description' => 'Reach the signed-in landing screen of the Academy.',
			'integration' => false,
		),
		'kasa_view_facilitator_resources' => array(
			'label'       => 'View facilitator only materials',
			'group'       => 'Learning',
			'description' => 'The Technovation Transition Guide, Troubleshooting and Recovery Playbook, Selection Rubric and Facilitator Guide. These carry safeguarding procedures and assessment criteria, so learners must never reach them.',
			'integration' => false,
		),

		/* ---------------------------------------------------------------- */
		/* Applications                                                      */
		/* ---------------------------------------------------------------- */

		'kasa_submit_application'         => array(
			'label'       => 'Submit an application',
			'group'       => 'Applications',
			'description' => 'Start a new application. Partners submit on behalf of an organisation registering a group of children.',
			'integration' => false,
		),
		'kasa_view_own_application'       => array(
			'label'       => 'View own application status',
			'group'       => 'Applications',
			'description' => 'See the state of an application this user submitted.',
			'integration' => false,
		),
		'kasa_review_applications'        => array(
			'label'       => 'Approve or decline an application',
			'group'       => 'Applications',
			'description' => 'Decide on applications from the groups this user leads. Deliberately withheld from partners, who submit but never review.',
			'integration' => false,
		),
		'kasa_select_for_competition'     => array(
			'label'       => 'Mark a learner as selected for competition',
			'group'       => 'Applications',
			'description' => 'Administrator only.',
			'integration' => false,
		),
		'kasa_manage_seasons'             => array(
			'label'       => 'Open or close an application season',
			'group'       => 'Applications',
			'description' => 'Administrator only.',
			'integration' => false,
		),

		/* ---------------------------------------------------------------- */
		/* Group and cohort                                                  */
		/* ---------------------------------------------------------------- */

		'kasa_view_group_learners'        => array(
			'label'       => 'See learners inside own groups',
			'group'       => 'Group and cohort',
			'description' => 'The roster. Always scoped by group membership, never a list of every learner on the platform.',
			'integration' => false,
		),
		'kasa_view_group_detail'          => array(
			'label'       => 'See a learner quiz answers and written reflections',
			'group'       => 'Group and cohort',
			'description' => 'Deliberately withheld from partners. Quiz answers and reflections contain children own words and a partner organisation has no need for them.',
			'integration' => false,
		),
		'kasa_export_group_progress'      => array(
			'label'       => 'Export progress for own groups',
			'group'       => 'Group and cohort',
			'description' => 'Export is still bounded by the same group scope as on-screen data.',
			'integration' => false,
		),
		'kasa_manage_group_members'       => array(
			'label'       => 'Add or remove learners from own groups',
			'group'       => 'Group and cohort',
			'description' => 'Requires the LearnDash setting Group Leader User Management to be on as well. A capability alone is not enough.',
			'integration' => false,
		),
		'kasa_view_org_cohorts'           => array(
			'label'       => 'See own organisation cohorts',
			'group'       => 'Group and cohort',
			'description' => 'Partner scoping. Resolves groups through the kasa_organisation_id link rather than through group leadership alone.',
			'integration' => false,
		),

		/* ---------------------------------------------------------------- */
		/* Content and site                                                  */
		/* ---------------------------------------------------------------- */

		'kasa_manage_resources'           => array(
			'label'       => 'Upload and remove shared documents',
			'group'       => 'Content and site',
			'description' => 'Put a handbook, manual or rulebook on the dashboard for others to download, and take one down again. Facilitators do this from the dashboard itself rather than wp-admin, so this is the only thing standing between a signed-in learner and a file upload: it is granted to facilitators and administrators and to nobody else. Viewing a document is a separate question, answered per document by kasa_view_facilitator_resources.',
			'integration' => false,
		),
		'kasa_manage_academy'             => array(
			'label'       => 'Administer the Academy',
			'group'       => 'Content and site',
			'description' => 'Administrator catch all, used to gate plugin settings screens.',
			'integration' => false,
		),

		/* ---------------------------------------------------------------- */
		/* LearnDash integration                                             */
		/* ---------------------------------------------------------------- */

		'group_leader'                    => array(
			'label'       => 'LearnDash group leader recognition',
			'group'       => 'LearnDash integration',
			'description' => 'LearnDash decides who is a group leader with a capability check, not a role check. learndash_is_group_leader_user() calls user_can( $user, LEARNDASH_GROUP_LEADER_CAPABILITY_CHECK ), and that constant is defined as the string group_leader in learndash-scalar-constants.php. Granting this capability is what makes group reporting work for a custom role. Administrators do not need it, they are recognised through manage_options instead.',
			'integration' => true,
		),
		'propanel_widgets'                => array(
			'label'       => 'ProPanel reporting widgets',
			'group'       => 'LearnDash integration',
			'description' => 'LearnDash grants this once, to the roles that exist at that moment, and records the fact in the learndash_modules_reports_capabilities_granted option. A role created afterwards never receives it, so this plugin grants it explicitly.',
			'integration' => true,
		),
		'wpProQuiz_show'                   => array(
			'label'       => 'Reach the Advanced Quiz screen',
			'group'       => 'LearnDash integration',
			'description' => 'The capability LearnDash registers its Advanced Quiz page with. It is needed to open the statistics module at all, but it also opens the quiz builder, the question editor and the import and export tools, none of which a facilitator may use. Kasa_Guards therefore allows only module=statistics for anyone who is not an administrator and refuses every other module on that page.',
			'integration' => true,
		),
		'wpProQuiz_show_statistics'       => array(
			'label'       => 'Open a quiz attempt and its answers',
			'group'       => 'LearnDash integration',
			'description' => 'What LearnDash requires to open the quiz statistics screen, where a learner\'s individual answers are read. Granted to the Facilitator so the matrix row "See a learner\'s quiz answers, own groups" is actually deliverable, and deliberately withheld from the Implementation Partner. The capability carries no notion of a group, so it is paired with a scope guard in Kasa_Guards that resolves the attempt to the learner who made it and refuses anyone outside their own groups.',
			'integration' => true,
		),
	);
}

/**
 * The roles this plugin defines or extends.
 *
 * `managed`   true when this plugin creates the role and may remove it on
 *             uninstall. False for roles owned by WordPress or LearnDash,
 *             where we only add our own capabilities on top.
 * `rename`    true when the display name should be applied to a role that
 *             already exists. Only the LearnDash group leader is renamed.
 * `base_caps` the non-Kasa capabilities a managed role needs to function.
 *             Ensured present, never used to strip anything.
 * `caps`      the managed capabilities this role is granted. Any managed
 *             capability absent from this list is actively removed from the
 *             role, which is what makes the map authoritative.
 *
 * @return array
 */
function kasa_academy_role_definitions() {
	return array(

		KASA_ROLE_LEARNER     => array(
			'display_name' => 'Learner',
			'managed'      => true,
			'rename'       => false,
			'description'  => 'A child or young person taking part in Academy programmes. The default role for every new registration.',
			'base_caps'    => array(
				'read'    => true,
				'level_0' => true,
			),
			'caps'         => array(
				'kasa_view_dashboard',
				'kasa_submit_application',
				'kasa_view_own_application',
			),
		),

		KASA_ROLE_FACILITATOR => array(
			'display_name' => 'Facilitator',
			'managed'      => false,
			'rename'       => true,
			'description'  => 'Runs a group of learners. This is the LearnDash group_leader role, renamed. Registering a separate kasa_facilitator role would break LearnDash group reporting and leave ProPanel empty, because LearnDash would no longer recognise the user as a group leader.',
			'base_caps'    => array(),
			'caps'         => array(
				'kasa_view_dashboard',
				// Deliberately no kasa_submit_application. The capability matrix
				// says a facilitator does not submit applications, they review
				// them. They keep kasa_view_own_application because many
				// facilitators applied through the facilitator and mentor
				// pathway themselves and can still see that earlier application.
				'kasa_view_own_application',
				'kasa_view_group_learners',
				'kasa_view_group_detail',
				'kasa_export_group_progress',
				'kasa_manage_group_members',
				'kasa_review_applications',
				'kasa_view_facilitator_resources',
				'kasa_manage_resources',
				'group_leader',
				'propanel_widgets',
				'wpProQuiz_show',
				'wpProQuiz_show_statistics',
			),
		),

		KASA_ROLE_PARTNER     => array(
			'display_name' => 'Implementation Partner',
			'managed'      => true,
			'rename'       => false,
			'description'  => 'A school or organisation registering a cohort of children. Sees a roster with completion status for its own cohorts and nothing else.',
			'base_caps'    => array(
				'read'         => true,
				'level_0'      => true,
				// The group_leader capability, not the group_leader role. This is
				// what makes LearnDash treat a partner as a group leader so group
				// reporting is populated.
				//
				// Note what is NOT here. The LearnDash group_leader role also
				// carries read_essays, read_private_essays, edit_essays and the
				// assignment capabilities. In LearnDash an essay is an open
				// response quiz answer, which is exactly the written reflection
				// the capability matrix withholds from partners. Withholding
				// those capabilities keeps a partner out of the wp-admin
				// Submitted Essays screen at edit.php?post_type=sfwd-essays.
				//
				// WARNING, and this is not yet fixed. Withholding them does NOT
				// keep a partner out of a single essay on the front end. The
				// essay post type is registered public and publicly_queryable
				// with a rewrite slug of essay, and the graded and not_graded
				// post statuses are both registered public, so WP_Query never
				// reaches a meta capability check. Access is decided instead by
				// learndash_essay_permissions() in
				// sfwd-lms/includes/quiz/ld-quiz-essays.php, which authorises on
				//
				//     learndash_is_admin_user() || author || learndash_is_group_leader_user()
				//
				// and learndash_is_group_leader_user() is user_can( $user,
				// 'group_leader' ). It never consults read_essays. So the
				// recognition capability granted just above is on its own enough
				// for a partner to open /essay/<slug>/ for a child in their own
				// cohort and read that child's words.
				//
				// Closing this needs a front end guard on template_redirect that
				// denies a single essay to anyone without kasa_view_group_detail
				// who is not the author. It belongs with the rest of the
				// enforcement work, not here, because a capability map cannot fix
				// a check that never looks at capabilities.
				//
				// Related trap for whoever turns on Group Leader User Management:
				// learndash_check_group_leader_course_user_intersect() in
				// ld-groups.php returns true unconditionally when that setting,
				// or Group Management, is set to advanced. Choose basic. On
				// advanced a partner reads every child's essay on the platform
				// rather than only their own cohorts.
				'group_leader' => true,
			),
			'caps'         => array(
				'kasa_view_dashboard',
				'kasa_submit_application',
				'kasa_view_own_application',
				'kasa_view_group_learners',
				'kasa_export_group_progress',
				'kasa_view_org_cohorts',
				'group_leader',
				'propanel_widgets',
			),
		),

		KASA_ROLE_ADMIN       => array(
			'display_name' => 'Administrator',
			'managed'      => false,
			'rename'       => false,
			'description'  => 'Unrestricted. Scoping helpers return every group for an administrator.',
			'base_caps'    => array(),
			'caps'         => array(
				'kasa_view_dashboard',
				'kasa_submit_application',
				'kasa_view_own_application',
				'kasa_view_group_learners',
				'kasa_view_group_detail',
				'kasa_export_group_progress',
				'kasa_manage_group_members',
				'kasa_review_applications',
				'kasa_view_facilitator_resources',
				'kasa_view_org_cohorts',
				'kasa_select_for_competition',
				'kasa_manage_seasons',
				'kasa_manage_resources',
				'kasa_manage_academy',
				// No group_leader capability. LearnDash recognises an
				// administrator through LEARNDASH_ADMIN_CAPABILITY_CHECK, which
				// is manage_options, and learndash_is_group_leader_user()
				// returns false for administrators by design.
				'propanel_widgets',
				'wpProQuiz_show',
				'wpProQuiz_show_statistics',
			),
		),
	);
}
