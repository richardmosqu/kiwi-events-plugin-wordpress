<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Short human-readable reference codes (e.g. "RES-AB12CD34").
 *
 * Distinct from ticket codes, which are 64-char SHA-256 hashes embedded in
 * QR codes — those are opaque on purpose and not meant to be read aloud.
 * Reservation codes (and any future booking-style references) need to be
 * speakable over the phone and short enough to print on a confirmation
 * screen, so they use this generator instead.
 *
 * Crockford-style alphabet (no I/O/0/1) keeps codes unambiguous when
 * customers read them back.
 */
class KE_Codes {

    const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Generate a unique code with the given prefix. Caller is responsible
     * for providing a callable `$exists_check( string $code ): bool` that
     * tests whether the candidate is already taken in its target table —
     * this class doesn't know which table to look in.
     *
     * Retries up to 8 times with progressively longer codes if collisions
     * happen (in practice the 8-char base is ~1 trillion combinations so
     * collisions are vanishingly rare; the retry exists only to avoid
     * surfacing a false "duplicate" error to the user).
     *
     * @param string        $prefix       e.g. 'RES'. Trailing dash is added automatically.
     * @param callable|null $exists_check Returns true if code is already taken. Pass null to skip uniqueness check.
     * @param int           $length       Body length in characters (default 8).
     * @return string Code, e.g. "RES-AB23CD45".
     */
    public static function generate( $prefix, $exists_check = null, $length = 8 ) {
        $prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $prefix ) );
        $length = max( 4, min( 16, (int) $length ) );

        for ( $attempt = 0; $attempt < 8; $attempt++ ) {
            $body = self::random_body( $length + intdiv( $attempt, 2 ) );
            $code = $prefix . '-' . $body;
            if ( ! is_callable( $exists_check ) || ! call_user_func( $exists_check, $code ) ) {
                return $code;
            }
        }

        // Final fallback: append a microsecond suffix. Practically unreachable.
        return $prefix . '-' . self::random_body( $length ) . '-' . substr( (string) microtime( true ), -4 );
    }

    private static function random_body( $length ) {
        $alpha = self::ALPHABET;
        $max   = strlen( $alpha ) - 1;
        $out   = '';
        for ( $i = 0; $i < $length; $i++ ) {
            $out .= $alpha[ wp_rand( 0, $max ) ];
        }
        return $out;
    }
}
