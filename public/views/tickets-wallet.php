<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Customer ticket wallet — [kiwi_tickets_purchase]
 * Receives $wallet = array( total, tabs => { upcoming, past, cancelled } ),
 * each tab: array( label, events (grouped, ordered), ticket_count ).
 */

$ke_tw_pdf_base = rest_url( 'ke/v1/my-tickets/' );
$ke_tw_nonce    = wp_create_nonce( 'wp_rest' );

$ke_tw_tab_meta = array(
    'upcoming'  => array(
        'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
        'badge' => 'Próximo',
        'empty' => 'No tienes eventos próximos',
    ),
    'past'      => array(
        'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
        'badge' => 'Finalizado',
        'empty' => 'No tienes eventos pasados',
    ),
    'cancelled' => array(
        'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        'badge' => 'Cancelado',
        'empty' => 'No tienes boletos cancelados',
    ),
);
?>
<div class="ke-tickets-wallet" data-ke-tw>

    <header class="ke-tw-header">
        <div class="ke-tw-header__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2M13 17v2M13 11v2"/></svg>
        </div>
        <div class="ke-tw-header__text">
            <h2 class="ke-tw-header__title">Mis Boletos</h2>
            <span class="ke-tw-header__count"><?php echo esc_html( number_format_i18n( $wallet['total'] ) ); ?> <?php echo 1 === (int) $wallet['total'] ? 'boleto' : 'boletos'; ?></span>
        </div>
    </header>

    <div class="ke-tw-tiles" role="tablist" aria-label="Categorías de boletos">
        <?php foreach ( $wallet['tabs'] as $ke_tw_key => $ke_tw_tab ) : ?>
            <button type="button"
                    class="ke-tw-tile<?php echo 'upcoming' === $ke_tw_key ? ' is-active' : ''; ?>"
                    id="ke-tw-tile-<?php echo esc_attr( $ke_tw_key ); ?>"
                    role="tab"
                    data-ke-tw-tab="<?php echo esc_attr( $ke_tw_key ); ?>"
                    aria-selected="<?php echo 'upcoming' === $ke_tw_key ? 'true' : 'false'; ?>"
                    aria-controls="ke-tw-panel-<?php echo esc_attr( $ke_tw_key ); ?>">
                <span class="ke-tw-tile__icon ke-tw-tile__icon--<?php echo esc_attr( $ke_tw_key ); ?>" aria-hidden="true"><?php echo $ke_tw_tab_meta[ $ke_tw_key ]['icon']; // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG ?></span>
                <span class="ke-tw-tile__count"><?php echo esc_html( number_format_i18n( $ke_tw_tab['ticket_count'] ) ); ?></span>
                <span class="ke-tw-tile__label"><?php echo esc_html( $ke_tw_tab['label'] ); ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <?php foreach ( $wallet['tabs'] as $ke_tw_key => $ke_tw_tab ) : ?>
        <section class="ke-tw-panel<?php echo 'upcoming' === $ke_tw_key ? ' is-active' : ''; ?>"
                 id="ke-tw-panel-<?php echo esc_attr( $ke_tw_key ); ?>"
                 role="tabpanel"
                 aria-labelledby="ke-tw-tile-<?php echo esc_attr( $ke_tw_key ); ?>"
                 data-ke-tw-panel="<?php echo esc_attr( $ke_tw_key ); ?>"
                 <?php echo 'upcoming' === $ke_tw_key ? '' : 'hidden'; ?>>

            <div class="ke-tw-panel__heading">
                <h3 class="ke-tw-panel__title"><?php echo esc_html( $ke_tw_tab['label'] ); ?></h3>
                <span class="ke-tw-panel__pill"><?php echo esc_html( number_format_i18n( count( $ke_tw_tab['events'] ) ) ); ?> <?php echo 1 === count( $ke_tw_tab['events'] ) ? 'evento' : 'eventos'; ?></span>
            </div>

            <?php if ( empty( $ke_tw_tab['events'] ) ) : ?>

                <div class="ke-tw-empty">
                    <span class="ke-tw-empty__icon" aria-hidden="true"><?php echo $ke_tw_tab_meta[ $ke_tw_key ]['icon']; // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG ?></span>
                    <p><?php echo esc_html( $ke_tw_tab_meta[ $ke_tw_key ]['empty'] ); ?></p>
                </div>

            <?php else : ?>

                <?php $ke_tw_first = true; ?>
                <?php foreach ( $ke_tw_tab['events'] as $ke_tw_event ) : ?>
                    <?php $ke_tw_n = count( $ke_tw_event['tickets'] ); ?>
                    <article class="ke-tw-card<?php echo $ke_tw_first ? ' is-open' : ''; ?>">
                        <button type="button" class="ke-tw-card__head" data-ke-tw-toggle aria-expanded="<?php echo $ke_tw_first ? 'true' : 'false'; ?>">
                            <span class="ke-tw-card__thumb" aria-hidden="true">
                                <?php if ( $ke_tw_event['thumb'] ) : ?>
                                    <img src="<?php echo esc_url( $ke_tw_event['thumb'] ); ?>" alt="" loading="lazy">
                                <?php else : ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/></svg>
                                <?php endif; ?>
                            </span>
                            <span class="ke-tw-card__info">
                                <span class="ke-tw-card__badge"><?php echo esc_html( $ke_tw_n ); ?> <?php echo 1 === $ke_tw_n ? 'Boleto' : 'Boletos'; ?></span>
                                <span class="ke-tw-card__title"><?php echo esc_html( $ke_tw_event['title'] ); ?></span>
                                <span class="ke-tw-card__meta">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg>
                                    <?php echo esc_html( $ke_tw_event['date_display'] ); ?>
                                </span>
                                <?php if ( $ke_tw_event['location'] ) : ?>
                                    <span class="ke-tw-card__meta ke-tw-card__meta--loc">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-5.1-7-11a7 7 0 0 1 14 0c0 5.9-7 11-7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                                        <span class="ke-tw-card__loc-text"><?php echo esc_html( $ke_tw_event['location'] ); ?></span>
                                    </span>
                                <?php endif; ?>
                            </span>
                            <span class="ke-tw-card__side">
                                <span class="ke-tw-status ke-tw-status--<?php echo esc_attr( $ke_tw_key ); ?>"><?php echo esc_html( $ke_tw_tab_meta[ $ke_tw_key ]['badge'] ); ?></span>
                                <svg class="ke-tw-card__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                            </span>
                        </button>

                        <div class="ke-tw-card__body">
                            <?php foreach ( $ke_tw_event['tickets'] as $ke_tw_ticket ) : ?>
                                <div class="ke-tw-row">
                                    <span class="ke-tw-row__icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2M13 17v2M13 11v2"/></svg>
                                    </span>
                                    <span class="ke-tw-row__name">
                                        <?php echo esc_html( $ke_tw_ticket['type_name'] ); ?>
                                        <span class="ke-tw-row__code">· #<?php echo esc_html( $ke_tw_ticket['code_short'] ); ?></span>
                                    </span>
                                    <span class="ke-tw-row__actions">
                                        <button type="button"
                                                class="ke-tw-btn ke-tw-btn--qr"
                                                data-ke-tw-qr="<?php echo esc_url( $ke_tw_ticket['qr_url'] ); ?>"
                                                data-ke-tw-type="<?php echo esc_attr( $ke_tw_ticket['type_name'] ); ?>"
                                                data-ke-tw-code="<?php echo esc_attr( $ke_tw_ticket['code_short'] ); ?>"
                                                data-ke-tw-event="<?php echo esc_attr( $ke_tw_event['title'] ); ?>"
                                                data-ke-tw-date="<?php echo esc_attr( $ke_tw_event['date_display'] ); ?>">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM21 14v.01M14 21v.01M18 18h3v3h-3z"/></svg>
                                            Ver QR
                                        </button>
                                        <a class="ke-tw-btn ke-tw-btn--pdf"
                                           href="<?php echo esc_url( add_query_arg( '_wpnonce', $ke_tw_nonce, $ke_tw_pdf_base . $ke_tw_ticket['id'] . '/pdf' ) ); ?>">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                                            Descargar PDF
                                        </a>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </article>
                    <?php $ke_tw_first = false; ?>
                <?php endforeach; ?>

            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <div class="ke-tw-modal" data-ke-tw-modal hidden>
        <div class="ke-tw-modal__overlay" data-ke-tw-modal-close></div>
        <div class="ke-tw-modal__panel" role="dialog" aria-modal="true" aria-label="Código QR del boleto">
            <p class="ke-tw-modal__event" data-ke-tw-modal-event></p>
            <p class="ke-tw-modal__date" data-ke-tw-modal-date></p>
            <div class="ke-tw-modal__qr">
                <img src="" alt="Código QR del boleto" data-ke-tw-modal-img>
            </div>
            <p class="ke-tw-modal__type" data-ke-tw-modal-type></p>
            <p class="ke-tw-modal__code" data-ke-tw-modal-code></p>
            <button type="button" class="ke-tw-btn ke-tw-btn--close" data-ke-tw-modal-close>Cerrar</button>
        </div>
    </div>

</div>
