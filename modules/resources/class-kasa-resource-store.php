<?php
/**
 * Where uploaded documents are kept, and how they get in and out.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The files themselves.
 *
 * A document on this dashboard can be facilitator-only, and some of them carry
 * safeguarding procedures and assessment criteria. So the file must not be
 * readable by anyone who happens to have the URL, which is exactly what an
 * ordinary WordPress upload is. Three things keep that from happening:
 *
 *  1. Files are written to their own directory under uploads, with an .htaccess
 *     that denies direct access and an index.php so the directory cannot be
 *     listed.
 *  2. Each file is stored under a random 32 character name, so on a server that
 *     ignores .htaccess — nginx does — the URL still cannot be guessed. The
 *     original filename is kept in post meta and given back at download time.
 *  3. Reading always goes through Kasa_Resources_Module, which checks the
 *     capability before this class is asked for the file at all.
 *
 * Nothing here decides who may read anything. It only stores and streams.
 */
class Kasa_Resource_Store {

	/**
	 * Directory name under uploads.
	 *
	 * @var string
	 */
	const DIR = 'kasa-resources';

	/**
	 * What a facilitator is allowed to upload.
	 *
	 * Documents, and nothing that any server could be persuaded to execute. The
	 * list is deliberately short: this is a front-end upload, reachable by every
	 * facilitator, and each extra type is another parser to be wrong about.
	 *
	 * @return array Extension to MIME type, in the shape wp_handle_upload wants.
	 */
	public static function allowed_types() {
		return apply_filters(
			'kasa_resource_allowed_types',
			array(
				'pdf'  => 'application/pdf',
				'doc'  => 'application/msword',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'ppt'  => 'application/vnd.ms-powerpoint',
				'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			)
		);
	}

	/**
	 * The largest file we will take.
	 *
	 * @return int Bytes.
	 */
	public static function max_bytes() {
		$ceiling = 20 * MB_IN_BYTES;
		$server  = (int) wp_max_upload_size();

		// The server's own limit still wins when it is the lower of the two.
		return (int) apply_filters( 'kasa_resource_max_bytes', $server > 0 ? min( $ceiling, $server ) : $ceiling );
	}

	/**
	 * The directory documents live in, created and protected if it is not there.
	 *
	 * @return array path, url, and whether it could be prepared.
	 */
	public static function directory() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return array(
				'path'  => '',
				'ready' => false,
			);
		}

		$path = trailingslashit( $uploads['basedir'] ) . self::DIR;

		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return array(
				'path'  => '',
				'ready' => false,
			);
		}

		self::protect( $path );

		return array(
			'path'  => $path,
			'ready' => true,
		);
	}

	/**
	 * Write the guards that keep the directory from being browsed or served.
	 *
	 * Apache and LiteSpeed both honour .htaccess. Nginx does not, which is why
	 * the filenames are random as well; this is the second lock, not the only
	 * one.
	 *
	 * @param string $path Directory.
	 * @return void
	 */
	private static function protect( $path ) {
		$htaccess = trailingslashit( $path ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Kasa Academy. These documents are served through PHP after a\n"
				. "# capability check. Direct access is denied on purpose.\n"
				. "<IfModule mod_authz_core.c>\n"
				. "\tRequire all denied\n"
				. "</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n"
				. "\tOrder allow,deny\n"
				. "\tDeny from all\n"
				. "</IfModule>\n";

			@file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$index = trailingslashit( $path ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Take an uploaded file and put it somewhere safe.
	 *
	 * The upload itself is handed to wp_handle_upload() rather than moved by
	 * hand, so WordPress's own checks run: it verifies the file really was
	 * uploaded, and it re-reads the extension and the contents together through
	 * wp_check_filetype_and_ext() instead of believing the browser's
	 * Content-Type. Our allowlist is passed in as the only acceptable answer.
	 *
	 * @param array $file One entry from $_FILES.
	 * @return array|WP_Error stored, name, size, extension.
	 */
	public static function save( $file ) {
		if ( ! is_array( $file ) || ! isset( $file['name'], $file['tmp_name'] ) ) {
			return new WP_Error( 'kasa_no_file', __( 'No file arrived. Please choose one and try again.', 'kasa-academy' ) );
		}

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'kasa_upload_error', self::upload_error_message( (int) $file['error'] ) );
		}

		if ( isset( $file['size'] ) && (int) $file['size'] > self::max_bytes() ) {
			return new WP_Error(
				'kasa_too_big',
				sprintf(
					/* translators: %s: the largest allowed file size, already formatted. */
					__( 'That file is too big. The limit is %s.', 'kasa-academy' ),
					size_format( self::max_bytes() )
				)
			);
		}

		// Checked here as well as inside wp_handle_upload, because the wrong file
		// type is much the commonest mistake and WordPress answers it with
		// "Sorry, you are not allowed to upload this file type" — which reads
		// like the facilitator's account is at fault rather than the file, and
		// never says what would work instead.
		$extension = strtolower( (string) pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );

		if ( ! array_key_exists( $extension, self::allowed_types() ) ) {
			return new WP_Error(
				'kasa_wrong_type',
				sprintf(
					/* translators: %s: a list of file extensions, e.g. "PDF, DOC, DOCX". */
					__( 'That kind of file cannot go on the dashboard. Save it as %s and try again.', 'kasa-academy' ),
					strtoupper( implode( ', ', array_keys( self::allowed_types() ) ) )
				)
			);
		}

		$directory = self::directory();

		if ( ! $directory['ready'] ) {
			return new WP_Error( 'kasa_no_directory', __( 'The documents folder could not be prepared on the server.', 'kasa-academy' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$original = sanitize_file_name( (string) $file['name'] );

		// Send this one upload into our own directory, and give it a name that
		// cannot be guessed. The real name goes into post meta instead.
		$redirect = function ( $dirs ) use ( $directory ) {
			$dirs['path']   = $directory['path'];
			$dirs['url']    = '';
			$dirs['subdir'] = '';

			return $dirs;
		};

		$rename = function ( $name, $ext ) {
			return wp_generate_password( 32, false, false ) . $ext;
		};

		add_filter( 'upload_dir', $redirect );
		add_filter( 'wp_unique_filename', $rename, 10, 2 );

		$result = wp_handle_upload(
			$file,
			array(
				// This form is not a wp-admin form, so WordPress's own
				// action-field check would fail. The nonce and the capability
				// are verified by the caller before we get here.
				'test_form' => false,
				'mimes'     => self::allowed_types(),
			)
		);

		remove_filter( 'upload_dir', $redirect );
		remove_filter( 'wp_unique_filename', $rename, 10 );

		if ( ! is_array( $result ) || isset( $result['error'] ) ) {
			$message = is_array( $result ) && isset( $result['error'] )
				? $result['error']
				: __( 'That file could not be accepted.', 'kasa-academy' );

			return new WP_Error( 'kasa_rejected', $message );
		}

		$stored = basename( $result['file'] );

		// wp_handle_upload has already agreed the contents match the extension,
		// but the stored name is ours, so it is checked once more against the
		// allowlist before anything is recorded.
		$extension = strtolower( (string) pathinfo( $stored, PATHINFO_EXTENSION ) );

		if ( ! array_key_exists( $extension, self::allowed_types() ) ) {  // phpcs:ignore
			wp_delete_file( $result['file'] );

			return new WP_Error( 'kasa_rejected', __( 'Only documents can be uploaded here.', 'kasa-academy' ) );
		}

		return array(
			'stored'    => $stored,
			'name'      => '' !== $original ? $original : $stored,
			'size'      => (int) filesize( $result['file'] ),
			'extension' => $extension,
		);
	}

	/**
	 * The full path of a stored file, if it really is one of ours.
	 *
	 * The stored name is reduced to its basename before it is joined to the
	 * directory, so a value like ../../wp-config.php cannot climb out, and the
	 * result is checked to be inside the directory afterwards as well.
	 *
	 * @param string $stored Stored filename.
	 * @return string Absolute path, or '' when there is no such file.
	 */
	public static function path( $stored ) {
		$stored = basename( (string) $stored );

		if ( '' === $stored || '.' === $stored || '..' === $stored ) {
			return '';
		}

		$directory = self::directory();

		if ( ! $directory['ready'] ) {
			return '';
		}

		$path = trailingslashit( $directory['path'] ) . $stored;

		if ( ! is_file( $path ) ) {
			return '';
		}

		$real = realpath( $path );
		$root = realpath( $directory['path'] );

		if ( false === $real || false === $root || 0 !== strpos( $real, $root ) ) {
			return '';
		}

		return $real;
	}

	/**
	 * Remove a stored file.
	 *
	 * @param string $stored Stored filename.
	 * @return void
	 */
	public static function delete( $stored ) {
		$path = self::path( $stored );

		if ( '' !== $path ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Send a file to the browser and stop.
	 *
	 * Always as an attachment, never inline: a PDF rendered in place would run
	 * in the site's own origin, and there is no reason to take that on for a
	 * document somebody uploaded.
	 *
	 * @param string $stored   Stored filename.
	 * @param string $filename Name to offer the browser.
	 * @return void
	 */
	public static function stream( $stored, $filename ) {
		$path = self::path( $stored );

		if ( '' === $path ) {
			wp_die(
				esc_html__( 'That document is no longer available.', 'kasa-academy' ),
				esc_html__( 'Not found', 'kasa-academy' ),
				array( 'response' => 404 )
			);
		}

		$filename = sanitize_file_name( $filename );

		if ( '' === $filename ) {
			$filename = basename( $path );
		}

		nocache_headers();

		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Transfer-Encoding: binary' );

		// Anything already buffered would be prepended to the file and corrupt
		// it, which on a PDF shows up as a file that simply will not open.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile

		exit;
	}

	/**
	 * Plain words for PHP's upload error codes.
	 *
	 * @param int $code One of the UPLOAD_ERR_* constants.
	 * @return string
	 */
	private static function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					/* translators: %s: the largest allowed file size, already formatted. */
					__( 'That file is too big. The limit is %s.', 'kasa-academy' ),
					size_format( self::max_bytes() )
				);

			case UPLOAD_ERR_PARTIAL:
				return __( 'The upload stopped partway. Please try again.', 'kasa-academy' );

			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was chosen.', 'kasa-academy' );

			default:
				return __( 'The upload failed on the server. Please try again.', 'kasa-academy' );
		}
	}
}
