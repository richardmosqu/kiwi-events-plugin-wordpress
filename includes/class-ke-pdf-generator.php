<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PDF ticket generator using TCPDF
 */
class KE_PDF_Generator {

    private $upload_dir;

    public function __construct() {
        $upload = wp_upload_dir();
        $this->upload_dir = $upload['basedir'] . '/kiwi-events/tickets/';

        if ( ! is_dir( $this->upload_dir ) ) {
            wp_mkdir_p( $this->upload_dir );
        }
    }

    /**
     * Generate a PDF ticket
     *
     * @param object $ticket  Ticket object from DB
     * @return string|WP_Error File path on success
     */
    public function generate( $ticket ) {
        if ( ! class_exists( 'TCPDF' ) ) {
            return new WP_Error( 'tcpdf_missing', 'TCPDF library not available.' );
        }

        try {
            // Event data
            $event_title   = $ticket->event_name ?? get_the_title( $ticket->event_id );
            $event_date    = get_post_meta( $ticket->event_id, '_ke_event_date_start', true );
            $event_venue   = get_post_meta( $ticket->event_id, '_ke_event_venue', true );
            $event_address = get_post_meta( $ticket->event_id, '_ke_event_address', true );

            // Format date nicely
            $formatted_date = '';
            if ( $event_date ) {
                $formatted_date = date( 'l, F j, Y \a\t g:i A', strtotime( $event_date ) );
            }

            // Create PDF
            $pdf = new TCPDF( 'L', 'mm', array( 210, 100 ), true, 'UTF-8', false );

            // Remove default header/footer
            $pdf->setPrintHeader( false );
            $pdf->setPrintFooter( false );

            // Set margins
            $pdf->SetMargins( 0, 0, 0 );
            $pdf->SetAutoPageBreak( false );

            // Add page
            $pdf->AddPage();

            // Background gradient
            $pdf->SetFillColor( 24, 24, 38 );
            $pdf->Rect( 0, 0, 210, 100, 'F' );

            // Accent stripe at top
            $pdf->SetFillColor( 107, 203, 119 ); // Kiwi green
            $pdf->Rect( 0, 0, 210, 4, 'F' );

            // KiwiEvents branding
            $pdf->SetFont( 'helvetica', 'B', 10 );
            $pdf->SetTextColor( 107, 203, 119 );
            $pdf->SetXY( 10, 8 );
            $pdf->Cell( 80, 6, '🥝 KIWIEVENTS', 0, 0, 'L' );

            // Ticket type badge
            $ticket_type_name = $ticket->ticket_type_name ?? 'General';
            $pdf->SetFont( 'helvetica', 'B', 8 );
            $pdf->SetFillColor( 107, 203, 119 );
            $pdf->SetTextColor( 24, 24, 38 );
            $pdf->SetXY( 10, 16 );
            $pdf->Cell( 40, 6, strtoupper( $ticket_type_name ), 0, 0, 'C', true );

            // Event Title
            $pdf->SetFont( 'helvetica', 'B', 18 );
            $pdf->SetTextColor( 255, 255, 255 );
            $pdf->SetXY( 10, 26 );
            $pdf->Cell( 130, 10, $event_title, 0, 1, 'L' );

            // Date
            $pdf->SetFont( 'helvetica', '', 9 );
            $pdf->SetTextColor( 180, 180, 200 );
            $pdf->SetXY( 10, 38 );
            $pdf->Cell( 130, 5, '📅  ' . $formatted_date, 0, 1, 'L' );

            // Venue
            if ( $event_venue ) {
                $pdf->SetXY( 10, 44 );
                $pdf->Cell( 130, 5, '📍  ' . $event_venue . ( $event_address ? ' — ' . $event_address : '' ), 0, 1, 'L' );
            }

            // Separator line
            $pdf->SetDrawColor( 60, 60, 80 );
            $pdf->Line( 10, 54, 140, 54 );

            // Attendee info
            $pdf->SetFont( 'helvetica', '', 8 );
            $pdf->SetTextColor( 140, 140, 160 );
            $pdf->SetXY( 10, 58 );
            $pdf->Cell( 30, 4, 'ATTENDEE', 0, 0, 'L' );
            $pdf->SetXY( 80, 58 );
            $pdf->Cell( 30, 4, 'TICKET #', 0, 0, 'L' );

            $pdf->SetFont( 'helvetica', 'B', 10 );
            $pdf->SetTextColor( 255, 255, 255 );
            $pdf->SetXY( 10, 63 );
            $pdf->Cell( 60, 5, $ticket->attendee_name, 0, 0, 'L' );
            $pdf->SetXY( 80, 63 );
            $pdf->Cell( 40, 5, '#' . str_pad( $ticket->attendee_number, 4, '0', STR_PAD_LEFT ), 0, 0, 'L' );

            // Ticket code (small)
            $pdf->SetFont( 'courier', '', 6 );
            $pdf->SetTextColor( 100, 100, 120 );
            $pdf->SetXY( 10, 72 );
            $pdf->Cell( 130, 4, substr( $ticket->ticket_code, 0, 32 ), 0, 0, 'L' );

            // Price
            $price = isset( $ticket->ticket_price ) ? $ticket->ticket_price : 0;
            $pdf->SetFont( 'helvetica', 'B', 12 );
            $pdf->SetTextColor( 107, 203, 119 );
            $pdf->SetXY( 10, 80 );
            $pdf->Cell( 40, 8, $price > 0 ? '$' . number_format( $price, 2 ) : 'FREE', 0, 0, 'L' );

            // Footer text
            $pdf->SetFont( 'helvetica', '', 6 );
            $pdf->SetTextColor( 80, 80, 100 );
            $pdf->SetXY( 10, 92 );
            $pdf->Cell( 130, 4, 'Present this ticket at the venue. This ticket is non-transferable.', 0, 0, 'L' );

            // QR Code section — right side with vertical dashed line separator
            $pdf->SetDrawColor( 60, 60, 80 );
            $pdf->SetLineDash( 2, 2 );
            $pdf->Line( 148, 8, 148, 92 );
            $pdf->SetLineDash(); // Reset

            // QR Code
            if ( ! empty( $ticket->qr_code_path ) && file_exists( $ticket->qr_code_path ) ) {
                $pdf->Image( $ticket->qr_code_path, 155, 15, 45, 45, 'PNG' );
            }

            // Scan instruction
            $pdf->SetFont( 'helvetica', '', 7 );
            $pdf->SetTextColor( 140, 140, 160 );
            $pdf->SetXY( 150, 64 );
            $pdf->Cell( 55, 5, 'Scan QR code at entry', 0, 0, 'C' );

            // Attendee number under QR
            $pdf->SetFont( 'helvetica', 'B', 16 );
            $pdf->SetTextColor( 255, 255, 255 );
            $pdf->SetXY( 150, 72 );
            $pdf->Cell( 55, 10, '#' . str_pad( $ticket->attendee_number, 4, '0', STR_PAD_LEFT ), 0, 0, 'C' );

            // Save PDF
            $filename = 'ticket-' . $ticket->ticket_code . '.pdf';
            $filepath = $this->upload_dir . $filename;
            $pdf->Output( $filepath, 'F' );

            if ( ! file_exists( $filepath ) ) {
                return new WP_Error( 'pdf_save_failed', 'Failed to save PDF ticket.' );
            }

            // Update ticket with PDF path
            $tickets_handler = new KE_Tickets();
            $tickets_handler->update_pdf_path( $ticket->id, $filepath );

            return $filepath;

        } catch ( \Throwable $e ) {
            return new WP_Error( 'pdf_generation_failed', 'PDF generation failed: ' . $e->getMessage() );
        }
    }

    /**
     * Get the URL for a ticket PDF
     */
    public function get_url( $ticket_code ) {
        $upload = wp_upload_dir();
        return $upload['baseurl'] . '/kiwi-events/tickets/ticket-' . $ticket_code . '.pdf';
    }
}
