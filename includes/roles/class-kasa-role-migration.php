<?php
/**
 * Moves existing users onto the Kasa roles and points registration at them.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/capabilities.php';

/**
 * Learner migration.
 *
 * Before this plugin there was no learner role, so learners were plain
 * subscribers. This moves them onto kasa_learner and repoints Loginly, which
 * owns registration, at the same role.
 *
 * Written to be run more than once. It will run against roughly ten real
 * users on production, and the safe assumption is that somebody will run it
 * twice, or run it after adding a few users by hand. Every step therefore
 * checks the current state rather than assuming it.
 */
class Kasa_Role_Migration {

	/**
	 * Bumped when the migration logic itself changes and needs to run again.
	 *
	 * @var string
	 */
	const MIGRATION_VERSION = '1.0.0';

	/**
	 * Option holding the migration version already applied.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'kasa_academy_learner_migration_version';

	/**
	 * User meta recording what a user held before migration, so a mistake can
	 * be walked back.
	 *
	 * @var string
	 */
	const PREVIOUS_ROLES_META = 'kasa_academy_previous_roles';

	/**
	 * The role a user must hold, and only that role, to be migrated.
	 *
	 * @var string
	 */
	const SOURCE_ROLE = 'subscriber';

	/**
	 * Run once per migration version. Hooked to admin_init.
	 *
	 * @return void
	 */
	public static function maybe_run() {
		if ( get_option( self::VERSION_OPTION ) === self::MIGRATION_VERSION ) {
			return;
		}

		self::run();

		update_option( self::VERSION_OPTION, self::MIGRATION_VERSION );
	}

	/**
	 * Do the work.
	 *
	 * @param bool $dry_run When true, report what would change without changing it.
	 * @return array Report of what happened.
	 */
	public static function run( $dry_run = false ) {
		$report = array(
			'dry_run'       => (bool) $dry_run,
			'migrated'      => array(),
			'skipped'       => array(),
			'loginly'       => 'unchanged',
			'role_missing'  => false,
		);

		if ( ! get_role( KASA_ROLE_LEARNER ) instanceof WP_Role ) {
			// Never move a user onto a role that does not exist. That would
			// leave them with no capabilities at all.
			$report['role_missing'] = true;

			return $report;
		}

		$report = self::migrate_subscribers( $report, $dry_run );
		$report = self::sync_loginly_default_role( $report, $dry_run );

		return $report;
	}

	/**
	 * Move plain subscribers onto the learner role.
	 *
	 * Only users whose entire role list is exactly [subscriber] are touched. A
	 * user who is a subscriber and also something else was given that second
	 * role deliberately, and guessing at their intent is how people lose
	 * access.
	 *
	 * @param array $report  Running report.
	 * @param bool  $dry_run Whether to only simulate.
	 * @return array
	 */
	private static function migrate_subscribers( $report, $dry_run ) {
		$users = get_users(
			array(
				'role'   => self::SOURCE_ROLE,
				'fields' => array( 'ID', 'user_login' ),
			)
		);

		foreach ( $users as $user_row ) {
			$user = get_userdata( $user_row->ID );

			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$roles = array_values( $user->roles );

			// Already migrated. Running twice must be harmless.
			if ( in_array( KASA_ROLE_LEARNER, $roles, true ) ) {
				$report['skipped'][] = array(
					'user_login' => $user->user_login,
					'reason'     => 'already a learner',
				);

				continue;
			}

			if ( array( self::SOURCE_ROLE ) !== $roles ) {
				$report['skipped'][] = array(
					'user_login' => $user->user_login,
					'reason'     => 'holds other roles: ' . implode( ', ', $roles ),
				);

				continue;
			}

			if ( ! $dry_run ) {
				// Record first. If anything fails after this point the original
				// role list is still on disk.
				update_user_meta( $user->ID, self::PREVIOUS_ROLES_META, $roles );

				$user->add_role( KASA_ROLE_LEARNER );
				$user->remove_role( self::SOURCE_ROLE );
			}

			$report['migrated'][] = array(
				'user_login' => $user->user_login,
				'from'       => $roles,
				'to'         => KASA_ROLE_LEARNER,
			);
		}

		return $report;
	}

	/**
	 * Point Loginly's registration default at the learner role.
	 *
	 * Loginly, not WordPress, owns registration on this site. Leaving its
	 * default at subscriber would mean every new child who signed up landed
	 * with none of the Kasa capabilities.
	 *
	 * @param array $report  Running report.
	 * @param bool  $dry_run Whether to only simulate.
	 * @return array
	 */
	private static function sync_loginly_default_role( $report, $dry_run ) {
		$settings = get_option( 'loginly_settings' );

		if ( ! is_array( $settings ) ) {
			$report['loginly'] = 'not installed';

			return $report;
		}

		if ( isset( $settings['default_role'] ) && KASA_ROLE_LEARNER === $settings['default_role'] ) {
			$report['loginly'] = 'already correct';

			return $report;
		}

		$report['loginly'] = sprintf(
			'%s -> %s',
			isset( $settings['default_role'] ) ? $settings['default_role'] : '(unset)',
			KASA_ROLE_LEARNER
		);

		if ( ! $dry_run ) {
			$settings['default_role'] = KASA_ROLE_LEARNER;

			update_option( 'loginly_settings', $settings );
		}

		return $report;
	}
}
