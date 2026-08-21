<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * QR Code generator — 100% local, no third-party service.
 *
 * HISTORY / WHY THIS IS LOCAL
 * ---------------------------
 * Until 2026-08-21 every QR in the plugin was an <img> pointing at
 * api.qrserver.com (a free public API): the wallet, the ticket page, the
 * confirmation email, the WooCommerce thank-you page, the admin attendee
 * modal and the promoter portal all built that URL by hand. A flash sale
 * that pushed ~60 tickets through in under a minute produced a burst of
 * requests to that host — 60 ticket pages + 60 emails, each fanning out to
 * every recipient's mail-client image proxy — and it started refusing them.
 * Every QR in the product went blank at once, mid-sale, and there was no
 * fallback because the QR *was* the remote URL.
 *
 * A ticket QR is the product. It cannot depend on someone else's uptime or
 * rate limit. So it is now rendered on this server by the bundled
 * chillerlan/php-qrcode (~0.04s, no network) and cached as a PNG under
 * uploads/kiwi-events/qr/.
 *
 * THE CONTRACT
 * ------------
 * The QR encodes ONLY the raw ticket_code (sha256 hex). The scanner reads it
 * and passes it straight to POST /ke/v1/tickets/validate/{code}. Do not add
 * a URL, a prefix or JSON around it — the scanner would stop matching.
 *
 * URL RESOLUTION (get_url)
 * ------------------------
 *   1. cached file on disk  → the uploads URL. Static file, no PHP, no work.
 *   2. nothing cached yet   → GET /ke/v1/qr/{code}, which renders it, writes
 *                             the cache and streams the PNG. First view pays
 *                             ~40ms once, every later view takes branch 1.
 *
 * Nothing here ever renders during the payment request: get_url() only
 * stats two paths. The cache gets written by whoever looks first — normally
 * KE_PDF_Generator while it builds the email attachment.
 *
 * @see KE_QR_Generator::serve_ticket_qr()  the lazy endpoint
 * @see KE_PDF_Generator                    embeds the same cached file
 */
class KE_QR_Generator {

    /** Cache location, relative to the uploads basedir. */
    const CACHE_DIR = 'kiwi-events/qr/';

    /** Module scale for the cached ticket PNG (8 → ~450px for a sha256 code). */
    const SCALE = 8;

    /**
     * Legacy remote service. Kept ONLY as a needle for detecting the dead
     * URLs still stored in ke_tickets.qr_code_path (see is_legacy_remote()
     * and KE_Activator::maybe_migrate_remote_qr_urls()). Never emitted.
     */
    const LEGACY_HOST = 'api.qrserver.com';

    /* ─────────────────────────────────────────────
     *  Public URL / path resolution
     * ───────────────────────────────────────────── */

    /**
     * Canonical public URL for a ticket's QR image.
     *
     * Cheap by design — two file_exists() calls, no rendering — because the
     * wallet and the attendee list call this once per ticket in a loop.
     *
     * @param string $ticket_code sha256 hex ticket code
     * @return string
     */
    public function get_url( $ticket_code ) {
        $code = self::normalize_code( $ticket_code );
        if ( $code === '' ) {
            return '';
        }

        $upload = wp_upload_dir( null, false );
        if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
            foreach ( array( 'png', 'svg' ) as $ext ) {
                if ( file_exists( $upload['basedir'] . '/' . self::CACHE_DIR . self::filename( $code, $ext ) ) ) {
                    return $upload['baseurl'] . '/' . self::CACHE_DIR . self::filename( $code, $ext );
                }
            }
        }

        return self::endpoint_url( $code );
    }

    /**
     * Back-compat entry point. KE_Tickets::generate() stores the return value
     * in ke_tickets.qr_code_path, inside the payment request — so this must
     * stay allocation-only and never render. The image itself is produced on
     * first view by the endpoint.
     *
     * @param string $ticket_code
     * @return string URL (never a WP_Error; callers already tolerate both)
     */
    public function generate( $ticket_code ) {
        return $this->get_url( $ticket_code );
    }

    /**
     * Absolute path to the cached QR image, rendering + caching it if needed.
     * This is the one method that does real work; used by KE_PDF_Generator to
     * embed the QR and by the endpoint to serve it.
     *
     * @param string $ticket_code
     * @return string|null path, or null when the QR could not be produced
     */
    public function get_path( $ticket_code ) {
        $code = self::normalize_code( $ticket_code );
        if ( $code === '' ) {
            return null;
        }

        $dir = $this->cache_dir();
        if ( ! $dir ) {
            return null;
        }

        foreach ( array( 'png', 'svg' ) as $ext ) {
            $cached = $dir . self::filename( $code, $ext );
            // A zero-byte file is a half-finished write from an earlier
            // crash; treat it as absent so this request rewrites it.
            if ( file_exists( $cached ) && filesize( $cached ) > 0 ) {
                return $cached;
            }
        }

        // PNG first (what TCPDF and every mail client want). SVG is the
        // fallback when the GD extension is missing — chillerlan's markup
        // renderer needs no extension at all, so a server without GD still
        // gets a working QR instead of a broken image.
        foreach ( array( 'png', 'svg' ) as $ext ) {
            $blob = $this->render( $code, $ext, self::SCALE );
            if ( $blob === null ) {
                continue;
            }
            $path = $dir . self::filename( $code, $ext );
            if ( $this->write_atomic( $path, $blob ) ) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Delete the cached image for a ticket code.
     */
    public function delete( $ticket_code ) {
        $code = self::normalize_code( $ticket_code );
        $dir  = $this->cache_dir( false );
        if ( $code === '' || ! $dir ) {
            return;
        }
        foreach ( array( 'png', 'svg' ) as $ext ) {
            $path = $dir . self::filename( $code, $ext );
            if ( file_exists( $path ) ) {
                @unlink( $path );
            }
        }
    }

    /* ─────────────────────────────────────────────
     *  Arbitrary payloads (promoter links, previews)
     * ───────────────────────────────────────────── */

    /**
     * Raw PNG bytes for any string. Not cached — used for one-off QRs
     * (promoter tracking links) that aren't tied to a ticket code.
     *
     * @param string $data payload to encode
     * @param int    $px   approximate target width in pixels
     * @return string|null PNG bytes, or null when PNG rendering is unavailable
     */
    public function generate_png_blob( $data, $px = 220 ) {
        $data = (string) $data;
        if ( $data === '' ) {
            return null;
        }
        // A QR is scale × modules wide; a link of this length lands around
        // 45 modules, so derive the scale from the requested pixel width and
        // keep it inside a sane range.
        $scale = (int) max( 3, min( 12, round( ( (int) $px ) / 45 ) ) );
        return $this->render( $data, 'png', $scale );
    }

    /**
     * data: URI for any string — PNG when GD is present, otherwise SVG.
     * Returns '' on failure so templates can print it unconditionally.
     *
     * @param string $data
     * @param int    $px
     * @return string
     */
    public function data_uri( $data, $px = 220 ) {
        $data = (string) $data;
        if ( $data === '' ) {
            return '';
        }
        $scale = (int) max( 3, min( 12, round( ( (int) $px ) / 45 ) ) );

        $png = $this->render( $data, 'png', $scale );
        if ( $png !== null ) {
            return 'data:image/png;base64,' . base64_encode( $png );
        }
        $svg = $this->render( $data, 'svg', $scale );
        if ( $svg !== null ) {
            return 'data:image/svg+xml;base64,' . base64_encode( $svg );
        }
        return '';
    }

    /* ─────────────────────────────────────────────
     *  REST endpoints
     * ───────────────────────────────────────────── */

    /**
     * Routes are registered from KE_REST_API::register_routes().
     */
    public static function register_routes( $namespace = 'ke/v1' ) {
        // Public: one image per existing ticket code. Public because the
        // confirmation email, the shared ticket page and mail-client image
        // proxies all fetch it with no cookie. The payload space is bounded
        // — the code must exist in ke_tickets or this 404s — so it can't be
        // used to fill the disk with arbitrary renders.
        register_rest_route( $namespace, '/qr/(?P<code>[A-Fa-f0-9]{8,128})', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'serve_ticket_qr' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'code' => array(
                    'required'          => true,
                    // Closures, not bare internals: WP passes
                    // ($value, $request, $param) and PHP 8 fatals on those.
                    'sanitize_callback' => static function ( $value ) {
                        return self::normalize_code( $value );
                    },
                ),
            ),
        ) );

        // Authenticated: QR for an arbitrary URL on this site (promoter
        // tracking links). Login-gated and same-host-only so it is not an
        // open render-anything service.
        register_rest_route( $namespace, '/qr-link', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'serve_link_qr' ),
            'permission_callback' => 'is_user_logged_in',
            'args'                => array(
                'url' => array(
                    'required'          => true,
                    'sanitize_callback' => static function ( $value ) {
                        return esc_url_raw( (string) $value );
                    },
                ),
                'size' => array(
                    'required'          => false,
                    'sanitize_callback' => static function ( $value ) {
                        return absint( $value );
                    },
                ),
            ),
        ) );
    }

    /**
     * GET /ke/v1/qr/{code} — render (once), cache, stream.
     *
     * Streams the bytes and exits, the same way the wallet's PDF endpoint
     * does; the REST JSON serializer never sees this response.
     */
    public static function serve_ticket_qr( WP_REST_Request $request ) {
        global $wpdb;

        $code = self::normalize_code( $request['code'] );
        if ( $code === '' ) {
            return new WP_Error( 'ke_qr_bad_code', 'Código inválido.', array( 'status' => 400 ) );
        }

        // Bound the cache: only codes that belong to a real ticket render.
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ke_tickets WHERE ticket_code = %s LIMIT 1",
            $code
        ) );
        if ( ! $exists ) {
            return new WP_Error( 'ke_qr_not_found', 'Boleto no encontrado.', array( 'status' => 404 ) );
        }

        $generator = new self();
        $path      = $generator->get_path( $code );
        if ( ! $path || ! file_exists( $path ) ) {
            return new WP_Error(
                'ke_qr_render_failed',
                'No se pudo generar el código QR.',
                array( 'status' => 500 )
            );
        }

        self::stream_file( $path );
    }

    /**
     * GET /ke/v1/qr-link?url=… — QR for a link on this site. Logged-in only.
     */
    public static function serve_link_qr( WP_REST_Request $request ) {
        $url  = (string) $request->get_param( 'url' );
        $size = (int) $request->get_param( 'size' );
        $size = $size > 0 ? min( 600, max( 80, $size ) ) : 240;

        if ( $url === '' || ! self::is_same_site( $url ) ) {
            return new WP_Error(
                'ke_qr_bad_url',
                'Solo se admiten enlaces de este sitio.',
                array( 'status' => 400 )
            );
        }

        $generator = new self();
        $png       = $generator->render( $url, 'png', (int) max( 3, min( 12, round( $size / 45 ) ) ) );
        $mime      = 'image/png';
        if ( $png === null ) {
            $png  = $generator->render( $url, 'svg', 8 );
            $mime = 'image/svg+xml';
        }
        if ( $png === null ) {
            return new WP_Error( 'ke_qr_render_failed', 'No se pudo generar el código QR.', array( 'status' => 500 ) );
        }

        // Not cached on disk (unbounded payloads), but immutable per URL, so
        // let the browser hold on to it.
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . strlen( $png ) );
        header( 'Cache-Control: private, max-age=3600' );
        header( 'X-Content-Type-Options: nosniff' );
        echo $png; // phpcs:ignore WordPress.Security.EscapeOutput -- binary image
        exit;
    }

    /**
     * URL of the lazy endpoint for a ticket code.
     */
    public static function endpoint_url( $ticket_code ) {
        $code = self::normalize_code( $ticket_code );
        return $code === '' ? '' : rest_url( 'ke/v1/qr/' . $code );
    }

    /**
     * URL of the authenticated link endpoint, nonce included so the <img>
     * request carries the REST cookie identity. add_query_arg() does the
     * encoding — do not pre-encode $url or it arrives double-escaped.
     */
    public static function link_endpoint_url( $url, $size = 240 ) {
        return add_query_arg(
            array(
                'url'      => $url,
                'size'     => absint( $size ),
                '_wpnonce' => wp_create_nonce( 'wp_rest' ),
            ),
            rest_url( 'ke/v1/qr-link' )
        );
    }

    /**
     * Same endpoint, nonce only — for JS that appends &url=…&size=… as the
     * user edits a slug. Always ends up with a query string already present
     * (?_wpnonce=… or ?rest_route=…&_wpnonce=…) so callers append with '&'.
     */
    public static function link_endpoint_base() {
        return add_query_arg(
            array( '_wpnonce' => wp_create_nonce( 'wp_rest' ) ),
            rest_url( 'ke/v1/qr-link' )
        );
    }

    /**
     * True when a stored qr_code_path still points at the dead remote
     * service. Readers use this to fall back to the local URL instead of
     * emitting an image that will never load.
     */
    public static function is_legacy_remote( $value ) {
        return is_string( $value ) && $value !== '' && strpos( $value, self::LEGACY_HOST ) !== false;
    }

    /**
     * Resolve whatever is stored in qr_code_path into a URL that works today:
     * the stored value if it is still usable, else the local one.
     *
     * @param string $stored      ke_tickets.qr_code_path
     * @param string $ticket_code
     * @return string
     */
    public static function resolve_stored_url( $stored, $ticket_code ) {
        $stored = (string) $stored;
        if ( $stored !== '' && ! self::is_legacy_remote( $stored ) ) {
            return $stored;
        }
        $generator = new self();
        return $generator->get_url( $ticket_code );
    }

    /* ─────────────────────────────────────────────
     *  Rendering
     * ───────────────────────────────────────────── */

    /**
     * Render a payload with the bundled library.
     *
     * @param string $data  payload
     * @param string $ext   'png' or 'svg'
     * @param int    $scale module scale
     * @return string|null image bytes, or null when this format is unavailable
     */
    private function render( $data, $ext, $scale ) {
        if ( ! class_exists( '\chillerlan\QRCode\QRCode' ) ) {
            // vendor/ was not deployed. Nothing to fall back to — the remote
            // service is exactly what this class exists to stop using.
            error_log( 'KiwiEvents: chillerlan/php-qrcode missing — vendor/ must ship with every deploy.' );
            return null;
        }
        if ( $ext === 'png' && ! extension_loaded( 'gd' ) ) {
            return null;
        }

        try {
            $options = array(
                // No fixed 'version': a 64-char sha256 code at ECC_H
                // overflows a version-5 symbol. AUTO sizes it correctly.
                'outputType'   => ( $ext === 'svg' )
                    ? \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG
                    : \chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG,
                'eccLevel'     => \chillerlan\QRCode\Common\EccLevel::H,
                'scale'        => (int) $scale,
                // Default is TRUE in php-qrcode 5 — leaving it on would
                // write a base64 *string* into the .png file.
                'outputBase64' => false,
                'quietzoneSize' => 2,
            );
            if ( $ext === 'png' ) {
                $options['imageTransparent'] = false;
            } else {
                // Inline <img src> SVG. drawLightModules keeps the light
                // modules painted (#fff by default in the markup renderer),
                // which is the contrast a scanner needs — an SVG with a
                // transparent background reads as noise on a dark page.
                $options['svgAddXmlHeader']  = false;
                $options['drawLightModules'] = true;
            }

            $qroptions = new \chillerlan\QRCode\QROptions( $options );
            $out       = ( new \chillerlan\QRCode\QRCode( $qroptions ) )->render( (string) $data );

            return ( is_string( $out ) && $out !== '' ) ? $out : null;

        } catch ( \Throwable $e ) {
            error_log( 'KiwiEvents: QR render failed (' . $ext . '): ' . $e->getMessage() );
            return null;
        }
    }

    /* ─────────────────────────────────────────────
     *  Cache plumbing
     * ───────────────────────────────────────────── */

    /**
     * Cache directory with a trailing slash, or null when unusable.
     *
     * @param bool $create create it (and its listing guard) when missing
     */
    private function cache_dir( $create = true ) {
        $upload = wp_upload_dir( null, false );
        if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
            return null;
        }
        $dir = trailingslashit( $upload['basedir'] ) . self::CACHE_DIR;

        if ( ! is_dir( $dir ) ) {
            if ( ! $create ) {
                return null;
            }
            // wp_mkdir_p() is not concurrency-safe: it checks file_exists()
            // and then mkdir()s, so when N requests race to create this
            // directory — exactly what a flash sale on a fresh deploy looks
            // like — the losers get mkdir(): File exists and a false return.
            // Taking that at face value dropped the QR for every request but
            // one. Re-check instead: another process creating it is success.
            if ( ! wp_mkdir_p( $dir ) && ! is_dir( $dir ) ) {
                error_log( 'KiwiEvents: cannot create the QR cache directory ' . $dir );
                return null;
            }
            // Ticket codes are the filenames here; a browsable directory
            // would hand them out. Cheap belt to whatever the server does.
            $guard = $dir . 'index.html';
            if ( ! file_exists( $guard ) ) {
                @file_put_contents( $guard, '' );
            }
        }

        if ( ! is_writable( $dir ) ) {
            error_log( 'KiwiEvents: QR cache directory is not writable: ' . $dir );
            return null;
        }

        return $dir;
    }

    /**
     * Same naming convention as the ticket PDFs (ticket-{code}.pdf).
     */
    private static function filename( $code, $ext ) {
        return 'qr-' . $code . '.' . $ext;
    }

    /**
     * Write via temp file + rename so a concurrent reader never gets a
     * half-written image. During a flash sale several requests render the
     * same code at once; rename() is atomic, last writer wins, both are
     * byte-identical anyway.
     */
    private function write_atomic( $path, $bytes ) {
        $tmp = $path . '.' . getmypid() . uniqid( '', true ) . '.tmp';
        if ( file_put_contents( $tmp, $bytes ) === false ) {
            @unlink( $tmp );
            return false;
        }
        if ( ! @rename( $tmp, $path ) ) {
            @unlink( $tmp );
            return false;
        }
        return true;
    }

    /**
     * Ticket codes are lowercase hex; anything else is not a code.
     */
    private static function normalize_code( $value ) {
        $value = strtolower( (string) $value );
        return preg_replace( '/[^a-f0-9]/', '', $value );
    }

    private static function is_same_site( $url ) {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        $home = wp_parse_url( home_url(), PHP_URL_HOST );
        return $host && $home && strtolower( $host ) === strtolower( $home );
    }

    /**
     * Stream a cached image with long-lived cache headers and exit.
     * The bytes for a ticket code never change, so this is immutable.
     */
    private static function stream_file( $path ) {
        $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $mime = ( $ext === 'svg' ) ? 'image/svg+xml' : 'image/png';
        $etag = '"' . md5( $path . '|' . filesize( $path ) ) . '"';

        header( 'Content-Type: ' . $mime );
        header( 'Cache-Control: public, max-age=31536000, immutable' );
        header( 'ETag: ' . $etag );
        header( 'X-Content-Type-Options: nosniff' );

        if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] )
             && trim( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) === $etag ) {
            status_header( 304 );
            exit;
        }

        header( 'Content-Length: ' . filesize( $path ) );
        readfile( $path );
        exit;
    }
}
