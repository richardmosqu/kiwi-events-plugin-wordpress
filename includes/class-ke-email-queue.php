<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * KE_Email_Queue
 *
 * Routes promoter-system emails through wp_schedule_single_event so they
 * (a) don't block admin POSTs and (b) get retries on transient failures.
 *
 * Flow:
 *   - Caller passes a *template* key and a *context* array to ::enqueue().
 *   - We INSERT a 'queued' row in ke_email_log and schedule a one-off cron
 *     hook 'ke_email_queue_send' carrying the row id.
 *   - When wp-cron fires, ::handle_send() looks the row back up, asks the
 *     template renderer for { to, subject, body }, calls wp_mail(), and
 *     marks the row sent / failed.
 *   - On failure we reschedule with exponential backoff (1m / 5m / 30m).
 *     After 3 failed attempts we mark the row 'failed' permanently.
 *
 * Stagger: callers can pass a $delay (seconds from now) so a batch of N
 * recipients can be paced. Convention is 2s between recipients to avoid
 * SMTP rate limits.
 */
class KE_Email_Queue {

    const HOOK_SEND     = 'ke_email_queue_send';
    const MAX_ATTEMPTS  = 3;

    /** Exponential backoff for retries, in seconds (1m / 5m / 30m). */
    private static $backoff = array( 60, 300, 1800 );

    public static function init() {
        add_action( self::HOOK_SEND, array( __CLASS__, 'handle_send' ), 10, 1 );
    }

    /**
     * Public table accessor — also used by the admin "Recent emails" view.
     */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'ke_email_log';
    }

    /**
     * Enqueue an email.
     *
     * @param string $template Template key (used by ::render_message()).
     * @param string $to       Recipient email.
     * @param array  $context  Free-form context, JSON-encoded and stored.
     * @param int    $delay    Seconds to wait before first send attempt.
     * @return int             Log row id (0 on failure).
     */
    public static function enqueue( $template, $to, array $context = array(), $delay = 0 ) {
        global $wpdb;

        $to = sanitize_email( $to );
        if ( ! $to ) return 0;

        $delay = max( 0, (int) $delay );
        $scheduled = gmdate( 'Y-m-d H:i:s', time() + $delay );

        $subject = isset( $context['_subject'] ) ? (string) $context['_subject'] : self::peek_subject( $template, $context );

        $wpdb->insert(
            self::table(),
            array(
                'recipient'     => $to,
                'subject'       => mb_substr( (string) $subject, 0, 255 ),
                'template'      => sanitize_key( $template ),
                'context_json'  => wp_json_encode( $context ),
                'status'        => 'queued',
                'attempts'      => 0,
                'scheduled_for' => $scheduled,
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );
        $id = (int) $wpdb->insert_id;
        if ( ! $id ) return 0;

        wp_schedule_single_event( time() + $delay, self::HOOK_SEND, array( $id ) );
        return $id;
    }

    /**
     * Cron callback. Look up the row, render, send, update status.
     */
    public static function handle_send( $log_id ) {
        global $wpdb;
        $log_id = (int) $log_id;
        if ( ! $log_id ) return;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d", $log_id
        ) );
        if ( ! $row ) return;

        // Don't double-send if another cron tick already handled it.
        if ( in_array( $row->status, array( 'sent', 'failed' ), true ) ) {
            return;
        }

        $context = array();
        if ( ! empty( $row->context_json ) ) {
            $decoded = json_decode( $row->context_json, true );
            if ( is_array( $decoded ) ) $context = $decoded;
        }

        $msg = self::render_message( $row->template, $context );
        if ( ! $msg || empty( $msg['body'] ) ) {
            self::mark_failed( $log_id, 'Renderer returned empty body' );
            return;
        }

        $headers = isset( $msg['headers'] ) ? $msg['headers'] : array( 'Content-Type: text/html; charset=UTF-8' );

        $ok = (bool) wp_mail( $row->recipient, $msg['subject'], $msg['body'], $headers );

        $attempts = (int) $row->attempts + 1;

        if ( $ok ) {
            $wpdb->update(
                self::table(),
                array(
                    'status'   => 'sent',
                    'subject'  => mb_substr( (string) $msg['subject'], 0, 255 ),
                    'attempts' => $attempts,
                    'sent_at'  => current_time( 'mysql' ),
                    'error_message' => null,
                ),
                array( 'id' => $log_id ),
                array( '%s', '%s', '%d', '%s', '%s' ),
                array( '%d' )
            );
            return;
        }

        // Failed. Retry with backoff, or give up after MAX_ATTEMPTS.
        if ( $attempts < self::MAX_ATTEMPTS ) {
            $delay = self::$backoff[ $attempts - 1 ] ?? end( self::$backoff );
            $wpdb->update(
                self::table(),
                array(
                    'status'        => 'retrying',
                    'attempts'      => $attempts,
                    'scheduled_for' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
                    'error_message' => 'wp_mail returned false',
                ),
                array( 'id' => $log_id ),
                array( '%s', '%d', '%s', '%s' ),
                array( '%d' )
            );
            wp_schedule_single_event( time() + $delay, self::HOOK_SEND, array( $log_id ) );
        } else {
            self::mark_failed( $log_id, 'wp_mail returned false (gave up after ' . self::MAX_ATTEMPTS . ' attempts)' );
        }
    }

    private static function mark_failed( $log_id, $message ) {
        global $wpdb;
        $wpdb->update(
            self::table(),
            array(
                'status'        => 'failed',
                'error_message' => mb_substr( (string) $message, 0, 1000 ),
            ),
            array( 'id' => (int) $log_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Render a {subject, body, headers?} triple from (template, context).
     *
     * Templates live in KE_Email_Templates to keep this dispatcher slim.
     * If the template is unknown the row is marked failed by the caller.
     */
    public static function render_message( $template, array $context ) {
        if ( class_exists( 'KE_Email_Templates' ) ) {
            return KE_Email_Templates::render( $template, $context );
        }
        return null;
    }

    /**
     * Best-effort subject preview for the log row at enqueue time, so an
     * admin scanning "Recent emails" sees something useful even while a
     * row is still queued.
     */
    private static function peek_subject( $template, array $context ) {
        if ( class_exists( 'KE_Email_Templates' ) ) {
            $m = KE_Email_Templates::render( $template, $context );
            if ( is_array( $m ) && ! empty( $m['subject'] ) ) {
                return (string) $m['subject'];
            }
        }
        return '(pending) ' . $template;
    }
}

KE_Email_Queue::init();
