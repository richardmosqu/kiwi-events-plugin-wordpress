<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Login gate for [kiwi_tickets_purchase] — shown to logged-out visitors.
 * Receives $gate = array( login_url, register_url, message ).
 */
?>
<div class="ke-tickets-wallet">
    <div class="ke-tw-gate">
        <div class="ke-tw-gate__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2M13 17v2M13 11v2"/></svg>
        </div>
        <h2 class="ke-tw-gate__title">Mis Boletos</h2>
        <p class="ke-tw-gate__message"><?php echo esc_html( $gate['message'] ); ?></p>
        <a class="ke-tw-btn ke-tw-btn--primary ke-tw-gate__cta" href="<?php echo esc_url( $gate['login_url'] ); ?>">Iniciar sesión</a>
        <?php if ( ! empty( $gate['register_url'] ) ) : ?>
            <p class="ke-tw-gate__register">¿No tienes cuenta? <a href="<?php echo esc_url( $gate['register_url'] ); ?>">Regístrate</a></p>
        <?php endif; ?>
    </div>
</div>
