<?php
/**
 * The written permissions matrix, generated from the capability map.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns the capability map into the document promised to the client.
 *
 * Definition of Done point 10 asks for the matrix to be generated from the
 * code rather than typed by hand, and the reason is worth stating: a
 * hand-written permissions document is correct on the day it is written and
 * quietly wrong from then on. This one cannot disagree with the plugin,
 * because it is the same array the role installer reads.
 */
class Kasa_Permissions_Matrix {

	/**
	 * Where the generated file is kept.
	 *
	 * @return string
	 */
	public static function file_path() {
		return KASA_ACADEMY_PATH . 'PERMISSIONS.md';
	}

	/**
	 * Capability groups, in the order the brief presents them.
	 *
	 * Derived from the map rather than listed here, so a new group appears on
	 * its own.
	 *
	 * @return array
	 */
	private static function groups() {
		$order = array( 'Learning', 'Applications', 'Group and cohort', 'Content and site', 'LearnDash integration' );
		$found = array();

		foreach ( kasa_academy_capability_definitions() as $definition ) {
			if ( ! in_array( $definition['group'], $found, true ) ) {
				$found[] = $definition['group'];
			}
		}

		$ordered = array_values( array_intersect( $order, $found ) );

		// Anything not in the known order still gets listed, at the end.
		return array_merge( $ordered, array_values( array_diff( $found, $ordered ) ) );
	}

	/**
	 * Does a role hold a capability?
	 *
	 * Read from the map, not from the database, so the document describes what
	 * the plugin intends. Kasa_Roles is what makes the database agree, and the
	 * verification suite is what proves it did.
	 *
	 * @param array  $role       Role definition.
	 * @param string $capability Capability slug.
	 * @return bool
	 */
	private static function role_has( $role, $capability ) {
		return in_array( $capability, $role['caps'], true );
	}

	/**
	 * Build the markdown.
	 *
	 * @return string
	 */
	public static function to_markdown() {
		$capabilities = kasa_academy_capability_definitions();
		$roles        = kasa_academy_role_definitions();

		$lines = array();

		$lines[] = '# Kasa Academy — permissions matrix';
		$lines[] = '';
		$lines[] = 'Every role in the Kasa Learning Academy, and what each one may do.';
		$lines[] = '';
		$lines[] = '**This file is generated.** It is built from the capability map in';
		$lines[] = '`includes/roles/capabilities.php`, which is the same array the role';
		$lines[] = 'installer reads, so it cannot drift away from what the plugin actually';
		$lines[] = 'does. Editing it by hand achieves nothing; change the map and';
		$lines[] = 'regenerate from **Kasa Academy → Permissions matrix**.';
		$lines[] = '';
		$lines[] = sprintf(
			'Plugin version %s · roles version %s · generated %s',
			KASA_ACADEMY_VERSION,
			KASA_ROLES_VERSION,
			gmdate( 'j F Y' )
		);
		$lines[] = '';

		/* -------------------------------------------------------------- */
		/* Roles                                                           */
		/* -------------------------------------------------------------- */

		$lines[] = '## The four roles';
		$lines[] = '';
		$lines[] = '| Role | Slug | Notes |';
		$lines[] = '| --- | --- | --- |';

		foreach ( $roles as $slug => $role ) {
			$lines[] = sprintf(
				'| %s | `%s` | %s |',
				$role['display_name'],
				$slug,
				str_replace( '|', '\|', $role['description'] )
			);
		}

		$lines[] = '';

		/* -------------------------------------------------------------- */
		/* The matrix                                                      */
		/* -------------------------------------------------------------- */

		$header    = '| Capability |';
		$separator = '| --- |';

		foreach ( $roles as $role ) {
			$header    .= ' ' . $role['display_name'] . ' |';
			$separator .= ' :-: |';
		}

		foreach ( self::groups() as $group ) {
			$lines[] = '## ' . $group;
			$lines[] = '';
			$lines[] = $header;
			$lines[] = $separator;

			foreach ( $capabilities as $capability => $definition ) {
				if ( $definition['group'] !== $group ) {
					continue;
				}

				$row = sprintf( '| %s<br>`%s` |', $definition['label'], $capability );

				foreach ( $roles as $role ) {
					$row .= self::role_has( $role, $capability ) ? ' Yes |' : ' — |';
				}

				$lines[] = $row;
			}

			$lines[] = '';

			// The notes below each table are where the reasoning lives, and
			// they come from the map too.
			foreach ( $capabilities as $capability => $definition ) {
				if ( $definition['group'] !== $group || empty( $definition['description'] ) ) {
					continue;
				}

				$lines[] = sprintf( '- **%s** — %s', $definition['label'], $definition['description'] );
			}

			$lines[] = '';
		}

		/* -------------------------------------------------------------- */
		/* Scoping                                                         */
		/* -------------------------------------------------------------- */

		$lines[] = '## Scoping';
		$lines[] = '';
		$lines[] = 'A capability says what kind of thing a role may do. It does not say';
		$lines[] = 'whose learners they may do it to. Every question of the second kind is';
		$lines[] = 'answered in one place, `Kasa_Scope`, and every screen asks it rather';
		$lines[] = 'than working it out again.';
		$lines[] = '';
		$lines[] = '| Role | Sees |';
		$lines[] = '| --- | --- |';
		$lines[] = '| ' . $roles[ KASA_ROLE_LEARNER ]['display_name'] . ' | No groups. Their own work only. |';
		$lines[] = '| ' . $roles[ KASA_ROLE_FACILITATOR ]['display_name'] . ' | The groups they lead, through LearnDash group leadership. |';
		$lines[] = '| ' . $roles[ KASA_ROLE_PARTNER ]['display_name'] . ' | The groups carrying their organisation, and nothing else. Leader assignments are ignored for partners on purpose, so one wrong assignment cannot widen what they see. |';
		$lines[] = '| ' . $roles[ KASA_ROLE_ADMIN ]['display_name'] . ' | Everything. |';
		$lines[] = '';
		$lines[] = 'Everything fails closed. A partner with no organisation, an organisation';
		$lines[] = 'that was deleted, a signed out visitor, an unrecognised role: all resolve';
		$lines[] = 'to no groups, which callers must read as *none* and never as *all*.';
		$lines[] = '';
		$lines[] = '## What partners deliberately cannot see';
		$lines[] = '';
		$lines[] = 'A partner organisation gets a roster and completion status. It does not';
		$lines[] = 'get quiz answers or written reflections, because those are children\'s own';
		$lines[] = 'words and a partner has no need of them. In LearnDash a written reflection';
		$lines[] = 'is stored as an essay, and an uploaded piece of work as an assignment;';
		$lines[] = 'both are refused to partners on the front end and in wp-admin.';
		$lines[] = '';
		$lines[] = '> This was a judgement call, flagged in the brief for confirmation. It is';
		$lines[] = '> built as described and can be widened if the client asks.';
		$lines[] = '';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Write the file next to the plugin.
	 *
	 * Best effort. A deployment with a read-only plugin directory is a good
	 * thing, not a problem to work around, and the screen can still hand the
	 * document over as a download.
	 *
	 * @return bool
	 */
	public static function write_file() {
		return false !== file_put_contents( self::file_path(), self::to_markdown() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}
