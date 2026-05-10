<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Load the bundled FPDF library eagerly if present so the FPDF subclass at
// the bottom of this file can extend it. If the file is missing, the report
// class falls back to the printable HTML report — see stream() below.
if ( ! class_exists( 'FPDF' ) ) {
    $ke_fpdf_path = KE_PLUGIN_DIR . 'includes/vendor/fpdf/fpdf.php';
    if ( file_exists( $ke_fpdf_path ) ) {
        require_once $ke_fpdf_path;
    }
    unset( $ke_fpdf_path );
}

/**
 * Sales report for the organizer dashboard.
 *
 * Tries FPDF (bundled at includes/vendor/fpdf/fpdf.php) first. Falls back to
 * a printable HTML report opened in a new tab if the library is somehow
 * unavailable, so the organizer can always get *some* report.
 *
 * Layout (PDF mode)
 *   - Cover page: organizer logo, name, date range, generated date
 *   - Executive summary table (totals)
 *   - Per-event breakdown
 *   - Attendee list
 *   - Footer "Powered by Kiwi Events | Page X of Y"
 *
 * Branding pulls --kep-accent from ke_ui_settings.accent_color so the report
 * matches the dashboard.
 */
class KE_Organizer_Report_PDF {

    /** @var WP_Term */
    private $term;

    /** @var array{hex:string,rgb:int[]} */
    private $accent;

    /** @var string */
    private $range;

    /** @var int 0 = organizer-wide; otherwise scope all numbers to this event_id */
    private $event_id = 0;

    public function __construct( $term, $range = 'all', $event_id = 0 ) {
        $this->term     = $term;
        $this->range    = (string) $range;
        $this->event_id = (int) $event_id;

        $ui  = get_option( 'ke_ui_settings', array() );
        $hex = ! empty( $ui['accent_color'] ) ? sanitize_hex_color( $ui['accent_color'] ) : '#6366f1';
        $this->accent = array(
            'hex' => $hex,
            'rgb' => $this->hex_to_rgb( $hex ),
        );
    }

    /**
     * FPDF needs Helvetica regular + bold + italic + bold-italic to render
     * the report. If any are missing on the deploy, we don't try to call
     * SetFont() and crash mid-stream — fall back to HTML instead.
     */
    public static function fpdf_fonts_available() {
        $base = KE_PLUGIN_DIR . 'includes/vendor/fpdf/font/';
        foreach ( array( 'helvetica.php', 'helveticab.php', 'helveticai.php', 'helveticabi.php' ) as $f ) {
            if ( ! file_exists( $base . $f ) ) return false;
        }
        return true;
    }

    /** Scope events down to a single event when $event_id is set. */
    private function scoped_events( $events ) {
        if ( $this->event_id <= 0 ) return $events;
        $filtered = array();
        foreach ( $events as $e ) {
            if ( (int) $e['id'] === $this->event_id ) $filtered[] = $e;
        }
        return $filtered;
    }

    private function hex_to_rgb( $hex ) {
        $hex = ltrim( (string) $hex, '#' );
        if ( strlen( $hex ) !== 6 ) return array( 99, 102, 241 );
        return array(
            (int) hexdec( substr( $hex, 0, 2 ) ),
            (int) hexdec( substr( $hex, 2, 2 ) ),
            (int) hexdec( substr( $hex, 4, 2 ) ),
        );
    }

    /**
     * Stream the report. Picks PDF if FPDF + its font files are present,
     * else falls back to the printable HTML report so the organizer always
     * gets *some* report instead of a 500.
     */
    public function stream() {
        $can_use_pdf = class_exists( 'FPDF' )
            && class_exists( 'KE_Organizer_FPDF_Report' )
            && self::fpdf_fonts_available();
        if ( $can_use_pdf ) {
            $this->stream_pdf();
        } else {
            $this->stream_html();
        }
    }

    /**
     * The REST stack runs inside one or more nested output buffers. FPDF's
     * _checkoutput() throws "Some data has already been output" if the
     * buffer contains anything other than a BOM/whitespace — and our safe
     * wrapper turns that into a generic JSON 500. Clear every level before
     * we start streaming so the binary response is the only thing on the
     * wire.
     */
    private function reset_output_buffers() {
        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }
    }

    /**
     * Return a path FPDF can safely embed via Image(), or null if the asset
     * can't be turned into JPEG/PNG/GIF on this server.
     *
     * FPDF only handles JPEG, PNG, and GIF. WordPress 6.x serves WebP (and
     * sometimes AVIF/HEIC) by default for new uploads, which makes
     * Image('whatever.webp', ...) throw "Unsupported image type: webp" and
     * the safe wrapper converts that into a generic 500.
     *
     * For unsupported types, transcode to JPEG once, cache the result under
     * uploads/ke-pdf-cache/, and reuse on subsequent runs.
     */
    private function pdf_safe_image_path( $original ) {
        if ( ! $original || ! file_exists( $original ) ) return null;

        $ext = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
        $supported = array( 'jpg', 'jpeg', 'png', 'gif' );
        if ( in_array( $ext, $supported, true ) ) return $original;

        // Anything else: transcode to JPEG via GD if we can.
        $unsupported = array( 'webp', 'avif', 'heic', 'heif', 'bmp', 'tiff', 'tif' );
        if ( ! in_array( $ext, $unsupported, true ) ) {
            // Unknown extension — let FPDF try, but most likely it'll fail.
            // Returning the original is safer than null (some servers serve
            // .jpg without the extension).
            return $original;
        }

        $uploads = wp_upload_dir();
        if ( empty( $uploads['basedir'] ) || ! empty( $uploads['error'] ) ) return null;
        $cache_dir = trailingslashit( $uploads['basedir'] ) . 'ke-pdf-cache';
        if ( ! file_exists( $cache_dir ) ) {
            // wp_mkdir_p drops index.html — fine; the dir is harmless static.
            wp_mkdir_p( $cache_dir );
        }
        if ( ! is_dir( $cache_dir ) || ! is_writable( $cache_dir ) ) return null;

        // Cache key includes mtime so a re-uploaded same-name file invalidates.
        $cache_key = md5( $original . '|' . (int) @filemtime( $original ) );
        $cached    = trailingslashit( $cache_dir ) . $cache_key . '.jpg';
        if ( file_exists( $cached ) ) return $cached;

        // Try the GD path matching the source extension.
        $loader = null;
        if ( $ext === 'webp' && function_exists( 'imagecreatefromwebp' ) )      $loader = 'imagecreatefromwebp';
        elseif ( $ext === 'avif' && function_exists( 'imagecreatefromavif' ) ) $loader = 'imagecreatefromavif';
        elseif ( $ext === 'bmp'  && function_exists( 'imagecreatefrombmp' ) )  $loader = 'imagecreatefrombmp';
        elseif ( ( $ext === 'tiff' || $ext === 'tif' ) && function_exists( 'imagecreatefromstring' ) ) {
            // GD doesn't have imagecreatefromtiff, but imagecreatefromstring
            // sometimes works for embedded TIFFs. Last-ditch attempt.
            $bytes = @file_get_contents( $original );
            if ( $bytes !== false ) {
                $img = @imagecreatefromstring( $bytes );
                if ( $img ) {
                    @imagejpeg( $img, $cached, 85 );
                    @imagedestroy( $img );
                    return file_exists( $cached ) ? $cached : null;
                }
            }
            return null;
        }

        if ( ! $loader || ! function_exists( 'imagejpeg' ) ) {
            // GD lacks the right loader (e.g. PHP built without --with-webp).
            // Don't crash — just skip the image. The cover still renders.
            return null;
        }

        $img = @$loader( $original );
        if ( ! $img ) return null;
        // Flatten transparency to white so JPEG doesn't render black where
        // the WebP had alpha.
        if ( function_exists( 'imageistruecolor' ) && imageistruecolor( $img ) ) {
            $w = imagesx( $img );
            $h = imagesy( $img );
            $flat = imagecreatetruecolor( $w, $h );
            $white = imagecolorallocate( $flat, 255, 255, 255 );
            imagefilledrectangle( $flat, 0, 0, $w, $h, $white );
            imagecopy( $flat, $img, 0, 0, 0, 0, $w, $h );
            @imagejpeg( $flat, $cached, 85 );
            @imagedestroy( $flat );
            @imagedestroy( $img );
        } else {
            @imagejpeg( $img, $cached, 85 );
            @imagedestroy( $img );
        }
        return file_exists( $cached ) ? $cached : null;
    }

    /* ──────────────────────────────────────────────────────────────
     *  Shared formatting helpers
     * ────────────────────────────────────────────────────────────── */

    private function fmt_money( $n ) {
        $currency = get_option( 'ke_currency', 'USD' );
        $symbol = $currency === 'EUR' ? '€' : ( $currency === 'GBP' ? '£' : '$' );
        // Cast to float so a null/string from the DB (e.g. NULL net_revenue
        // for a free event) doesn't trip number_format and bubble up as a
        // TypeError under PHP 8.
        return $symbol . number_format( (float) ( $n ?? 0 ), 2 );
    }
    private function fmt_int( $n ) { return number_format( (int) ( $n ?? 0 ) ); }
    private function fmt_pct( $n ) { return number_format( (float) ( $n ?? 0 ), 1 ) . '%'; }

    /**
     * Format a date defensively. Empty / null / '0000-00-00' / unparseable
     * strings return the dash placeholder rather than 'Dec 31, 1969'.
     */
    private function safe_date( $s, $fmt = 'M j, Y' ) {
        if ( ! $s || $s === '0000-00-00' || $s === '0000-00-00 00:00:00' ) return '-';
        $ts = strtotime( (string) $s );
        if ( ! $ts ) return '-';
        return date_i18n( $fmt, $ts );
    }

    /**
     * Truncate to N visible characters, UTF-8 aware. Adds an ellipsis when
     * truncated. Falls back to substr if mb_* is unavailable.
     */
    private function safe_truncate( $s, $max ) {
        $s = (string) ( $s ?? '' );
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $s ) <= $max ) return $s;
            return mb_substr( $s, 0, $max - 1 ) . '...';
        }
        if ( strlen( $s ) <= $max ) return $s;
        return substr( $s, 0, $max - 1 ) . '...';
    }

    private function range_label() {
        $map = array(
            '7'   => __( 'Last 7 days',   'kiwi-events' ),
            '30'  => __( 'Last 30 days',  'kiwi-events' ),
            '90'  => __( 'Last 90 days',  'kiwi-events' ),
            '365' => __( 'Last year',     'kiwi-events' ),
            'all' => __( 'All time',      'kiwi-events' ),
        );
        return $map[ $this->range ] ?? $this->range;
    }

    /**
     * FPDF only handles latin-1 — sanitize UTF-8 strings before printing.
     * Null-safe: a NULL DB column (attendee_name, ticket_type_snapshot, etc.)
     * coerces to empty string rather than causing a TypeError under PHP 8.1+
     * deprecation rules. Real-world data — Spanish names like "José", "Peña",
     * em-dashes, smart quotes — gets transliterated to its closest ASCII
     * equivalent so FPDF's Cell() doesn't blow up on the high byte.
     */
    private function latin1( $s ) {
        if ( $s === null ) return '';
        $s = (string) $s;
        if ( $s === '' ) return '';
        if ( function_exists( 'iconv' ) ) {
            $out = @iconv( 'UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s );
            if ( is_string( $out ) ) return $out;
        }
        if ( function_exists( 'mb_convert_encoding' ) ) {
            $out = @mb_convert_encoding( $s, 'ISO-8859-1', 'UTF-8' );
            if ( is_string( $out ) ) return $out;
        }
        // Final fallback: strip everything outside printable ASCII so we
        // never pass raw UTF-8 bytes into FPDF's core-font width tables.
        return preg_replace( '/[^\x20-\x7E]/', '', $s );
    }

    private function fetch_attendees() {
        global $wpdb;
        $event_ids = KE_Organizer_Stats::get_event_ids_for_organizer( $this->term->term_id );
        if ( empty( $event_ids ) ) return array();
        if ( $this->event_id > 0 ) {
            // Hard-scope to a single event when generating a per-event report.
            // event_id has already been authorized in the REST callback.
            $event_ids = array( $this->event_id );
        }
        $placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT t.event_id, t.attendee_name, t.attendee_email, t.status, t.created_at, t.checked_in_at,
                    t.ticket_type_snapshot, t.extra_fields_data, p.post_title AS event_title
             FROM {$wpdb->prefix}ke_tickets t
             LEFT JOIN {$wpdb->posts} p ON p.ID = t.event_id
             WHERE t.event_id IN ($placeholders) AND t.status != 'cancelled'
             ORDER BY t.created_at DESC",
            $event_ids
        ) );
    }

    /**
     * Tally answers per (event, select-field) for the Extras Summary section.
     *
     * Returns a list of { event_id, event_title, field_id, label, total, counts: [{value, count}] }
     * Only `select`-type fields are summarised — open-ended types (text, email,
     * phone, etc.) don't lend themselves to a histogram and would just clutter
     * the report with one row per attendee.
     */
    private function build_extras_summary( $rows ) {
        if ( ! class_exists( 'KE_Event_Extra_Fields' ) ) return array();
        $event_ids = array();
        if ( $this->event_id > 0 ) {
            $event_ids[] = $this->event_id;
        } else {
            $organizer_event_ids = KE_Organizer_Stats::get_event_ids_for_organizer( $this->term->term_id );
            $event_ids = $organizer_event_ids;
        }
        if ( empty( $event_ids ) ) return array();

        // Index attendee answers by event for quick lookup.
        $answers_by_event = array();
        foreach ( $rows as $r ) {
            $eid = (int) ( $r->event_id ?? 0 );
            if ( ! $eid || empty( $r->extra_fields_data ) ) continue;
            $decoded = json_decode( (string) $r->extra_fields_data, true );
            if ( ! is_array( $decoded ) ) continue;
            $answers_by_event[ $eid ][] = $decoded;
        }
        if ( empty( $answers_by_event ) ) return array();

        $sections = array();
        foreach ( $event_ids as $eid ) {
            $cfg = KE_Event_Extra_Fields::get_config( $eid );
            if ( empty( $cfg['fields'] ) ) continue;
            $event_answers = $answers_by_event[ $eid ] ?? array();
            if ( empty( $event_answers ) ) continue;

            foreach ( $cfg['fields'] as $f ) {
                if ( ( $f['type'] ?? 'text' ) !== 'select' ) continue;
                $counts = array();
                $total  = 0;
                foreach ( $event_answers as $ans ) {
                    $val = isset( $ans[ $f['id'] ] ) ? (string) $ans[ $f['id'] ] : '';
                    if ( $val === '' ) continue;
                    $counts[ $val ] = ( $counts[ $val ] ?? 0 ) + 1;
                    $total++;
                }
                if ( $total === 0 ) continue;
                arsort( $counts );
                $rows_out = array();
                foreach ( $counts as $val => $c ) {
                    $rows_out[] = array( 'value' => $val, 'count' => $c );
                }
                $sections[] = array(
                    'event_id'    => (int) $eid,
                    'event_title' => get_the_title( $eid ),
                    'field_id'    => (string) $f['id'],
                    'label'       => (string) $f['label'],
                    'total'       => $total,
                    'counts'      => $rows_out,
                );
            }
        }
        return $sections;
    }

    /**
     * For event-scoped reports, synthesize a "headline" from the single
     * event's row in events_breakdown so the executive-summary cards show
     * the event's numbers, not the organizer-wide ones.
     */
    private function headline_for_scope( $events ) {
        $organizer_headline = KE_Organizer_Stats::headline_stats( $this->term->term_id, $this->range );
        if ( $this->event_id <= 0 ) return $organizer_headline;

        // Find the single event in the breakdown.
        $row = null;
        foreach ( $events as $e ) {
            if ( (int) $e['id'] === $this->event_id ) { $row = $e; break; }
        }
        if ( ! $row ) {
            $row = array( 'tickets_sold' => 0, 'net_revenue' => 0.0, 'check_in_rate' => 0.0,
                          'check_in_total' => 0, 'check_in_used' => 0 );
        }
        return array_merge( $organizer_headline, array(
            'tickets_sold'   => (int) ( $row['tickets_sold']  ?? 0 ),
            'net_revenue'    => (float) ( $row['net_revenue']   ?? 0 ),
            'check_in_rate'  => (float) ( $row['check_in_rate'] ?? 0 ),
            'check_in_total' => (int) ( $row['check_in_total'] ?? 0 ),
            'check_in_used'  => (int) ( $row['check_in_used']  ?? 0 ),
            // Free vs paid is hard to derive from a single breakdown row;
            // leave the organizer-wide values rather than fabricate.
        ) );
    }

    /** Title for cover page + filename — adds event name when event-scoped. */
    private function report_title() {
        if ( $this->event_id > 0 ) {
            $post = get_post( $this->event_id );
            if ( $post ) {
                return $this->term->name . ' — ' . $post->post_title;
            }
        }
        return $this->term->name;
    }

    /* ──────────────────────────────────────────────────────────────
     *  PDF mode (FPDF)
     * ────────────────────────────────────────────────────────────── */

    private function stream_pdf() {
        try {
            $events_all = KE_Organizer_Stats::events_breakdown( $this->term->term_id, $this->range );
            $events     = $this->scoped_events( $events_all );
            $headline   = $this->headline_for_scope( $events_all );
            $rows       = $this->fetch_attendees();

            $pdf = new KE_Organizer_FPDF_Report( $this );
            $pdf->SetCreator( 'KiwiEvents' );
            $pdf->SetAuthor( $this->latin1( get_bloginfo( 'name' ) ) );
            $pdf->SetTitle( $this->latin1( $this->report_title() . ' - Sales Report' ) );
            $pdf->SetMargins( 15, 15, 15 );
            $pdf->SetAutoPageBreak( true, 20 );
            $pdf->AliasNbPages();

            $this->draw_pdf_cover( $pdf );
            $this->draw_pdf_summary( $pdf, $headline );
            $this->draw_pdf_events( $pdf, $events );
            $extras_sections = $this->build_extras_summary( $rows );
            if ( ! empty( $extras_sections ) ) {
                $this->draw_pdf_extras_summary( $pdf, $extras_sections );
            }
            $this->draw_pdf_attendees( $pdf, $rows );

            $name_part = sanitize_title( $this->term->slug );
            if ( $this->event_id > 0 ) {
                $name_part .= '-event-' . $this->event_id;
            }
            $filename = 'organizer-' . $name_part . '-report-' . gmdate( 'Y-m-d' ) . '.pdf';
            // Drop the REST stack's output buffers so FPDF's _checkoutput()
            // doesn't see leftover bytes and abort with "Some data has already
            // been output". Must come BEFORE nocache_headers() (which sends
            // its own headers) and before Output() (which sends Content-Type).
            $this->reset_output_buffers();
            nocache_headers();
            $pdf->Output( 'D', $filename );
        } catch ( \Throwable $e ) {
            // Last-ditch: log the real failure with a stack trace and stream
            // a 1-page error PDF so the user gets *something* instead of the
            // generic JSON 500 from the safe wrapper. Without this, any
            // unexpected throw bubbles back up into safe_organizer_callback
            // and the operator never sees the trace until they hit debug.log.
            error_log( sprintf(
                "[KiwiEvents] PDF generation failed: %s in %s:%d\n%s",
                $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
            ) );
            $this->stream_error_pdf( $e->getMessage() );
        }
    }

    /**
     * Minimal "we couldn't generate the report" 1-page PDF used as a last
     * resort when stream_pdf() throws. Avoids any of the data-driven code
     * paths that may have caused the failure — only renders fixed strings.
     */
    private function stream_error_pdf( $reason ) {
        if ( ! class_exists( 'FPDF' ) || ! self::fpdf_fonts_available() ) {
            // FPDF itself is broken — degrade to the HTML fallback rather
            // than try to draw a PDF we can't draw.
            $this->stream_html();
            return;
        }
        try {
            $pdf = new FPDF( 'P', 'mm', 'A4' );
            $pdf->SetMargins( 20, 20, 20 );
            $pdf->AddPage();
            list( $r, $g, $b ) = $this->accent['rgb'];

            $pdf->SetFillColor( $r, $g, $b );
            $pdf->Rect( 0, 0, $pdf->GetPageWidth(), 50, 'F' );
            $pdf->SetTextColor( 255, 255, 255 );
            $pdf->SetFont( 'Helvetica', 'B', 18 );
            $pdf->SetXY( 20, 18 );
            $pdf->Cell( 0, 10, $this->latin1( 'KiwiEvents - Sales Report' ), 0, 1 );

            $pdf->SetTextColor( 17, 24, 39 );
            $pdf->SetY( 70 );
            $pdf->SetFont( 'Helvetica', 'B', 14 );
            $pdf->Cell( 0, 8, $this->latin1( 'Could not generate report' ), 0, 1 );
            $pdf->Ln( 4 );
            $pdf->SetFont( 'Helvetica', '', 11 );
            $pdf->SetTextColor( 75, 85, 99 );
            $pdf->MultiCell( 0, 6, $this->latin1(
                'An error occurred while generating this report. The site administrator has been notified ' .
                '(check wp-content/debug.log for the full stack trace).'
            ) );
            $pdf->Ln( 4 );
            $pdf->SetFont( 'Helvetica', '', 9 );
            $pdf->SetTextColor( 156, 163, 175 );
            $pdf->Cell( 0, 6, $this->latin1( 'Reference: ' . $this->safe_truncate( $reason, 80 ) ), 0, 1 );
            $pdf->Cell( 0, 6, $this->latin1( 'Generated: ' . gmdate( 'Y-m-d H:i' ) . ' UTC' ), 0, 1 );

            $name_part = sanitize_title( $this->term->slug );
            if ( $this->event_id > 0 ) $name_part .= '-event-' . $this->event_id;
            $filename = 'organizer-' . $name_part . '-error-' . gmdate( 'Y-m-d' ) . '.pdf';
            $this->reset_output_buffers();
            nocache_headers();
            $pdf->Output( 'D', $filename );
        } catch ( \Throwable $inner ) {
            // Even the error PDF failed. Fall back to HTML, which uses an
            // entirely different code path (no FPDF).
            error_log( '[KiwiEvents] PDF error-fallback also failed: ' . $inner->getMessage() );
            $this->stream_html();
        }
    }

    private function draw_pdf_cover( $pdf ) {
        $pdf->AddPage();
        list( $r, $g, $b ) = $this->accent['rgb'];

        // Accent band
        $pdf->SetFillColor( $r, $g, $b );
        $pdf->Rect( 0, 0, $pdf->GetPageWidth(), 60, 'F' );

        // Logo (if any). FPDF only handles JPEG/PNG/GIF, so route every
        // attachment through pdf_safe_image_path() — that converts WebP/AVIF/
        // HEIC to JPEG on the fly (cached under uploads/ke-pdf-cache/) and
        // returns null when the source can't be transcoded.
        $logo_id = (int) get_term_meta( $this->term->term_id, 'ke_organizer_logo', true );
        $logo_x  = 15;
        if ( $logo_id ) {
            $logo_path = $this->pdf_safe_image_path( get_attached_file( $logo_id ) );
            if ( $logo_path ) {
                try {
                    $pdf->Image( $logo_path, 15, 15, 30, 30 );
                    $logo_x = 50;
                } catch ( \Throwable $img_e ) {
                    // FPDF still didn't like the file — fall through to the
                    // placeholder below rather than aborting the whole report.
                    error_log( '[KiwiEvents] PDF logo embed skipped: ' . $img_e->getMessage() );
                    $logo_path = null;
                }
            }
            if ( ! $logo_path ) {
                // Solid-accent placeholder so the cover still has a visual
                // anchor where the logo should be.
                list( $rr, $gg, $bb ) = $this->accent['rgb'];
                $pdf->SetFillColor( $rr, $gg, $bb );
                $pdf->Rect( 15, 15, 30, 30, 'F' );
                $logo_x = 50;
            }
        }

        // Title block on the band
        $pdf->SetTextColor( 255, 255, 255 );
        $pdf->SetFont( 'Helvetica', 'B', 10 );
        $pdf->SetXY( $logo_x, 22 );
        $pdf->Cell( 0, 5, $this->latin1( strtoupper( __( 'KiwiEvents · Sales Report', 'kiwi-events' ) ) ), 0, 1 );
        $pdf->SetFont( 'Helvetica', 'B', 22 );
        $pdf->SetXY( $logo_x, 28 );
        $pdf->Cell( 0, 12, $this->latin1( $this->report_title() ), 0, 1 );

        // Body
        $pdf->SetTextColor( 17, 24, 39 );
        $pdf->SetY( 80 );

        $rows = array(
            array( __( 'Date range', 'kiwi-events' ), $this->range_label() ),
            array( __( 'Generated',  'kiwi-events' ), date_i18n( 'F j, Y · H:i', current_time( 'timestamp' ) ) ),
            array( __( 'Prepared by','kiwi-events' ), get_bloginfo( 'name' ) ),
        );
        foreach ( $rows as $row ) {
            $pdf->SetFont( 'Helvetica', '', 11 );
            $pdf->SetTextColor( 107, 114, 128 );
            $pdf->Cell( 50, 7, $this->latin1( $row[0] ), 0, 0 );
            $pdf->SetFont( 'Helvetica', 'B', 11 );
            $pdf->SetTextColor( 17, 24, 39 );
            $pdf->Cell( 0, 7, $this->latin1( $row[1] ), 0, 1 );
        }

        $pdf->Ln( 6 );
        $pdf->SetDrawColor( 229, 231, 235 );
        $pdf->Line( 15, $pdf->GetY(), $pdf->GetPageWidth() - 15, $pdf->GetY() );
        $pdf->Ln( 4 );

        $pdf->SetFont( 'Helvetica', 'I', 10 );
        $pdf->SetTextColor( 107, 114, 128 );
        $pdf->MultiCell( 0, 6,
            $this->latin1( __( 'This report shows your net revenue (after service fees), tickets sold, and check-in performance for the events under your organization.', 'kiwi-events' ) )
        );
    }

    private function draw_pdf_summary( $pdf, $headline ) {
        $pdf->Ln( 8 );
        $pdf->SetTextColor( 17, 24, 39 );
        $pdf->SetFont( 'Helvetica', 'B', 14 );
        $pdf->Cell( 0, 8, $this->latin1( __( 'Executive summary', 'kiwi-events' ) ), 0, 1 );
        $pdf->Ln( 2 );

        // Render as a 2x2 grid of mini-cards
        $cards = array(
            array( __( 'Tickets sold', 'kiwi-events' ),    $this->fmt_int( $headline['tickets_sold'] ),    false ),
            array( __( 'Net revenue',  'kiwi-events' ),    $this->fmt_money( $headline['net_revenue'] ),  true  ),
            array( __( 'Free tickets', 'kiwi-events' ),    $this->fmt_int( $headline['free_tickets'] ),    false ),
            array( __( 'Check-in rate','kiwi-events' ),    $this->fmt_pct( $headline['check_in_rate'] ),   false ),
        );

        $page_w = $pdf->GetPageWidth() - 30;
        $cw     = ( $page_w - 9 ) / 4;
        $ch     = 22;
        $x = 15;
        $y = $pdf->GetY();

        list( $ar, $ag, $ab ) = $this->accent['rgb'];
        foreach ( $cards as $i => $c ) {
            $cx = $x + $i * ( $cw + 3 );
            $pdf->SetDrawColor( 229, 231, 235 );
            $pdf->SetFillColor( 250, 251, 252 );
            $pdf->Rect( $cx, $y, $cw, $ch, 'DF' );

            $pdf->SetXY( $cx + 4, $y + 4 );
            $pdf->SetFont( 'Helvetica', '', 8 );
            $pdf->SetTextColor( 107, 114, 128 );
            $pdf->Cell( $cw - 6, 4, $this->latin1( strtoupper( $c[0] ) ), 0, 1 );

            $pdf->SetXY( $cx + 4, $y + 10 );
            $pdf->SetFont( 'Helvetica', 'B', 14 );
            if ( $c[2] ) $pdf->SetTextColor( $ar, $ag, $ab );
            else        $pdf->SetTextColor( 17, 24, 39 );
            $pdf->Cell( $cw - 6, 8, $this->latin1( $c[1] ), 0, 1 );
        }
        $pdf->SetY( $y + $ch + 2 );
    }

    private function draw_pdf_events( $pdf, $events ) {
        $pdf->Ln( 6 );
        $pdf->SetTextColor( 17, 24, 39 );
        $pdf->SetFont( 'Helvetica', 'B', 14 );
        $pdf->Cell( 0, 8, $this->latin1( __( 'Events', 'kiwi-events' ) ), 0, 1 );
        $pdf->Ln( 1 );

        if ( empty( $events ) ) {
            $pdf->SetFont( 'Helvetica', 'I', 10 );
            $pdf->SetTextColor( 107, 114, 128 );
            $pdf->Cell( 0, 6, $this->latin1( __( 'No events in this range.', 'kiwi-events' ) ), 0, 1 );
            return;
        }

        list( $ar, $ag, $ab ) = $this->accent['rgb'];
        $pdf->SetFont( 'Helvetica', 'B', 9 );
        $pdf->SetFillColor( $ar, $ag, $ab );
        $pdf->SetTextColor( 255, 255, 255 );
        $pdf->SetDrawColor( $ar, $ag, $ab );
        $pdf->Cell( 80, 7, $this->latin1( __( 'Event',       'kiwi-events' ) ), 0, 0, 'L', true );
        $pdf->Cell( 30, 7, $this->latin1( __( 'Date',        'kiwi-events' ) ), 0, 0, 'L', true );
        $pdf->Cell( 22, 7, $this->latin1( __( 'Sold',        'kiwi-events' ) ), 0, 0, 'R', true );
        $pdf->Cell( 30, 7, $this->latin1( __( 'Net revenue', 'kiwi-events' ) ), 0, 0, 'R', true );
        $pdf->Cell( 0,  7, $this->latin1( __( 'Check-in',    'kiwi-events' ) ), 0, 1, 'R', true );

        $pdf->SetFont( 'Helvetica', '', 9 );
        $pdf->SetTextColor( 17, 24, 39 );
        $pdf->SetDrawColor( 229, 231, 235 );
        $alt = false;
        foreach ( $events as $e ) {
            try {
                $pdf->SetFillColor( $alt ? 250 : 255, $alt ? 251 : 255, $alt ? 252 : 255 );
                $title = $this->safe_truncate( $e['title'] ?? '-', 48 );
                $date  = $this->safe_date( $e['date'] ?? '' );

                $pdf->Cell( 80, 6, $this->latin1( $title ), 0, 0, 'L', $alt );
                $pdf->Cell( 30, 6, $this->latin1( $date  ), 0, 0, 'L', $alt );
                $pdf->Cell( 22, 6, $this->latin1( $this->fmt_int( $e['tickets_sold'] ?? 0 ) ),     0, 0, 'R', $alt );
                $pdf->Cell( 30, 6, $this->latin1( $this->fmt_money( $e['net_revenue'] ?? 0 ) ),   0, 0, 'R', $alt );
                $pdf->Cell( 0,  6, $this->latin1( $this->fmt_pct( $e['check_in_rate'] ?? 0 ) ),   0, 1, 'R', $alt );
                $alt = ! $alt;

                // Indented ticket-type breakdown beneath the event row.
                if ( ! empty( $e['ticket_types'] ) && is_array( $e['ticket_types'] ) ) {
                    $this->draw_pdf_event_types( $pdf, $e['ticket_types'] );
                    $pdf->SetFont( 'Helvetica', '', 9 );
                    $pdf->SetTextColor( 17, 24, 39 );
                }
            } catch ( \Throwable $row_e ) {
                // Don't let one weird event row sink the whole report. Log
                // and keep going — the operator gets every other row.
                error_log( '[KiwiEvents] PDF event row skipped: ' . $row_e->getMessage() );
            }
        }
    }

    /**
     * Indented sub-table of ticket-type counts under each event in the
     * Events section. Smaller font + 4mm left indent to communicate the
     * visual hierarchy without crowding the parent row.
     */
    private function draw_pdf_event_types( $pdf, $types ) {
        if ( empty( $types ) ) return;
        $indent = 4; // mm
        $pdf->SetFont( 'Helvetica', '', 8 );
        $pdf->SetTextColor( 75, 85, 99 ); // muted gray
        $total = 0;

        foreach ( $types as $t ) {
            try {
                $pdf->SetX( $pdf->GetX() + $indent ); // indent from left margin
                $name = $this->safe_truncate( $t['name'] ?? '-', 60 );
                $sold = (int) ( $t['sold'] ?? 0 );
                $total += $sold;
                $rev  = ! empty( $t['is_free'] )
                    ? __( 'Free', 'kiwi-events' )
                    : $this->fmt_money( $t['revenue'] ?? 0 ) . ' ' . __( 'net', 'kiwi-events' );

                // L bullet + name (~96mm), sold (~30mm), revenue cell (rest).
                $pdf->Cell( 4,  5, $this->latin1( '-' ), 0, 0, 'L' );
                $pdf->Cell( 92, 5, $this->latin1( $name ), 0, 0, 'L' );
                $pdf->Cell( 30, 5, $this->latin1( $this->fmt_int( $sold ) . ' sold' ), 0, 0, 'R' );
                $pdf->Cell( 0,  5, $this->latin1( $rev ), 0, 1, 'R' );
            } catch ( \Throwable $row_e ) {
                error_log( '[KiwiEvents] PDF type row skipped: ' . $row_e->getMessage() );
            }
        }
        // Total line
        try {
            $pdf->SetX( $pdf->GetX() + $indent );
            $pdf->SetFont( 'Helvetica', 'B', 8 );
            $pdf->SetTextColor( 31, 41, 55 );
            $pdf->Cell( 4,  5, '', 0, 0, 'L' );
            $pdf->Cell( 92, 5, $this->latin1( __( 'Total', 'kiwi-events' ) ), 0, 0, 'L' );
            $pdf->Cell( 30, 5, $this->latin1( $this->fmt_int( $total ) ), 0, 0, 'R' );
            $pdf->Cell( 0,  5, '', 0, 1, 'R' );
        } catch ( \Throwable $row_e ) {
            error_log( '[KiwiEvents] PDF type total skipped: ' . $row_e->getMessage() );
        }
        $pdf->Ln( 1 );
    }

    /**
     * Render the per-event Extras Summary — one block per select-type field
     * with a count of each chosen option, ordered most-to-least common.
     */
    private function draw_pdf_extras_summary( $pdf, $sections ) {
        $pdf->AddPage();
        $pdf->SetTextColor( 17, 24, 39 );
        $pdf->SetFont( 'Helvetica', 'B', 14 );
        $pdf->Cell( 0, 8, $this->latin1( __( 'Extras summary', 'kiwi-events' ) ), 0, 1 );
        $pdf->SetFont( 'Helvetica', '', 9 );
        $pdf->SetTextColor( 107, 114, 128 );
        $pdf->Cell( 0, 5, $this->latin1( __( 'Counts of dropdown answers collected at checkout.', 'kiwi-events' ) ), 0, 1 );
        $pdf->Ln( 3 );

        list( $ar, $ag, $ab ) = $this->accent['rgb'];
        $current_event_id = 0;
        foreach ( $sections as $sec ) {
            // Page-break safety — leave room for at least the header + one row.
            if ( $pdf->GetY() > $pdf->GetPageHeight() - 30 ) {
                $pdf->AddPage();
            }
            // Group by event when reporting on the whole organizer.
            if ( $this->event_id <= 0 && $current_event_id !== (int) $sec['event_id'] ) {
                $current_event_id = (int) $sec['event_id'];
                $pdf->SetFont( 'Helvetica', 'B', 10 );
                $pdf->SetTextColor( 17, 24, 39 );
                $pdf->Cell( 0, 6, $this->latin1( $sec['event_title'] ), 0, 1 );
                $pdf->Ln( 1 );
            }

            $pdf->SetFont( 'Helvetica', 'B', 10 );
            $pdf->SetTextColor( $ar, $ag, $ab );
            $pdf->Cell( 0, 6, $this->latin1( $sec['label'] . ' (' . $sec['total'] . ')' ), 0, 1 );

            $pdf->SetFont( 'Helvetica', '', 9 );
            $pdf->SetTextColor( 17, 24, 39 );
            foreach ( $sec['counts'] as $row ) {
                $bullet = '  • ' . $this->safe_truncate( (string) $row['value'], 60 );
                $pdf->Cell( 140, 5, $this->latin1( $bullet ), 0, 0, 'L' );
                $pdf->Cell( 0,   5, (string) $row['count'],   0, 1, 'R' );
            }
            $pdf->Ln( 2 );
        }
    }

    private function draw_pdf_attendees( $pdf, $rows ) {
        $pdf->AddPage();
        $pdf->SetTextColor( 17, 24, 39 );
        $pdf->SetFont( 'Helvetica', 'B', 14 );
        $pdf->Cell( 0, 8, $this->latin1( __( 'Attendee list', 'kiwi-events' ) ), 0, 1 );
        $pdf->SetFont( 'Helvetica', '', 9 );
        $pdf->SetTextColor( 107, 114, 128 );
        $pdf->Cell( 0, 5, $this->latin1( sprintf( __( '%d attendees total', 'kiwi-events' ), count( $rows ) ) ), 0, 1 );
        $pdf->Ln( 2 );

        if ( empty( $rows ) ) {
            $pdf->SetFont( 'Helvetica', 'I', 10 );
            // Reservation-only events legitimately have 0 ticketed attendees.
            // Surface that explicitly so the report doesn't read like a bug.
            if ( $this->event_id > 0 && class_exists( 'KE_Reservations' ) && KE_Reservations::is_active( $this->event_id ) ) {
                $pdf->MultiCell( 0, 5, $this->latin1( __( 'This event is reservation-only. Attendance is tracked via reservations rather than ticket sales.', 'kiwi-events' ) ), 0, 'L' );
            } else {
                $pdf->Cell( 0, 6, $this->latin1( __( 'No attendees yet.', 'kiwi-events' ) ), 0, 1 );
            }
            // For per-event reports on events with reservations, follow with a
            // small reservation-stats block so the operator gets a useful page.
            $this->draw_pdf_event_reservations_summary( $pdf );
            return;
        }

        list( $ar, $ag, $ab ) = $this->accent['rgb'];
        $draw_header = function() use ( $pdf, $ar, $ag, $ab ) {
            $pdf->SetFont( 'Helvetica', 'B', 8 );
            $pdf->SetFillColor( $ar, $ag, $ab );
            $pdf->SetTextColor( 255, 255, 255 );
            $pdf->Cell( 50, 6, $this->latin1( __( 'Name',   'kiwi-events' ) ), 0, 0, 'L', true );
            $pdf->Cell( 60, 6, $this->latin1( __( 'Email',  'kiwi-events' ) ), 0, 0, 'L', true );
            $pdf->Cell( 45, 6, $this->latin1( __( 'Event',  'kiwi-events' ) ), 0, 0, 'L', true );
            $pdf->Cell( 0,  6, $this->latin1( __( 'Status', 'kiwi-events' ) ), 0, 1, 'L', true );
        };
        $draw_header();

        $pdf->SetFont( 'Helvetica', '', 8 );
        $pdf->SetTextColor( 17, 24, 39 );
        $alt = false;
        foreach ( $rows as $r ) {
            try {
                // Repeat the header on every page break inside the attendee list.
                if ( $pdf->GetY() > $pdf->GetPageHeight() - 25 ) {
                    $pdf->AddPage();
                    $draw_header();
                    $pdf->SetFont( 'Helvetica', '', 8 );
                    $pdf->SetTextColor( 17, 24, 39 );
                }
                // Defensive: every column may legitimately be NULL. A free
                // ticket can have empty attendee_name; an attendee_email is
                // optional in some flows; event_title is NULL if the post
                // was deleted but the ticket remained.
                $name   = $this->safe_truncate( ! empty( $r->attendee_name )  ? $r->attendee_name  : 'Anonymous', 28 );
                $email  = $this->safe_truncate( ! empty( $r->attendee_email ) ? $r->attendee_email : '-',         36 );
                $event  = $this->safe_truncate( ! empty( $r->event_title )    ? $r->event_title    : '-',         26 );
                $status = ( ( $r->status ?? '' ) === 'used' )
                    ? __( 'Checked in', 'kiwi-events' )
                    : __( 'Valid',      'kiwi-events' );

                $pdf->SetFillColor( $alt ? 250 : 255, $alt ? 251 : 255, $alt ? 252 : 255 );
                $pdf->Cell( 50, 5, $this->latin1( $name  ),  0, 0, 'L', $alt );
                $pdf->Cell( 60, 5, $this->latin1( $email ),  0, 0, 'L', $alt );
                $pdf->Cell( 45, 5, $this->latin1( $event ),  0, 0, 'L', $alt );
                $pdf->Cell( 0,  5, $this->latin1( $status ), 0, 1, 'L', $alt );
                $alt = ! $alt;
            } catch ( \Throwable $row_e ) {
                // Skip the bad row instead of aborting the whole report.
                // Log so the operator can spot the offending record.
                error_log( '[KiwiEvents] PDF attendee row skipped: ' . $row_e->getMessage() );
            }
        }
        // Hybrid event (tickets + reservations): include the reservation stats
        // beneath the attendee table so the report covers both booking modes.
        $this->draw_pdf_event_reservations_summary( $pdf );
    }

    /**
     * Per-event reservations summary block for PDF reports.
     *
     * Only renders when the report is scoped to a single event ($this->event_id > 0)
     * AND that event has reservations enabled. For reservation-only events this
     * is the only meaningful "what happened at this event" content; for hybrid
     * events it complements the attendee list above.
     */
    private function draw_pdf_event_reservations_summary( $pdf ) {
        if ( $this->event_id <= 0 ) return;
        if ( ! class_exists( 'KE_Reservations' ) ) return;
        if ( ! KE_Reservations::is_active( $this->event_id ) ) return;

        try {
            $resv = new KE_Reservations();
            if ( ! method_exists( $resv, 'compute_stats' ) ) return;
            $stats = $resv->compute_stats( array( 'event_id' => $this->event_id ) );
        } catch ( \Throwable $e ) {
            error_log( '[KiwiEvents] PDF reservations stats fetch failed: ' . $e->getMessage() );
            return;
        }
        $by_status = isset( $stats['by_status'] ) && is_array( $stats['by_status'] ) ? $stats['by_status'] : array();
        $total     = (int) ( $stats['total_rows']    ?? 0 );
        $seats     = (int) ( $stats['total_seats']   ?? 0 );
        $holding   = (int) ( $stats['holding_seats'] ?? 0 );
        $checked   = (int) ( $stats['checked_in']    ?? 0 );

        $pdf->Ln( 6 );
        $pdf->SetFont( 'Helvetica', 'B', 13 );
        $pdf->SetTextColor( 17, 24, 39 );
        $pdf->Cell( 0, 7, $this->latin1( __( 'Reservations', 'kiwi-events' ) ), 0, 1 );

        $pdf->SetFont( 'Helvetica', '', 9 );
        $pdf->SetTextColor( 107, 114, 128 );
        $pdf->Cell( 0, 5, $this->latin1( sprintf(
            __( '%1$d reservations · %2$d total seats · %3$d holding · %4$d checked in', 'kiwi-events' ),
            $total, $seats, $holding, $checked
        ) ), 0, 1 );
        $pdf->Ln( 2 );

        if ( ! empty( $by_status ) ) {
            list( $ar, $ag, $ab ) = $this->accent['rgb'];
            $pdf->SetFont( 'Helvetica', 'B', 8 );
            $pdf->SetFillColor( $ar, $ag, $ab );
            $pdf->SetTextColor( 255, 255, 255 );
            $pdf->Cell( 80, 6, $this->latin1( __( 'Status', 'kiwi-events' ) ),         0, 0, 'L', true );
            $pdf->Cell( 35, 6, $this->latin1( __( 'Reservations', 'kiwi-events' ) ),   0, 0, 'R', true );
            $pdf->Cell( 0,  6, $this->latin1( __( 'Seats', 'kiwi-events' ) ),          0, 1, 'R', true );

            $pdf->SetFont( 'Helvetica', '', 9 );
            $pdf->SetTextColor( 17, 24, 39 );
            foreach ( $by_status as $status_key => $row ) {
                $label = ucwords( str_replace( '_', ' ', (string) $status_key ) );
                $cnt   = (int) ( $row['count']  ?? 0 );
                $st    = (int) ( $row['seats']  ?? 0 );
                $pdf->Cell( 80, 5, $this->latin1( $label ), 0, 0, 'L' );
                $pdf->Cell( 35, 5, $this->latin1( (string) $cnt ), 0, 0, 'R' );
                $pdf->Cell( 0,  5, $this->latin1( (string) $st ),  0, 1, 'R' );
            }
        }
    }

    /* ──────────────────────────────────────────────────────────────
     *  HTML fallback
     *
     *  Used when the FPDF library file isn't present. Renders the same
     *  data as a printable HTML page so the organizer can still get a
     *  report — and ⌘P / Ctrl+P will save as PDF on every modern browser.
     * ────────────────────────────────────────────────────────────── */

    private function stream_html() {
        $events_all = KE_Organizer_Stats::events_breakdown( $this->term->term_id, $this->range );
        $events     = $this->scoped_events( $events_all );
        $headline   = $this->headline_for_scope( $events_all );
        $rows       = $this->fetch_attendees();
        $title      = $this->report_title();
        list( $ar, $ag, $ab ) = $this->accent['rgb'];
        $accent_hex = $this->accent['hex'];

        $logo_id  = (int) get_term_meta( $this->term->term_id, 'ke_organizer_logo', true );
        $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

        // Same reasoning as stream_pdf(): clear the REST stack's output
        // buffers before sending HTML so we don't collide with the JSON
        // response WordPress would otherwise emit.
        $this->reset_output_buffers();
        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        ?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
<meta charset="utf-8" />
<title><?php echo esc_html( sprintf( __( '%s — Sales Report', 'kiwi-events' ), $title ) ); ?></title>
<style>
    :root { --accent: <?php echo esc_html( $accent_hex ); ?>; --text:#111827; --muted:#6b7280; --soft:#e5e7eb; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #f5f6fa; color: var(--text); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    .wrap { max-width: 920px; margin: 0 auto; padding: 28px; }
    .print-bar { background: #fff; border:1px solid var(--soft); border-radius: 12px; padding: 14px 18px; display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
    .print-bar p { margin: 0; font-size: 13px; color: var(--muted); }
    .print-bar button { padding: 8px 18px; border:none; border-radius: 999px; background: var(--accent); color:#fff; font-weight: 600; font-size: 13px; cursor: pointer; }
    .cover { background: var(--accent); color: #fff; border-radius: 16px; padding: 32px; display:flex; gap: 20px; align-items:center; margin-bottom: 24px; }
    .cover img { width: 70px; height: 70px; border-radius: 50%; object-fit: cover; background: #fff; }
    .cover h1 { margin: 0 0 4px; font-size: 26px; font-weight: 800; }
    .cover .eyebrow { text-transform: uppercase; letter-spacing: 0.12em; font-size: 11px; opacity: 0.85; }
    .meta { background:#fff; border:1px solid var(--soft); border-radius: 12px; padding: 18px 22px; margin-bottom: 24px; display:grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
    .meta div .k { font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); }
    .meta div .v { font-size: 14px; font-weight: 700; color: var(--text); }
    section { background:#fff; border:1px solid var(--soft); border-radius: 12px; padding: 22px; margin-bottom: 18px; }
    section h2 { margin: 0 0 14px; font-size: 16px; font-weight: 700; }
    .grid { display:grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
    .stat { background:#fafbfc; border:1px solid var(--soft); border-radius: 10px; padding: 14px 16px; }
    .stat .k { font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); margin-bottom: 4px; }
    .stat .v { font-size: 22px; font-weight: 800; color: var(--text); }
    .stat .v.accent { color: var(--accent); }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    table th { text-align: left; padding: 10px 12px; background: var(--accent); color:#fff; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; }
    table td { padding: 10px 12px; border-bottom: 1px solid var(--soft); color: #1f2937; }
    table tr:nth-child(even) td { background: #fafbfc; }
    table .num { text-align: right; }
    .footer { text-align: center; color: var(--muted); font-size: 12px; padding: 20px 0; }
    @media print {
        body { background: #fff; }
        .print-bar { display: none; }
        .wrap { padding: 0; }
        section, .meta { break-inside: avoid; box-shadow: none; }
    }
</style>
</head>
<body>
<div class="wrap">
    <div class="print-bar">
        <p><?php esc_html_e( 'PDF library not available — this is the printable HTML version. Use your browser\'s Print → Save as PDF.', 'kiwi-events' ); ?></p>
        <button type="button" onclick="window.print();"><?php esc_html_e( 'Print / Save as PDF', 'kiwi-events' ); ?></button>
    </div>

    <div class="cover">
        <?php if ( $logo_url ) : ?>
            <img src="<?php echo esc_url( $logo_url ); ?>" alt="" />
        <?php endif; ?>
        <div>
            <p class="eyebrow"><?php esc_html_e( 'KiwiEvents · Sales Report', 'kiwi-events' ); ?></p>
            <h1><?php echo esc_html( $title ); ?></h1>
        </div>
    </div>

    <div class="meta">
        <div><div class="k"><?php esc_html_e( 'Date range', 'kiwi-events' ); ?></div><div class="v"><?php echo esc_html( $this->range_label() ); ?></div></div>
        <div><div class="k"><?php esc_html_e( 'Generated',  'kiwi-events' ); ?></div><div class="v"><?php echo esc_html( date_i18n( 'F j, Y · H:i', current_time( 'timestamp' ) ) ); ?></div></div>
        <div><div class="k"><?php esc_html_e( 'Prepared by','kiwi-events' ); ?></div><div class="v"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div></div>
    </div>

    <section>
        <h2><?php esc_html_e( 'Executive summary', 'kiwi-events' ); ?></h2>
        <div class="grid">
            <div class="stat"><div class="k"><?php esc_html_e( 'Tickets sold', 'kiwi-events' ); ?></div><div class="v"><?php echo esc_html( $this->fmt_int( $headline['tickets_sold'] ) ); ?></div></div>
            <div class="stat"><div class="k"><?php esc_html_e( 'Net revenue', 'kiwi-events' ); ?></div><div class="v accent"><?php echo esc_html( $this->fmt_money( $headline['net_revenue'] ) ); ?></div></div>
            <div class="stat"><div class="k"><?php esc_html_e( 'Free tickets', 'kiwi-events' ); ?></div><div class="v"><?php echo esc_html( $this->fmt_int( $headline['free_tickets'] ) ); ?></div></div>
            <div class="stat"><div class="k"><?php esc_html_e( 'Check-in rate', 'kiwi-events' ); ?></div><div class="v"><?php echo esc_html( $this->fmt_pct( $headline['check_in_rate'] ) ); ?></div></div>
        </div>
    </section>

    <section>
        <h2><?php esc_html_e( 'Events', 'kiwi-events' ); ?></h2>
        <?php if ( empty( $events ) ) : ?>
            <p style="color:#6b7280;"><?php esc_html_e( 'No events in this range.', 'kiwi-events' ); ?></p>
        <?php else : ?>
            <table>
                <thead><tr>
                    <th><?php esc_html_e( 'Event',       'kiwi-events' ); ?></th>
                    <th><?php esc_html_e( 'Date',        'kiwi-events' ); ?></th>
                    <th class="num"><?php esc_html_e( 'Sold',        'kiwi-events' ); ?></th>
                    <th class="num"><?php esc_html_e( 'Net revenue', 'kiwi-events' ); ?></th>
                    <th class="num"><?php esc_html_e( 'Check-in',    'kiwi-events' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $events as $e ) : ?>
                    <tr>
                        <td><?php echo esc_html( $e['title'] ); ?></td>
                        <td><?php echo esc_html( $e['date'] ? date_i18n( 'M j, Y', strtotime( $e['date'] ) ) : '—' ); ?></td>
                        <td class="num"><?php echo esc_html( $this->fmt_int( $e['tickets_sold'] ) ); ?></td>
                        <td class="num"><?php echo esc_html( $this->fmt_money( $e['net_revenue'] ) ); ?></td>
                        <td class="num"><?php echo esc_html( $this->fmt_pct( $e['check_in_rate'] ) ); ?></td>
                    </tr>
                    <?php if ( ! empty( $e['ticket_types'] ) ) : ?>
                        <?php foreach ( $e['ticket_types'] as $tt ) : ?>
                            <tr style="background:#fafbfc;">
                                <td style="padding-left:32px; color:#4b5563; font-size:12px;">— <?php echo esc_html( $tt['name'] ?? '—' ); ?></td>
                                <td></td>
                                <td class="num" style="color:#4b5563; font-size:12px;"><?php echo esc_html( $this->fmt_int( $tt['sold'] ?? 0 ) ); ?> sold</td>
                                <td class="num" style="color:#4b5563; font-size:12px;">
                                    <?php echo ! empty( $tt['is_free'] )
                                        ? esc_html__( 'Free', 'kiwi-events' )
                                        : esc_html( $this->fmt_money( $tt['revenue'] ?? 0 ) . ' net' ); ?>
                                </td>
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <?php
    $extras_sections = $this->build_extras_summary( $rows );
    if ( ! empty( $extras_sections ) ) :
        $current_event_id_html = 0;
    ?>
    <section>
        <h2><?php esc_html_e( 'Extras summary', 'kiwi-events' ); ?></h2>
        <p style="color:#6b7280;font-size:12px;margin-top:-6px;margin-bottom:14px;">
            <?php esc_html_e( 'Counts of dropdown answers collected at checkout.', 'kiwi-events' ); ?>
        </p>
        <?php foreach ( $extras_sections as $sec ) : ?>
            <?php if ( $this->event_id <= 0 && $current_event_id_html !== (int) $sec['event_id'] ) : ?>
                <?php $current_event_id_html = (int) $sec['event_id']; ?>
                <h3 style="margin:14px 0 6px;font-size:14px;"><?php echo esc_html( $sec['event_title'] ); ?></h3>
            <?php endif; ?>
            <h4 style="margin:10px 0 4px;font-size:13px;color:var(--accent);">
                <?php echo esc_html( $sec['label'] ); ?>
                <span style="color:#6b7280;font-weight:500;">(<?php echo (int) $sec['total']; ?>)</span>
            </h4>
            <table style="margin-bottom:12px;">
                <tbody>
                <?php foreach ( $sec['counts'] as $c ) : ?>
                    <tr>
                        <td><?php echo esc_html( $c['value'] ); ?></td>
                        <td class="num" style="width:80px;"><?php echo (int) $c['count']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <section>
        <h2><?php echo esc_html( sprintf( __( 'Attendees (%d)', 'kiwi-events' ), count( $rows ) ) ); ?></h2>
        <?php if ( empty( $rows ) ) : ?>
            <p style="color:#6b7280;"><?php esc_html_e( 'No attendees yet.', 'kiwi-events' ); ?></p>
        <?php else : ?>
            <table>
                <thead><tr>
                    <th><?php esc_html_e( 'Name',   'kiwi-events' ); ?></th>
                    <th><?php esc_html_e( 'Email',  'kiwi-events' ); ?></th>
                    <th><?php esc_html_e( 'Event',  'kiwi-events' ); ?></th>
                    <th><?php esc_html_e( 'Ticket', 'kiwi-events' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'kiwi-events' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $rows as $r ) : ?>
                    <tr>
                        <td><?php echo esc_html( $r->attendee_name  ?: '—' ); ?></td>
                        <td><?php echo esc_html( $r->attendee_email ?: '—' ); ?></td>
                        <td><?php echo esc_html( $r->event_title    ?: '—' ); ?></td>
                        <td><?php echo esc_html( $r->ticket_type_snapshot ?: '—' ); ?></td>
                        <td><?php echo esc_html( $r->status === 'used' ? __( 'Checked in', 'kiwi-events' ) : __( 'Valid', 'kiwi-events' ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <p class="footer">
        <?php
        printf(
            /* translators: %s: site name */
            esc_html__( 'Powered by Kiwi Events · %s', 'kiwi-events' ),
            esc_html( get_bloginfo( 'name' ) )
        );
        ?>
    </p>
</div>
</body>
</html>
        <?php
    }

    /* ──────────────────────────────────────────────────────────────
     *  Internal: expose helpers to the FPDF subclass for the footer.
     * ────────────────────────────────────────────────────────────── */
    public function _footer_text() {
        return $this->latin1( sprintf(
            /* translators: 1: site name, 2: page X of Y placeholder rendered by FPDF */
            __( 'Powered by Kiwi Events · %s', 'kiwi-events' ),
            get_bloginfo( 'name' )
        ) );
    }
}

/**
 * Tiny FPDF subclass whose only job is the footer. Defined here, not in its
 * own file, so it loads in lockstep with the report class and only when FPDF
 * is actually present.
 */
if ( ! class_exists( 'KE_Organizer_FPDF_Report' ) ) {
    if ( class_exists( 'FPDF' ) ) {
        class KE_Organizer_FPDF_Report extends FPDF {
            /** @var KE_Organizer_Report_PDF */
            private $report;
            public function __construct( $report ) {
                parent::__construct( 'P', 'mm', 'A4' );
                $this->report = $report;
            }
            public function Footer() {
                $this->SetY( -14 );
                $this->SetFont( 'Helvetica', '', 8 );
                $this->SetTextColor( 156, 163, 175 );
                $left = $this->report->_footer_text();
                $this->Cell( 0, 6, $left, 0, 0, 'L' );
                // FPDF replaces {nb} with the total page count after AliasNbPages().
                $this->Cell( 0, 6, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R' );
            }
        }
    }
}
