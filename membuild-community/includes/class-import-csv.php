<?php
/**
 * Members from a spreadsheet: a CSV from Kajabi, Circle, Skool or anywhere, or the Excel
 * file Mighty Networks sends. The owner matches the columns (we guess from their names),
 * a dry run shows who's new, who's already here and which rows can't come, then new
 * members get an account. Someone already here (by email) only has empty fields filled in.
 *
 * The file waits in the protected uploads folder under a random name and is deleted when
 * the import finishes or after a day.
 *
 * @package membuild-community
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

class Import_Csv extends Import_Source {

	const MAX_BYTES = 10485760; // 10 MB: tens of thousands of members
	const MAX_ROWS  = 50000;
	const FIELDS    = array( 'email', 'first_name', 'last_name', 'name', 'joined', 'membership', 'bio', 'location', 'photo', 'skip' );

	public function label() {
		return __( 'a spreadsheet', 'membuild-community' );
	}

	public function detect() {
		return true;
	}

	public function summary() {
		return array();
	}

	/* ---------- The file ---------- */

	private static function path( $token ) {
		return preg_match( '/^[a-f0-9]{32}\.(csv|xlsx)$/', (string) $token ) ? Files::dir() . 'import-' . $token : '';
	}

	/** Saves the upload, then reads its columns and first row so the owner can match them. */
	public static function receive( $name, $bytes ) {
		$file = array( 'name' => $name, 'size' => strlen( $bytes ) );
		$ext  = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'xlsx', 'txt' ), true ) ) {
			return new \WP_Error( 'mbcom_bad_type', __( 'Save the spreadsheet as a CSV (or an Excel .xlsx file) and try again.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] > self::MAX_BYTES ) {
			return new \WP_Error( 'mbcom_too_big', __( 'That file is bigger than 10 MB. Split it in two and add each.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		self::clean_old();
		$token = bin2hex( random_bytes( 16 ) ) . '.' . ( 'xlsx' === $ext ? 'xlsx' : 'csv' );
		if ( false === file_put_contents( self::path( $token ), $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own protected folder
			return new \WP_Error( 'mbcom_write', __( 'Couldn’t save the file. Check that uploads can be written.', 'membuild-community' ), array( 'status' => 500 ) );
		}
		$rows = self::rows( $token );
		if ( is_wp_error( $rows ) ) {
			wp_delete_file( self::path( $token ) );
			return $rows;
		}
		$head = array_shift( $rows );
		if ( ! $head || ! $rows ) {
			wp_delete_file( self::path( $token ) );
			return new \WP_Error( 'mbcom_empty', __( 'That file has no rows under its column names.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$first   = $rows[0];
		$columns = array();
		$taken   = array();
		foreach ( $head as $i => $name ) {
			$guess = self::guess( (string) $name, array_column( array_slice( $rows, 0, 20 ), $i ) );
			if ( 'skip' !== $guess && isset( $taken[ $guess ] ) ) {
				$guess = 'skip'; // one column per field
			}
			$taken[ $guess ] = true;
			$columns[]       = array( 'name' => (string) $name, 'first' => mb_substr( (string) ( $first[ $i ] ?? '' ), 0, 60 ), 'guess' => $guess );
		}
		return array( 'file' => $token, 'name' => sanitize_file_name( (string) $file['name'] ), 'rows' => count( $rows ), 'columns' => $columns, 'memberships' => array_values( array_map( fn( $g ) => array( 'id' => (int) $g['id'], 'name' => $g['name'] ), Access::groups() ) ) );
	}

	/** Files from imports that were never started. */
	private static function clean_old() {
		foreach ( (array) glob( Files::dir() . 'import-*' ) as $f ) {
			if ( is_file( $f ) && filemtime( $f ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $f );
			}
		}
	}

	/** What a column holds, from its name (Kajabi, Mighty Networks, Circle and Skool names included) or its values. */
	private static function guess( $name, array $values ) {
		$n = strtolower( trim( preg_replace( '/[^a-z ]+/i', ' ', $name ) ) );
		$rules = array(
			'email'      => '/^(e ?mail|email address|e mail address|member email|user email)$/',
			'first_name' => '/^(first|first name|given name|firstname|fname)$/',
			'last_name'  => '/^(last|last name|surname|family name|lastname|lname)$/',
			'name'       => '/^(name|full name|member name|display name|member)$/',
			'joined'     => '/(joined|member since|signed up|sign up|created|date added|registered|join date|start date)/',
			'membership' => '/(plan|tier|membership|level|offer|product|subscription|price)/',
			'bio'        => '/^(bio|about|about me|headline|description|tagline)$/',
			'location'   => '/(location|city|country|where)/',
			'photo'      => '/(photo|avatar|picture|image|profile pic)/',
		);
		foreach ( $rules as $field => $re ) {
			if ( preg_match( $re, $n ) ) {
				return $field;
			}
		}
		$emails = count( array_filter( $values, fn( $v ) => is_email( trim( (string) $v ) ) ) );
		return $values && $emails >= count( $values ) * 0.8 ? 'email' : 'skip';
	}

	/** Every row of the file, the first being the column names. */
	public static function rows( $token ) {
		$path = self::path( $token );
		if ( ! $path || ! is_readable( $path ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'That file is gone. Upload it again.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$rows = str_ends_with( $token, '.xlsx' ) ? self::xlsx( $path ) : self::csv( $path );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$rows = array_values( array_filter( $rows, fn( $r ) => '' !== trim( implode( '', array_map( 'strval', $r ) ) ) ) );
		return array_slice( $rows, 0, self::MAX_ROWS + 1 );
	}

	private static function csv( $path ) {
		$raw = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file
		if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
		}
		$line = strtok( $raw, "\n" );
		$sep  = substr_count( (string) $line, ';' ) > substr_count( (string) $line, ',' ) ? ';' : ( substr_count( (string) $line, "\t" ) > substr_count( (string) $line, ',' ) ? "\t" : ',' );
		$h    = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in memory
		fwrite( $h, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- in memory
		rewind( $h );
		$rows = array();
		while ( false !== ( $r = fgetcsv( $h, 0, $sep, '"', '' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$rows[] = array_map( fn( $v ) => trim( (string) $v ), $r );
			if ( count( $rows ) > self::MAX_ROWS + 1 ) {
				break;
			}
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in memory
		return $rows;
	}

	/** The first sheet of an Excel file, read straight from its XML (no library needed). */
	private static function xlsx( $path ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'mbcom_bad_type', __( 'This server can’t open Excel files. Save it as a CSV and try again.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new \WP_Error( 'mbcom_bad_type', __( 'That Excel file couldn’t be opened. Save it as a CSV and try again.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$strings = array();
		$shared  = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( $shared ) {
			$sx = simplexml_load_string( $shared, 'SimpleXMLElement', LIBXML_NONET );
			foreach ( $sx ? $sx->si : array() as $si ) {
				$strings[] = isset( $si->t ) ? (string) $si->t : implode( '', array_map( 'strval', iterator_to_array( $si->xpath( './/*[local-name()="t"]' ) ?: array() ) ) );
			}
		}
		$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();
		$sx = $sheet ? simplexml_load_string( $sheet, 'SimpleXMLElement', LIBXML_NONET ) : null;
		if ( ! $sx ) {
			return new \WP_Error( 'mbcom_bad_type', __( 'That Excel file has no sheet we can read. Save it as a CSV and try again.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$rows = array();
		foreach ( $sx->sheetData->row as $row ) {
			$cells = array();
			foreach ( $row->c as $c ) {
				$col = 0;
				foreach ( str_split( preg_replace( '/\d/', '', (string) $c['r'] ) ) as $ch ) {
					$col = $col * 26 + ( ord( $ch ) - 64 );
				}
				$type = (string) $c['t'];
				$v    = 's' === $type ? ( $strings[ (int) $c->v ] ?? '' ) : ( 'inlineStr' === $type ? (string) $c->is->t : (string) $c->v );
				$cells[ max( 0, $col - 1 ) ] = trim( $v );
			}
			if ( $cells ) {
				$max  = max( array_keys( $cells ) );
				$rows[] = array_map( fn( $i ) => $cells[ $i ] ?? '', range( 0, $max ) );
			}
			if ( count( $rows ) > self::MAX_ROWS + 1 ) {
				break;
			}
		}
		return $rows;
	}

	/* ---------- Matching ---------- */

	/** {file, map: [field per column], plans: {value: membership id}, welcome} from the request. */
	public static function options( \WP_REST_Request $req ) {
		$token = (string) $req['file'];
		if ( ! self::path( $token ) || ! is_readable( self::path( $token ) ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'That file is gone. Upload it again.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$map = array_map( fn( $f ) => in_array( $f, self::FIELDS, true ) ? $f : 'skip', array_values( (array) $req['map'] ) );
		if ( ! in_array( 'email', $map, true ) ) {
			return new \WP_Error( 'mbcom_invalid', __( 'Choose which column has the email addresses.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$groups = Access::groups();
		$plans  = array();
		foreach ( (array) $req['plans'] as $value => $group_id ) {
			if ( isset( $groups[ (int) $group_id ] ) ) {
				$plans[ mb_strtolower( (string) $value ) ] = (int) $group_id;
			}
		}
		return array( 'file' => $token, 'map' => $map, 'plans' => $plans, 'welcome' => (bool) $req['welcome'], 'name' => sanitize_file_name( (string) $req['name'] ) );
	}

	/** One row as named fields. */
	private static function member( array $map, array $row ) {
		$m = array();
		foreach ( $map as $i => $field ) {
			if ( 'skip' !== $field && isset( $row[ $i ] ) && '' !== $row[ $i ] ) {
				$m[ $field ] = (string) $row[ $i ];
			}
		}
		$m['email'] = strtolower( trim( $m['email'] ?? '' ) );
		if ( empty( $m['first_name'] ) && ! empty( $m['name'] ) ) {
			$parts           = preg_split( '/\s+/', trim( $m['name'] ), 2 );
			$m['first_name'] = $parts[0];
			$m['last_name']  = $m['last_name'] ?? ( $parts[1] ?? '' );
		}
		return $m;
	}

	/** Membership names (any case) match a plan's values; the owner's choices win. */
	private static function plan_group( array $job, $value ) {
		$v = mb_strtolower( trim( (string) $value ) );
		if ( '' === $v ) {
			return 0;
		}
		if ( isset( $job['plans'][ $v ] ) ) {
			return (int) $job['plans'][ $v ];
		}
		foreach ( Access::groups() as $g ) {
			if ( mb_strtolower( $g['name'] ) === $v ) {
				return (int) $g['id'];
			}
		}
		return 0;
	}

	public function totals( array $job ) {
		$rows = self::rows( $job['file'] );
		return array( 'members' => is_wp_error( $rows ) ? 0 : max( 0, count( $rows ) - 1 ) );
	}

	public function batch( $step, $after, $limit, array $job ) {
		if ( 'members' !== $step ) {
			return array( array(), null );
		}
		$rows = self::rows( $job['file'] );
		if ( is_wp_error( $rows ) ) {
			throw new \RuntimeException( $rows->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- shown as text
		}
		array_shift( $rows );
		$slice = array_slice( $rows, (int) $after, $limit );
		$out   = array();
		foreach ( $slice as $row ) {
			$m = self::member( $job['map'], $row );
			if ( is_email( $m['email'] ) ) {
				$out[] = $m;
			}
		}
		$next = (int) $after + count( $slice );
		if ( $next >= count( $rows ) ) {
			wp_delete_file( self::path( $job['file'] ) );
			return array( $out, null );
		}
		return array( $out, $next );
	}

	/**
	 * Makes the account, or fills in the empty fields of the one already here.
	 *
	 * @return array [user id or 0, whether it's new]
	 */
	public static function write( array $job, array $m ) {
		$user   = get_user_by( 'email', $m['email'] );
		$is_new = ! $user;
		if ( $is_new ) {
			$base  = sanitize_user( strstr( $m['email'], '@', true ), true ) ?: 'member';
			$login = $base;
			for ( $i = 2; username_exists( $login ); $i++ ) {
				$login = $base . $i;
			}
			$first   = sanitize_text_field( $m['first_name'] ?? '' );
			$last    = sanitize_text_field( $m['last_name'] ?? '' );
			$display = trim( $first . ' ' . $last ) ?: $base;
			$joined  = ! empty( $m['joined'] ) ? self::date( $m['joined'] ) : null;
			$id      = wp_insert_user(
				array(
					'user_login'      => $login,
					'user_email'      => $m['email'],
					'user_pass'       => wp_generate_password( 24 ),
					'first_name'      => $first,
					'last_name'       => $last,
					'display_name'    => $display,
					'user_registered' => $joined ?: Helpers::now(),
					'role'            => get_option( 'default_role', 'subscriber' ),
				)
			);
			if ( is_wp_error( $id ) ) {
				return array( 0, false );
			}
			$user = get_userdata( $id );
		} else {
			$fill = array();
			foreach ( array( 'first_name', 'last_name' ) as $f ) {
				if ( ! empty( $m[ $f ] ) && '' === (string) $user->$f ) {
					$fill[ $f ] = sanitize_text_field( $m[ $f ] );
				}
			}
			$fill && wp_update_user( array( 'ID' => $user->ID ) + $fill );
		}
		$meta = array(
			'mbcom_bio'            => isset( $m['bio'] ) ? mb_substr( Helpers::clean_text( $m['bio'], 500 ), 0, 500 ) : '',
			Rest_Members::LOCATION => isset( $m['location'] ) ? mb_substr( sanitize_text_field( $m['location'] ), 0, 80 ) : '',
			'mbcom_avatar_url'     => isset( $m['photo'] ) && wp_http_validate_url( $m['photo'] ) ? esc_url_raw( $m['photo'] ) : '',
		);
		foreach ( $meta as $key => $value ) {
			if ( '' !== $value && '' === (string) get_user_meta( $user->ID, $key, true ) ) {
				update_user_meta( $user->ID, $key, $value );
			}
		}
		$group = self::plan_group( $job, $m['membership'] ?? '' );
		if ( $group && Access::add( $group, $user->ID ) ) {
			Import::granted( $job, $group, (int) $user->ID ); // so Undo can take it back
		}
		return array( (int) $user->ID, $is_new );
	}

	/** "2024-03-02", "03/02/2024", "March 2, 2024" or an Excel day number, as UTC. */
	private static function date( $value ) {
		$value = trim( (string) $value );
		if ( is_numeric( $value ) && (float) $value > 20000 && (float) $value < 80000 ) {
			return gmdate( 'Y-m-d H:i:s', (int) round( ( (float) $value - 25569 ) * DAY_IN_SECONDS ) );
		}
		$t = strtotime( $value );
		return $t && $t < time() + DAY_IN_SECONDS ? gmdate( 'Y-m-d H:i:s', $t ) : null;
	}

	/* ---------- The dry run ---------- */

	public function check( array $job ) {
		$rows = self::rows( $job['file'] );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		array_shift( $rows );
		$new      = 0;
		$here     = 0;
		$bad      = array();
		$plans    = array();
		$seen     = array();
		$doubled  = 0;
		foreach ( $rows as $i => $row ) {
			$m = self::member( $job['map'], $row );
			if ( ! is_email( $m['email'] ) ) {
				$bad[] = array( 'row' => $i + 2, 'values' => $row );
				continue;
			}
			if ( isset( $seen[ $m['email'] ] ) ) {
				++$doubled;
				continue;
			}
			$seen[ $m['email'] ] = true;
			email_exists( $m['email'] ) ? ++$here : ++$new;
			if ( isset( $m['membership'] ) ) {
				$plans[ $m['membership'] ] = ( $plans[ $m['membership'] ] ?? 0 ) + 1;
			}
		}
		$items = array(
			array(
				/* translators: %s: number of members */
				'title' => sprintf( _n( '%s new member gets an account', '%s new members get an account', $new, 'membuild-community' ), number_format_i18n( $new ) ),
				'sub'   => in_array( 'joined', $job['map'], true ) ? __( 'With the name and joined date from your file.', 'membuild-community' ) : __( 'With the name from your file.', 'membuild-community' ),
				'tag'   => 'ready',
			),
		);
		if ( $here ) {
			/* translators: %s: number of members */
			$items[] = array( 'title' => sprintf( _n( '%s is already a member here', '%s are already members here', $here, 'membuild-community' ), number_format_i18n( $here ) ), 'sub' => __( 'Matched by email: only empty fields are filled in, nothing is overwritten.', 'membuild-community' ), 'tag' => 'updated' );
		}
		if ( $bad ) {
			$list    = implode( ', ', array_slice( array_column( $bad, 'row' ), 0, 5 ) ) . ( count( $bad ) > 5 ? '…' : '' );
			$items[] = array(
				/* translators: %s: number of rows */
				'title' => sprintf( _n( '%s row has no valid email', '%s rows have no valid email', count( $bad ), 'membuild-community' ), number_format_i18n( count( $bad ) ) ),
				/* translators: %s: row numbers, e.g. "214, 640" */
				'sub'   => sprintf( _n( 'Row %s. Fix it, then add it later.', 'Rows %s. Fix them, then add them later.', count( $bad ), 'membuild-community' ), $list ),
				'tag'   => 'left_out',
				'download' => true,
			);
		}
		if ( $doubled ) {
			/* translators: %s: number of rows */
			$items[] = array( 'title' => sprintf( _n( '%s email is in the file twice', '%s emails are in the file twice', $doubled, 'membuild-community' ), number_format_i18n( $doubled ) ), 'sub' => __( 'The first row with it is used.', 'membuild-community' ), 'tag' => 'merged' );
		}
		$values = array();
		if ( $plans ) {
			$matched = array();
			$none    = array();
			foreach ( array_keys( $plans ) as $v ) {
				$g = self::plan_group( $job, $v );
				$g ? ( $matched[] = $v ) : ( $none[] = $v );
				$values[] = array( 'value' => (string) $v, 'count' => $plans[ $v ], 'group_id' => $g );
			}
			$col     = $job['map'] ? array_search( 'membership', $job['map'], true ) : false;
			$items[] = array(
				/* translators: 1: number of values, 2: the values */
				'title' => sprintf( __( '“%1$s” has %2$s', 'membuild-community' ), self::column_name( $job, $col ), sprintf( _n( '%1$s value: %2$s', '%1$s values: %2$s', count( $plans ), 'membuild-community' ), number_format_i18n( count( $plans ) ), implode( ', ', array_slice( array_keys( $plans ), 0, 6 ) ) ) ),
				'sub'   => $matched
					/* translators: 1: values that match, 2: values that don't */
					? ( $none ? sprintf( __( '%1$s match your memberships; %2$s means no membership.', 'membuild-community' ), implode( ', ', $matched ), implode( ', ', $none ) ) : sprintf( __( '%s match your memberships.', 'membuild-community' ), implode( ', ', $matched ) ) )
					: __( 'None match a membership here yet. Choose one for each below, or make them in Memberships first.', 'membuild-community' ),
				'tag'   => 'memberships',
			);
		}
		return array( 'items' => $items, 'notes' => array(), 'new' => $new, 'here' => $here, 'invalid' => array_slice( $bad, 0, 2000 ), 'plans' => $values );
	}

	private static function column_name( array $job, $col ) {
		if ( false === $col ) {
			return __( 'Plan', 'membuild-community' );
		}
		$rows = self::rows( $job['file'] );
		return is_wp_error( $rows ) ? __( 'Plan', 'membuild-community' ) : (string) ( $rows[0][ $col ] ?? __( 'Plan', 'membuild-community' ) );
	}

	public function facts( array $job ) {
		return array();
	}
}
