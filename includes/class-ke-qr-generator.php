<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * QR Code generator
 *
 * Uses api.qrserver.com — more reliable than Google Charts,
 * no local library required, no CSP issues in modern browsers.
 * The QR encodes ONLY the raw ticket_code (sha256 hex string).
 * The scanner reads this and passes it straight to the REST
 * validate endpoint: POST /ke/v1/tickets/validate/{code}
 */
class KE_QR_Generator {

    /**
     * "Generate" a QR for a ticket code — returns the image URL.
     * No local file is created.
     *
     * @param string $ticket_code  sha256 hex ticket code
     * @return string  QR Server image URL
     */
    public function generate( $ticket_code ) {
        return $this->get_url( $ticket_code );
    }

    /**
     * Return the QR image URL for a ticket code.
     *
     * @param string $ticket_code
     * @return string
     */
    public function get_url( $ticket_code ) {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&format=png&ecc=H&data='
               . urlencode( $ticket_code );
    }

    /**
     * No-op: nothing to delete when using the API.
     */
    public function delete( $ticket_code ) {}
}
