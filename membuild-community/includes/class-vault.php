<?php
/**
 * Encryption at rest.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts what must stay private even if the database leaks: the text of
 * private messages, and stored secrets (SMTP password, Bunny API key).
 *
 * XChaCha20-Poly1305 (libsodium, bundled with WordPress), with a random nonce
 * per value and the value's context as associated data, so a ciphertext can't
 * be copied into another chat or field. Stored as "e1:" + base64(nonce|ciphertext).
 *
 * The key lives outside the database, so a leaked database or backup alone
 * can't be read. In order of preference:
 *   1. MEMBUILD_COMMUNITY_KEY in wp-config.php (64 hex characters);
 *   2. wp-content/mbcom-key.php, created once (a PHP file, so the web server
 *      never shows it);
 *   3. the database, only if the file can't be written (Site Health warns).
 * Losing the key means losing the encrypted text, so the key file belongs in
 * every backup and migration, and admins are asked to keep a copy (see Health).
 *
 * A sealed check value is kept with the settings. If the key later goes
 * missing, or a different site's key turns up, the vault stops (state()
 * "missing" or "wrong") instead of quietly making a new key: new messages
 * would otherwise be locked with a key that doesn't match the old ones. The
 * owner then restores the saved key, or knowingly starts over.
 */
class Vault {

	const PREFIX       = 'e1:';
	const CONSTANT     = 'MEMBUILD_COMMUNITY_KEY';
	const KEY_FILE     = 'mbcom-key.php';
	const KEY_OPTION   = 'mbcom_key'; // last resort only
	const MIGRATE_HOOK = 'mbcom_encrypt_messages';
	const CHECK_OPTION = 'mbcom_key_check'; // a value sealed with the key, to recognise it later
	const SAVED_OPTION = 'mbcom_key_saved'; // when an admin confirmed they kept a copy

	private static $key    = null;
	private static $state  = null; // "ok", "missing" or "wrong"
	private static $source = null; // "constant", "file" or "database"

	/** Where the key comes from: "constant", "file" or "database" (null while it's missing). */
	public static function key_source() {
		self::key();
		return self::$source;
	}

	/** "ok"; "missing" (there was a key, and it's gone); or "wrong" (a key that didn't lock what's stored). */
	public static function state() {
		self::key();
		return self::$state;
	}

	public static function ready() {
		return 'ok' === self::state();
	}

	/** Why something that needs the key can't be saved right now, or null when it can. */
	public static function paused() {
		return self::ready() ? null : new \WP_Error( 'mbcom_key_missing', __( 'This can’t be saved until the site owner restores the community’s encryption key.', 'membuild-community' ), array( 'status' => 503 ) );
	}

	/** Forgets the key loaded for this request (after a restore, and in tests). */
	public static function flush() {
		self::$key    = null;
		self::$state  = null;
		self::$source = null;
	}

	/** The key as 64 hex characters, for the owner to keep a copy. Null unless the vault is working. */
	public static function export() {
		return self::ready() ? bin2hex( self::$key ) : null;
	}

	/**
	 * Puts back a saved key (after a move or restore that lost the key file).
	 *
	 * @return true|\WP_Error
	 */
	public static function restore( $hex ) {
		$hex = strtolower( trim( (string) $hex ) );
		if ( 64 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return new \WP_Error( 'mbcom_bad_key', __( 'That isn’t a community key. It’s 64 letters and numbers, from your saved copy.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$check = get_option( self::CHECK_OPTION );
		if ( $check && null === self::unseal( (string) $check, 'key-check', hex2bin( $hex ) ) ) {
			return new \WP_Error( 'mbcom_wrong_key', __( 'That key doesn’t match this community’s messages. Check you copied the key from this site.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$stored = self::store( $hex );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		update_option( self::SAVED_OPTION, time(), false ); // they had a copy, or they couldn't have restored it
		self::flush();
		self::resume();
		return true;
	}

	/**
	 * Gives up on a lost key: a new key from now on. Messages locked with the
	 * old key stay unreadable ("This message can't be shown").
	 *
	 * @return true|\WP_Error
	 */
	public static function start_over() {
		if ( self::ready() ) {
			return new \WP_Error( 'mbcom_key_ok', __( 'The encryption key is working, so there’s nothing to start over.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$stored = self::store( bin2hex( random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES ) ) );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		delete_option( self::CHECK_OPTION );
		delete_option( self::SAVED_OPTION );
		self::flush();
		self::key(); // records the new key's check value
		self::resume();
		return true;
	}

	/** Messages that arrived unencrypted from older versions waited for the key; encrypt them now. */
	private static function resume() {
		if ( ! wp_next_scheduled( self::MIGRATE_HOOK ) ) {
			wp_schedule_single_event( time(), self::MIGRATE_HOOK );
		}
	}

	/** Writes a key where key() looks for it: the key file, or the database when wp-content isn't writable. */
	private static function store( $hex ) {
		if ( defined( self::CONSTANT ) ) {
			return new \WP_Error( 'mbcom_key_constant', __( 'The key is set in wp-config.php (MEMBUILD_COMMUNITY_KEY). Change it there.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$path = self::key_path();
		if ( file_exists( $path ) ) {
			// Kept, not deleted: it may be the key to something after all.
			@rename( $path, dirname( $path ) . '/mbcom-key-replaced-' . gmdate( 'Ymd-His' ) . '.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename -- our own key file
		}
		if ( self::write_key_file( $path, $hex ) ) {
			delete_option( self::KEY_OPTION );
		} else {
			update_option( self::KEY_OPTION, $hex, false );
		}
		return true;
	}

	public static function key_path() {
		return trailingslashit( WP_CONTENT_DIR ) . self::KEY_FILE;
	}

	/** The 32-byte key, or null while it's missing or wrong. Created on first use. */
	private static function key() {
		if ( null !== self::$state ) {
			return self::$key;
		}
		$hex   = self::find();
		$check = get_option( self::CHECK_OPTION );
		if ( null === $hex ) {
			if ( $check ) {
				self::$state = 'missing'; // there was a key: never replace it without the owner
				return self::$key = null;
			}
			$hex = self::create(); // first use
		}
		$key = hex2bin( $hex );
		if ( ! $check ) {
			add_option( self::CHECK_OPTION, self::seal_with( 'mbcom', 'key-check', $key ), '', false );
		} elseif ( null === self::unseal( (string) $check, 'key-check', $key ) ) {
			self::$state = 'wrong';
			return self::$key = null;
		}
		self::$state = 'ok';
		return self::$key = $key;
	}

	/** The key that's already somewhere, as hex: wp-config.php, the key file, or the database. */
	private static function find() {
		$valid = fn( $hex ) => 64 === strlen( $hex ) && ctype_xdigit( $hex );
		if ( defined( self::CONSTANT ) && $valid( (string) constant( self::CONSTANT ) ) ) {
			self::$source = 'constant';
			return strtolower( (string) constant( self::CONSTANT ) );
		}
		$path = self::key_path();
		if ( is_readable( $path ) ) {
			$hex = (string) include $path;
			if ( $valid( $hex ) ) {
				self::$source = 'file';
				return $hex;
			}
		}
		$hex = (string) get_option( self::KEY_OPTION );
		if ( $valid( $hex ) ) {
			self::$source = 'database';
			return $hex;
		}
		return null;
	}

	/** A brand-new key: in the key file, or in the database on a host where wp-content isn't writable. */
	private static function create() {
		$path = self::key_path();
		self::write_key_file( $path, bin2hex( random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES ) ), 'x' );
		$hex = self::find(); // whoever wrote first wins
		if ( null !== $hex ) {
			return $hex;
		}
		add_option( self::KEY_OPTION, bin2hex( random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES ) ), '', false );
		self::$source = 'database';
		return (string) get_option( self::KEY_OPTION );
	}

	/** Writes the key file. Mode "x" never overwrites, so two requests can't make two keys. */
	private static function write_key_file( $path, $hex, $mode = 'w' ) {
		$body = "<?php\n// MemBuild - Community encryption key for private messages and stored passwords.\n// Keep this file with your backups and move it with the site: without it, private messages can't be read.\n// Never share it or commit it to version control.\nreturn '{$hex}';\n";
		$fh   = @fopen( $path, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an exclusive create is the only race-free way to make the key once; WP_Filesystem may need FTP credentials during a request.
		if ( ! $fh ) {
			return false;
		}
		fwrite( $fh, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		@chmod( $path, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- best effort; some hosts don't allow it
		return true;
	}

	/** A stored secret (SMTP password, API key): sealed like a message, in its own context. */
	public static function secret( $plain ) {
		return self::seal( $plain, 'secret' );
	}

	/** Reads a stored secret, including ones saved by 0.1's older AES-CBC scheme. */
	public static function reveal( $stored ) {
		$stored = (string) $stored;
		if ( self::is_sealed( $stored ) ) {
			return (string) self::open( $stored, 'secret' );
		}
		if ( str_starts_with( $stored, 'v1:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $stored, 3 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading a legacy secret
			$key = hash( 'sha256', wp_salt( 'auth' ) . 'mbcom-email', true );
			return (string) openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		}
		return '';
	}

	public static function is_sealed( $value ) {
		return is_string( $value ) && str_starts_with( $value, self::PREFIX );
	}

	/**
	 * Encrypts $plain for a context such as "message:12" or "setting:email".
	 * Empty and null values are returned as they are.
	 */
	public static function seal( $plain, $context ) {
		if ( null === $plain || '' === $plain ) {
			return $plain;
		}
		if ( ! self::ready() ) {
			// Callers check ready() first; storing text unencrypted, or under a mismatched key, is never the fallback.
			throw new \LogicException( 'The encryption key is missing.' );
		}
		return self::seal_with( (string) $plain, $context, self::$key );
	}

	private static function seal_with( $plain, $context, $key ) {
		$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$box   = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain, $context, $nonce, $key );
		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storing binary ciphertext as text
	}

	/**
	 * Decrypts a value from seal(). Values that were never encrypted (older rows
	 * not yet migrated) come back unchanged; a value that can't be decrypted
	 * (wrong key, or moved to another context) comes back as null.
	 */
	public static function open( $stored, $context ) {
		if ( ! self::is_sealed( $stored ) ) {
			return $stored;
		}
		return self::ready() ? self::unseal( $stored, $context, self::$key ) : null;
	}

	private static function unseal( $stored, $context, $key ) {
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading stored ciphertext
		$n   = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if ( false === $raw || strlen( $raw ) <= $n ) {
			return null;
		}
		$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $raw, $n ), $context, substr( $raw, 0, $n ), $key );
		return false === $plain ? null : $plain;
	}
}
