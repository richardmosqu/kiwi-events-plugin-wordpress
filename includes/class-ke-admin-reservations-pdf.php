<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// FPDF may already have been loaded by class-ke-organizer-report-pdf.php; if
// not, try to load it ourselves so the FPDF subclass at the bottom can extend
// it. Missing FPDF files just falls back to the printable HTML report.
if ( ! class_exists( 'FPDF' ) ) {
    $ke_fpdf_path = KE_PLUGIN_DIR . 'includes/vendor/fpdf/fpdf.php';
    if ( file_exists( $ke_fpdf_path ) ) {
        require_once $ke_fpdf_path;
    }
    unset( $ke_fpdf_path );
}

/**
 * Reservations report for the wp-admin Reservations page.
 *
 * Identical lifecycle to KE_Organizer_Report_PDF: try FPDF first, fall back
 * to a printable HTML page if FPDF / its fonts aren't deployable. Output is
 * scoped by the filter args passed in (event_id / status / search).
 *
 * Sections (all modes):
 *   - Cover (title, scope summary, generation timestamp)
 *   - Status breakdown (rows + held seats per status)
 *   - Per-event breakdown (only on All Events scope)
 *   - Reservation list (one row per reservation)
 */
class KE_Admin_Reservations_Report_PDF {

    /** @var array filter args (event_id, status, search) */
    private $args;

    /** @var array{hex:string,rgb:int[]} */
    private $accent;

    public function __construct( $args = array() ) {
        $this->args = wp_parse_args( $args, array(
            'event_id' => 0,
            'status'   => '',
            'search'   => '',
            'limit'    => 50000,
            'offset'   => 0,
        ) );

        $ui  = get_option( 'ke_ui_settings', array() );
        $hex = ! empty( $ui['accent_color'] ) ? sanitize_hex_color( $ui['accent_color'] ) : '#6366f1';
        $this->accent = array( 'hex' => $hex, 'rgb' => $this->hex_to_rgb( $hex ) );
    }

    public static function fpdf_fonts_available() {
        $base = KE_PLUGIN_DIR . 'includes/vendor/fpdf/font/';
        foreach ( array( 'helvetica.php', 'helveticab.php', 'helveticai.php', 'helveticabi.php' ) as $f ) {
            if ( ! file_exists( $base . $f ) ) return false;
        }
        return true;
    }

    public function stream() {
        $can_use_pdf = class_exists( 'FPDF' )
            && class_exists( 'KE_Admin_Reservations_FPDF_Report', false )
            && self::fpdf_fonts_available();
        if ( $can_use_pdf ) {
            $this->stream_pdf();
        } else {
            $this->stream_html();
        }
    }

    /* ──────────────────────────────────────────────────────────────
     *  Helpers
     * ────────────────────────────────────────────────────────────── */

    private function hex_to_rgb( $hex ) {
        $hex = ltrim( (string) $hex, '#' );
        if ( strlen( $hex ) !== 6 ) return array( 99, 102, 241 );
        return array(
            (int) hexdec( substr( $hex, 0, 2 ) ),
            (int) hexdec( substr( $hex, 2, 2 ) ),
            (int) hexdec( substr( $hex, 4, 2 ) ),
        );
    }

    /** FPDF blows up on UTF-8 — transliterate to Latin-1 before printing. */
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
        return preg_replace( '/[^\x20-\x7E]/', '', $s );
    }

    private function safe_truncate( $s, $max ) {
        $s = (string) ( $s ?? '' );
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $s ) <= $max ) return $s;
            return mb_substr( $s, 0, $max - 1 ) . '...';
        }
        if ( strlen( $s ) <= $max ) return $s;
        return substr( $s, 0, $max - 1 ) . '...';
    }

    private function safe_dt( $s ) {
        if ( ! $s || $s === '0000-00-00 00:00:00' ) return '-';
        $ts = strtotime( (string) $s );
        if ( ! $ts ) return '-';
        return date_i18n( 'M j · g:i A', $ts );
    }

    private function status_label( $s ) {
        $map = array(
            'pending'            => 'Pending',
            'confirmed'          => 'Confirmed',
            'cancelled'          => 'Cancelled by customer',
            'cancelled_no_show'  => 'No-show',
            'cancelled_by_venue' => 'Cancelled by venue',
            'declined'           => 'Declined',
        );
        return $map[ $s ] ?? ucfirst( str_replace( '_', ' ', (string) $s ) );
    }

    private function reset_output_buffers() {
        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }
    }

    /** Title for cover + filename — reflects the active scope. */
    private function report_title() {
        if ( (int) $this->args['event_id'] > 0 ) {
            $post = get_post( (int) $this->args['event_id'] );
            return $post ? ( 'Reservations — ' . $post->post_title ) : 'Reservations';
        }
        return 'Reservations — All events';
    }

    /** Filename suffix, matches the title scope. */
    private function filename_part() {
        if ( (int) $this->args['event_id'] > 0 ) {
            return 'event-' . (int) $this->args['event_id'];
        }
        return 'all-events';
    }

    /** Human-readable scope summary for the cover page. */
    private function scope_lines() {
        $lines = array();
        if ( (int) $this->args['event_id'] > 0 ) {
            $title = get_the_title( (int) $this->args['event_id'] ) ?: '';
            $lines[] = array( 'Event', $title ?: '#' . (int) $this->args['event_id'] );
        } else {
            $lines[] = array( 'Event', 'All events' );
        }
        $lines[] = array(
            'Status',
            $this->args['status'] !== '' ? $this->status_label( $this->args['status'] ) : 'All statuses',
        );
        if ( $this->args['search'] !== '' ) {
            $lines[] = array( 'Search', '"' . $this->args['search'] . '"' );
        }
        $lines[] = array( 'Generated', date_i18n( 'F j, Y · H:i', current_time( 'timestamp' ) ) );
        return $lines;
    }

    /* ──────────────────────────────────────────────────────────────
     *  Data loading
     * ────────────────────────────────────────────────────────────── */

    private function load_data() {
        $reservations = new KE_Reservations();
        $rows  = $reservations->get_all( $this->args );
        $stats = $reservations->compute_stats( array(
            'event_id' => (int) $this->args['event_id'],
            'search'   => (string) $this->args['search'],
        ) );

        // Per-event breakdown — only meaningful on the All-Events scope.
        $by_event = array();
        if ( (int) $this->args['event_id'] <= 0 ) {
            foreach ( $rows as $r ) {
                $eid = (int) $r->event_id;
                if ( ! isset( $by_event[ $eid ] ) ) {
                    $by_event[ $eid ] = array(
                        'event_id'      => $eid,
                        'event_title'   => $r->event_title ?: ( '#' . $eid ),
                        'rows'          => 0,
                        'seats'         => 0,
                        'holding_seats' => 0,
                        'checked_in'    => 0,
                    );
                }
                $by_event[ $eid ]['rows']++;
                $by_event[ $eid ]['seats'] += (int) $r->party_size;
                if ( in_array( $r->status, array( 'pending', 'confirmed' ), true ) ) {
                    $by_event[ $eid ]['holding_seats'] += (int) $r->party_size;
                }
                if ( ! empty( $r->checked_in_at ) ) $by_event[ $eid ]['checked_in']++;
            }
            // Largest first — most-active events at the top of the report.
            usort( $by_event, function ( $a, $b ) { return $b['rows'] <=> $a['rows']; } );
        }

        return array( 'rows' => $rows, 'stats' => $stats, 'by_event' => array_values( $by_event ) );
    }

    /* ──────────────────────────────────────────────────────────────
     *  PDF mode
     * ────────────────────────────────────────────────────────────── */

    private function stream_pdf() {
        try {
            $bundle = $this->load_data();
            $pdf = new KE_Admin_Reservations_FPDF_Report( $this );
            $pdf->SetCreator( 'KiwiEvents' );
            $pdf->SetAuthor( $this->latin1( get_bloginfo( 'name' ) ) );
            $pdf->SetTitle( $this->latin1( $this->report_title() ) );
            $pdf->SetMargins( 15, 15, 15 );
            $pdf->SetAutoPageBreak( true, 20 );
            $pdf->AliasNbPages();

            $this->draw_cover( $pdf );
            $this->draw_status_summary( $pdf, $bundle['stats'] );
            if ( ! empty( $bundle['by_event'] ) ) {
                $this->draw_event_breakdown( $pdf, $bundle['by_event'] );
            }
            $this->draw_reservation_list( $pdf, $bundle['rows'] );

            $filename = 'reservations-' . $this->filename_part() . '-' . gmdate( 'Y-m-d' ) . '.pdf';
            $this->reset_output_buffers();
            nocache_headers();
            $pdf->Output( 'D', $filename );
        } catch ( \Throwable $e ) {
            error_log( sprintf(
                "[KiwiEvents] reservations PDF failed: %s in %s:%d\n%s",
                $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
            ) );
            $this->stream_html();
        }
    }

    private function draw_cover( $pdf ) {
        $pdf->AddPage();
        list( $r, $g, $b ) = $this->accent['rgb'];

        // Accent band.
        $pdf->SetFillColor( $r, $g, $b );
        $pdf->Rect( 0, 0, $pdf->GetPageWidth(), 50, 'F' );

        $pdf->SetTextColor( 255, 255, 255 );
        $pdf->SetFont( 'Helvetica', 'B', 10 );
        $pdf->SetXY( 15, 18 );
        $pdf->Cell( 0, 5, $this->latin1( strtoupper( 'KiwiEvents · Reservations Report' ) ), 0, 1 );
        $pdf->SetFont( 'Helvetica', 'B', 20 );
        $pdf->SetXY( 15, 24 );
        $pdf->Cell( 0, 12, $this->latin1( $this->report_title() ), 0, 1 );

        // Body — scope lines.
        $pdf->SetTextColor( 17, 24, 39 );
        $pdf->SetY( 65 );
        foreach ( $this->scope_lines() as $line ) {
            $pdf->SetFont( 'Helvetica', '', 11 );
            $pdf->SetTextColor( 107, 114, 128 );
            $pdf->Cell( 50, 7, $this->latin1( $line[0] ), 0, 0 );
            $pdf->SetFont( 'Helvetica', 'B', 11 );
            $pdf->SetTextColor( 17, 24, 39 );
            $pdf->Cell( 0, 7, $this->latin1( $line[1] ), 0, 1 );
        }

        $pdf->Ln( 4 );
        $pdf->SetDrawColor( 229, 231, 235 );
        $pdf->Line( 15, $pdf->GetY(), $pdf->GetPageWidth() - 15, $pdf->GetY() );
    }

    private function draw_status_summary( $pdf, $stats ) {
        $pdf->Ln( 8 );
        $pdf->SetTextColor( 17, 24, 39 );
        $pdf->SetFont( 'Helvetica', 'B', 14 );
        $pdf->Cell( 0, 8, $this->latin1( 'Status breakdown' ), 0, 1 );
        $pdf->Ln( 1 );

        list( $ar, $ag, $ab ) = $this->accent['rgb'];

        // Top totals strip — 4 mini cards: total / holding seats / checked-in / total seats.
        $cards = array(
            array( 'TOTAL',         number_format( (int) $stats['total_rows'] ),    false ),
            array( 'HOLDING SEATS', number_format( (int) $stats['holding_seats'] ), true ),
            array( 'CHECKED IN',    number_format( (int) $stats['checked_in'] ),    false ),
            array( 'TOTAL SEATS',   number_format( (int) $stats['total_seats'] ),   false ),
        );
        $page_w = $pdf->GetPageWidth() - 30;
        $cw     = ( $page_w - 9 ) / 4;
        $ch     = 22;
        $x = 15;
        $y = $pdf->GetY();
        foreach ( $cards as $i => $c ) {
            $cx = $x + $i * ( $cw + 3 );
            $pdf->SetDrawColor( 229, 231, 235 );
            $pdf->SetFillColor( 250, 251, 252 );
            $pdf->Rect( $cx, $y, $cw, $ch, 'DF' );

            $pdf->SetXY( $cx + 4, $y + 4 );
            $pdf->SetFont( 'Helvetica', '', 8 );
            $pdf->SetTextColor( 107, 114, 128 );
            $pdf->Cell( $cw - 6, 4, $this->latin1( $c[0] ), 0, 1 );

            $pdf->SetXY( $cx + 4, $y + 10 );
            $pdf->SetFont( 'Helvetica', 'B', 14 );
            if ( $c[2] ) $pdf->SetTextColor( $ar, $ag, $ab );
            else        $pdf->SetTextColor( 17, 24, 39 );
            $pdf->Cell( $cw - 6, 8, $this->latin1( $c[1] ), 0, 1 );
        }
        $pdf->SetY( $y + $ch + 4 );

        // Per-status table.
        $pdf->SetFont( 'Helvetica', 'B', 9 );
        $pdf->SetFillColor( $ar, $ag, $ab );
        $pdf->SetTextColor( 255, 255, 255 );
        $pdf->Cell( 90, 7, $this->latin1( 'Status' ),       0, 0, 'L', true );
        $pdf->Cell( 30, 7, $this->latin1( 'Reservations' ), 0, 0, 'R', true );
        $pdf->Cell( 30, 7, $this->latin1( 'Seats' ),        0, 0, 'R', true );
        $pdf->Cell( 0,  7, $this->latin1( 'Checked in' ),   0, 1, 'R', true );

        $pdf->SetFont( 'Helvetica', '', 9 );
        $pdf->SetTextColor( 17, 24, 39 );
        $alt = false;
        foreach ( $stats['by_status'] as $key => $row ) {
            $pdf->SetFillColor( $alt ? 250 : 255, $alt ? 251 : 255, $alt ? 252 : 255 );
            $pdf->Cell( 90, 6, $this->latin1( $this->status_label( $key ) ),    0, 0, 'L', $alt );
            $pdf->Cell( 30, 6, $this->latin1( number_format( (int) $row['rows'] ) ),       0, 0, 'R', $alt );
            $pdf->Cell( 30, 6, $this->latin1( number_format( (int) $row['seats'] ) ),      0, 0, 'R', $alt );
            $pdf->Cell( 0,  6, $this->latin1( number_format( (int) $row['checked_in'] ) ), 0, 1, 'R', $alt );
            $alt = ! $alt;
        }
    }

    private function draw_event_breakdown( $pdf, $by_event ) {
        $pdf->Ln( 6 );
        $pdf->SetTextColor( 17, 24, 39 );
        $pdf->SetFont( 'Helvetica', 'B', 14 );
        $pdf->Cell( 0, 8, $this->latin1( 'By event' ), 0, 1 );
        $pdf->Ln( 1 );

        list( $ar, $ag, $ab ) = $this->accent['rgb'];
        $pdf->SetFont( 'Helvetica', 'B', 9 );
        $pdf->SetFillColor( $ar, $ag, $ab );
        $pdf->SetTextColor( 255, 255, 255 );
        $pdf->Cell( 90, 7, $this->latin1( 'Event' ),         0, 0, 'L', true );
        $pdf->Cell( 25, 7, $this->latin1( 'Reservations' ),  0, 0, 'R', true );
        $pdf->Cell( 25, 7, $this->latin1( 'Holding' ),       0, 0, 'R', true );
        $pdf->Cell( 0,  7, $this->latin1( 'Checked in' ),    0, 1, 'R', true );

        $pdf->SetFont( 'Helvetica', '', 9 );
        $pdf->SetTextColor( 17, 24, 39 );
        $alt = false;
        foreach ( $by_event as $e ) {
            $pdf->SetFillColor( $alt ? 250 : 255, $alt ? 251 : 255, $alt ? 252 : 255 );
            $pdf->Cell( 90, 6, $this->latin1( $this->safe_truncate( $e['event_title'], 50 ) ), 0, 0, 'L', $alt );
            $pdf->Cell( 25, 6, $this->latin1( number_format( (int) $e['rows'] ) ),             0, 0, 'R', $alt );
            $pdf->Cell( 25, 6, $this->latin1( number_format( (int) $e['holding_seats'] ) ),    0, 0, 'R', $alt );
            $pdf->Cell( 0,  6, $this->latin1( number_format( (int) $e['checked_in'] ) ),       0, 1, 'R', $alt );
            $alt = ! $alt;
        }
    }

    private function draw_reservation_list( $pdf, $rows ) {
        $pdf->AddPage();
        $pdf->SetTextColor( 17, 24, 39 );
        $pdf->SetFont( 'Helvetica', 'B', 14 );
        $pdf->Cell( 0, 8, $this->latin1( 'Reservation list' ), 0, 1 );
        $pdf->SetFont( 'Helvetica', '', 9 );
        $pdf->SetTextColor( 107, 114, 128 );
        $pdf->Cell( 0, 5, $this->latin1( sprintf( '%d reservations', count( $rows ) ) ), 0, 1 );
        $pdf->Ln( 2 );

        if ( empty( $rows ) ) {
            $pdf->SetFont( 'Helvetica', 'I', 10 );
            $pdf->Cell( 0, 6, $this->latin1( 'No reservations match the current filters.' ), 0, 1 );
            return;
        }

        list( $ar, $ag, $ab ) = $this->accent['rgb'];
        $is_event_scoped = (int) $this->args['event_id'] > 0;

        // Column layout — drop the Event column when the report is event-scoped.
        $draw_header = function () use ( $pdf, $ar, $ag, $ab, $is_event_scoped ) {
            $pdf->SetFont( 'Helvetica', 'B', 8 );
            $pdf->SetFillColor( $ar, $ag, $ab );
            $pdf->SetTextColor( 255, 255, 255 );
            $pdf->Cell( 22, 6, $this->latin1( 'Code' ),    0, 0, 'L', true );
            $pdf->Cell( 38, 6, $this->latin1( 'Customer' ),0, 0, 'L', true );
            $pdf->Cell( 36, 6, $this->latin1( 'Contact' ), 0, 0, 'L', true );
            $pdf->Cell( 12, 6, $this->latin1( 'Pty' ),     0, 0, 'R', true );
            $pdf->Cell( 28, 6, $this->latin1( 'Arrival' ), 0, 0, 'L', true );
            if ( ! $is_event_scoped ) {
                $pdf->Cell( 28, 6, $this->latin1( 'Event' ), 0, 0, 'L', true );
            }
            $pdf->Cell( 0,  6, $this->latin1( 'Status' ),  0, 1, 'L', true );
        };
        $draw_header();

        $pdf->SetFont( 'Helvetica', '', 8 );
        $pdf->SetTextColor( 17, 24, 39 );
        $alt = false;
        foreach ( $rows as $r ) {
            try {
                if ( $pdf->GetY() > $pdf->GetPageHeight() - 25 ) {
                    $pdf->AddPage();
                    $draw_header();
                    $pdf->SetFont( 'Helvetica', '', 8 );
                    $pdf->SetTextColor( 17, 24, 39 );
                }
                $pdf->SetFillColor( $alt ? 250 : 255, $alt ? 251 : 255, $alt ? 252 : 255 );
                $pdf->Cell( 22, 5, $this->latin1( $this->safe_truncate( $r->reservation_code, 14 ) ), 0, 0, 'L', $alt );
                $pdf->Cell( 38, 5, $this->latin1( $this->safe_truncate( $r->customer_name ?: '—', 22 ) ), 0, 0, 'L', $alt );
                // Prefer email; fall back to phone if email empty.
                $contact = $r->customer_email ?: ( $r->customer_phone ?: '—' );
                $pdf->Cell( 36, 5, $this->latin1( $this->safe_truncate( $contact, 22 ) ), 0, 0, 'L', $alt );
                $pdf->Cell( 12, 5, $this->latin1( (string) (int) $r->party_size ), 0, 0, 'R', $alt );
                $pdf->Cell( 28, 5, $this->latin1( $this->safe_dt( $r->arrival_time ) ), 0, 0, 'L', $alt );
                if ( ! $is_event_scoped ) {
                    $pdf->Cell( 28, 5, $this->latin1( $this->safe_truncate( $r->event_title ?: '—', 18 ) ), 0, 0, 'L', $alt );
                }
                $pdf->Cell( 0, 5, $this->latin1( $this->status_label( $r->status ) ), 0, 1, 'L', $alt );
                $alt = ! $alt;
            } catch ( \Throwable $row_e ) {
                error_log( '[KiwiEvents] reservations PDF row skipped: ' . $row_e->getMessage() );
            }
        }
    }

    public function _footer_text() {
        return $this->latin1( sprintf(
            'Powered by Kiwi Events · %s',
            get_bloginfo( 'name' )
        ) );
    }

    /* ──────────────────────────────────────────────────────────────
     *  HTML fallback — print-friendly browser page.
     * ────────────────────────────────────────────────────────────── */

    private function stream_html() {
        $bundle = $this->load_data();
        $rows   = $bundle['rows'];
        $stats  = $bundle['stats'];
        $by_event = $bundle['by_event'];
        $accent_hex = $this->accent['hex'];
        $title  = $this->report_title();
        $is_event_scoped = (int) $this->args['event_id'] > 0;

        $this->reset_output_buffers();
        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        ?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
<meta charset="utf-8" />
<title><?php echo esc_html( $title ); ?></title>
<style>
    :root { --accent: <?php echo esc_html( $accent_hex ); ?>; --text:#111827; --muted:#6b7280; --soft:#e5e7eb; }
    * { box-sizing: border-box; }
    html, body { margin:0; padding:0; background:#f5f6fa; color:var(--text); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
    .wrap { max-width:980px; margin:0 auto; padding:28px; }
    .print-bar { background:#fff; border:1px solid var(--soft); border-radius:12px; padding:14px 18px; display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
    .print-bar p { margin:0; font-size:13px; color:var(--muted); }
    .print-bar button { padding:8px 18px; border:none; border-radius:999px; background:var(--accent); color:#fff; font-weight:600; font-size:13px; cursor:pointer; }
    .cover { background:var(--accent); color:#fff; border-radius:16px; padding:32px; margin-bottom:24px; }
    .cover h1 { margin:0 0 4px; font-size:26px; font-weight:800; }
    .cover .eyebrow { text-transform:uppercase; letter-spacing:0.12em; font-size:11px; opacity:0.85; }
    .meta { background:#fff; border:1px solid var(--soft); border-radius:12px; padding:18px 22px; margin-bottom:24px; display:grid; grid-template-columns:repeat(2,1fr); gap:12px; }
    .meta div .k { font-size:11px; text-transform:uppercase; letter-spacing:0.08em; color:var(--muted); }
    .meta div .v { font-size:14px; font-weight:700; color:var(--text); }
    section { background:#fff; border:1px solid var(--soft); border-radius:12px; padding:22px; margin-bottom:18px; }
    section h2 { margin:0 0 14px; font-size:16px; font-weight:700; }
    .grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:14px; }
    .stat { background:#fafbfc; border:1px solid var(--soft); border-radius:10px; padding:14px 16px; }
    .stat .k { font-size:11px; text-transform:uppercase; letter-spacing:0.08em; color:var(--muted); margin-bottom:4px; }
    .stat .v { font-size:22px; font-weight:800; color:var(--text); }
    .stat .v.accent { color:var(--accent); }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    table th { text-align:left; padding:10px 12px; background:var(--accent); color:#fff; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:0.06em; }
    table td { padding:10px 12px; border-bottom:1px solid var(--soft); color:#1f2937; }
    table tr:nth-child(even) td { background:#fafbfc; }
    table .num { text-align:right; }
    .footer { text-align:center; color:var(--muted); font-size:12px; padding:20px 0; }
    @media print {
        body { background:#fff; }
        .print-bar { display:none; }
        .wrap { padding:0; }
        section, .meta { break-inside:avoid; box-shadow:none; }
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
        <p class="eyebrow"><?php esc_html_e( 'KiwiEvents · Reservations Report', 'kiwi-events' ); ?></p>
        <h1><?php echo esc_html( $title ); ?></h1>
    </div>

    <div class="meta">
        <?php foreach ( $this->scope_lines() as $line ) : ?>
            <div><div class="k"><?php echo esc_html( $line[0] ); ?></div><div class="v"><?php echo esc_html( $line[1] ); ?></div></div>
        <?php endforeach; ?>
    </div>

    <section>
        <h2><?php esc_html_e( 'Status breakdown', 'kiwi-events' ); ?></h2>
        <div class="grid">
            <div class="stat"><div class="k"><?php esc_html_e( 'Total', 'kiwi-events' ); ?></div><div class="v"><?php echo (int) $stats['total_rows']; ?></div></div>
            <div class="stat"><div class="k"><?php esc_html_e( 'Holding seats', 'kiwi-events' ); ?></div><div class="v accent"><?php echo (int) $stats['holding_seats']; ?></div></div>
            <div class="stat"><div class="k"><?php esc_html_e( 'Checked in', 'kiwi-events' ); ?></div><div class="v"><?php echo (int) $stats['checked_in']; ?></div></div>
            <div class="stat"><div class="k"><?php esc_html_e( 'Total seats', 'kiwi-events' ); ?></div><div class="v"><?php echo (int) $stats['total_seats']; ?></div></div>
        </div>
        <table>
            <thead><tr>
                <th><?php esc_html_e( 'Status', 'kiwi-events' ); ?></th>
                <th class="num"><?php esc_html_e( 'Reservations', 'kiwi-events' ); ?></th>
                <th class="num"><?php esc_html_e( 'Seats', 'kiwi-events' ); ?></th>
                <th class="num"><?php esc_html_e( 'Checked in', 'kiwi-events' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( $stats['by_status'] as $key => $r ) : ?>
                    <tr>
                        <td><?php echo esc_html( $this->status_label( $key ) ); ?></td>
                        <td class="num"><?php echo (int) $r['rows']; ?></td>
                        <td class="num"><?php echo (int) $r['seats']; ?></td>
                        <td class="num"><?php echo (int) $r['checked_in']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <?php if ( ! empty( $by_event ) ) : ?>
    <section>
        <h2><?php esc_html_e( 'By event', 'kiwi-events' ); ?></h2>
        <table>
            <thead><tr>
                <th><?php esc_html_e( 'Event', 'kiwi-events' ); ?></th>
                <th class="num"><?php esc_html_e( 'Reservations', 'kiwi-events' ); ?></th>
                <th class="num"><?php esc_html_e( 'Holding seats', 'kiwi-events' ); ?></th>
                <th class="num"><?php esc_html_e( 'Checked in', 'kiwi-events' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( $by_event as $e ) : ?>
                    <tr>
                        <td><?php echo esc_html( $e['event_title'] ); ?></td>
                        <td class="num"><?php echo (int) $e['rows']; ?></td>
                        <td class="num"><?php echo (int) $e['holding_seats']; ?></td>
                        <td class="num"><?php echo (int) $e['checked_in']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <section>
        <h2><?php echo esc_html( sprintf( __( 'Reservations (%d)', 'kiwi-events' ), count( $rows ) ) ); ?></h2>
        <?php if ( empty( $rows ) ) : ?>
            <p style="color:#6b7280;"><?php esc_html_e( 'No reservations match the current filters.', 'kiwi-events' ); ?></p>
        <?php else : ?>
            <table>
                <thead><tr>
                    <th><?php esc_html_e( 'Code',     'kiwi-events' ); ?></th>
                    <th><?php esc_html_e( 'Customer', 'kiwi-events' ); ?></th>
                    <th><?php esc_html_e( 'Contact',  'kiwi-events' ); ?></th>
                    <th class="num"><?php esc_html_e( 'Party', 'kiwi-events' ); ?></th>
                    <th><?php esc_html_e( 'Arrival',  'kiwi-events' ); ?></th>
                    <?php if ( ! $is_event_scoped ) : ?>
                        <th><?php esc_html_e( 'Event', 'kiwi-events' ); ?></th>
                    <?php endif; ?>
                    <th><?php esc_html_e( 'Status', 'kiwi-events' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $rows as $r ) : ?>
                        <tr>
                            <td><code>#<?php echo esc_html( $r->reservation_code ); ?></code></td>
                            <td><?php echo esc_html( $r->customer_name ?: '—' ); ?></td>
                            <td><?php echo esc_html( $r->customer_email ?: ( $r->customer_phone ?: '—' ) ); ?></td>
                            <td class="num"><?php echo (int) $r->party_size; ?></td>
                            <td><?php echo esc_html( $this->safe_dt( $r->arrival_time ) ); ?></td>
                            <?php if ( ! $is_event_scoped ) : ?>
                                <td><?php echo esc_html( $r->event_title ?: '—' ); ?></td>
                            <?php endif; ?>
                            <td><?php echo esc_html( $this->status_label( $r->status ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <p class="footer">
        <?php printf( esc_html__( 'Powered by Kiwi Events · %s', 'kiwi-events' ), esc_html( get_bloginfo( 'name' ) ) ); ?>
    </p>
</div>
</body>
</html>
        <?php
    }
}

/**
 * Tiny FPDF subclass with just the footer. Defined here so it loads in
 * lockstep with the report class and only when FPDF is actually present.
 */
if ( ! class_exists( 'KE_Admin_Reservations_FPDF_Report', false ) && class_exists( 'FPDF' ) ) {
    class KE_Admin_Reservations_FPDF_Report extends FPDF {
        /** @var KE_Admin_Reservations_Report_PDF */
        private $report;
        public function __construct( $report ) {
            parent::__construct( 'P', 'mm', 'A4' );
            $this->report = $report;
        }
        public function Footer() {
            $this->SetY( -14 );
            $this->SetFont( 'Helvetica', '', 8 );
            $this->SetTextColor( 156, 163, 175 );
            $this->Cell( 0, 6, $this->report->_footer_text(), 0, 0, 'L' );
            $this->Cell( 0, 6, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R' );
        }
    }
}
