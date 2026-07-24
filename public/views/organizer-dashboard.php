<?php
/**
 * Organizer self-service dashboard template.
 *
 * Available locals (set by KE_Organizer_Dashboard::handle_dashboard_request):
 *   $organizer            : WP_Term  (ke_organizer term)
 *   $organizer_logo_id    : int      (attachment id, may be 0)
 *   $organizer_logo_url   : string   (medium image URL, may be empty)
 *   $authenticated_org_id : int
 *   $is_authenticated     : bool
 *   $is_admin_user        : bool
 *
 * Renders a full HTML document scoped under .ke-org-dash so dashboard styles
 * cannot leak into the rest of the site.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$page_title  = sprintf( '%s — %s', esc_html( $organizer->name ), esc_html( get_bloginfo( 'name' ) ) );
$admin_email = get_option( 'admin_email' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <title><?php echo $page_title; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" />
    <?php wp_head(); ?>
</head>
<body class="ke-org-dash-body">
    <div class="ke-org-dash" data-slug="<?php echo esc_attr( $organizer->slug ); ?>">

        <?php if ( ! $is_authenticated ) : ?>

            <div class="ke-org-login">
                <div class="ke-org-login-card" role="region" aria-labelledby="ke-org-login-title">
                    <?php if ( $organizer_logo_url ) : ?>
                        <img class="ke-org-login-logo"
                             src="<?php echo esc_url( $organizer_logo_url ); ?>"
                             alt="<?php echo esc_attr( $organizer->name ); ?>" />
                    <?php else : ?>
                        <div class="ke-org-login-logo ke-org-login-logo--text" aria-hidden="true">
                            <?php echo esc_html( mb_substr( $organizer->name, 0, 1 ) ); ?>
                        </div>
                    <?php endif; ?>

                    <h1 id="ke-org-login-title" class="ke-org-login-title">
                        <?php echo esc_html( $organizer->name ); ?>
                    </h1>
                    <p class="ke-org-login-subtitle">
                        <?php esc_html_e( 'Organizer Dashboard', 'kiwi-events' ); ?>
                    </p>

                    <form class="ke-org-login-form" id="keOrgLoginForm" autocomplete="off">
                        <label class="ke-org-login-label" for="keOrgPassword">
                            <?php esc_html_e( 'Password', 'kiwi-events' ); ?>
                        </label>
                        <div class="ke-org-login-input-wrap">
                            <input type="password"
                                   id="keOrgPassword"
                                   name="password"
                                   class="ke-org-login-input"
                                   autocomplete="current-password"
                                   required />
                            <button type="button"
                                    class="ke-org-login-toggle"
                                    id="keOrgPasswordToggle"
                                    aria-label="<?php esc_attr_e( 'Show password', 'kiwi-events' ); ?>">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>

                        <div class="ke-org-login-error" id="keOrgLoginError" role="alert" hidden></div>

                        <button type="submit" class="ke-org-login-submit" id="keOrgLoginSubmit">
                            <span class="ke-org-login-submit-label"><?php esc_html_e( 'Sign In', 'kiwi-events' ); ?></span>
                            <span class="ke-org-login-spinner" aria-hidden="true"></span>
                        </button>

                        <?php if ( $is_admin_user ) : ?>
                            <button type="button" class="ke-org-login-admin" id="keOrgLoginAdmin">
                                <?php esc_html_e( 'Skip with admin access', 'kiwi-events' ); ?>
                            </button>
                        <?php endif; ?>
                    </form>

                    <p class="ke-org-login-help">
                        <?php
                        printf(
                            /* translators: %s: site admin email address */
                            esc_html__( 'Forgot your password? Contact %s', 'kiwi-events' ),
                            '<a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a>'
                        );
                        ?>
                    </p>
                </div>
            </div>

        <?php else : ?>

            <header class="ke-org-header">
                <div class="ke-org-header-brand">
                    <?php if ( $organizer_logo_url ) : ?>
                        <img class="ke-org-header-logo"
                             src="<?php echo esc_url( $organizer_logo_url ); ?>"
                             alt="<?php echo esc_attr( $organizer->name ); ?>" />
                    <?php else : ?>
                        <div class="ke-org-header-logo ke-org-header-logo--text" aria-hidden="true">
                            <?php echo esc_html( mb_substr( $organizer->name, 0, 1 ) ); ?>
                        </div>
                    <?php endif; ?>
                    <div class="ke-org-header-titles">
                        <h1 class="ke-org-header-name"><?php echo esc_html( $organizer->name ); ?></h1>
                        <p class="ke-org-header-sub"><?php esc_html_e( 'Organizer Dashboard', 'kiwi-events' ); ?></p>
                    </div>
                </div>
                <div class="ke-org-header-controls">
                    <select class="ke-org-range" id="keOrgRange" aria-label="<?php esc_attr_e( 'Date range', 'kiwi-events' ); ?>">
                        <option value="7"><?php esc_html_e( 'Last 7 days', 'kiwi-events' ); ?></option>
                        <option value="30" selected><?php esc_html_e( 'Last 30 days', 'kiwi-events' ); ?></option>
                        <option value="90"><?php esc_html_e( 'Last 90 days', 'kiwi-events' ); ?></option>
                        <option value="365"><?php esc_html_e( 'Last year', 'kiwi-events' ); ?></option>
                        <option value="all"><?php esc_html_e( 'All time', 'kiwi-events' ); ?></option>
                    </select>
                    <button type="button" class="ke-org-logout" id="keOrgLogout">
                        <?php esc_html_e( 'Sign out', 'kiwi-events' ); ?>
                    </button>
                </div>
            </header>

            <main class="ke-org-main">
                <div class="ke-org-status-bar" id="keOrgStatusBar">
                    <div class="ke-org-status-banner" id="keOrgStatusBanner" role="alert" hidden></div>
                    <p class="ke-org-last-updated" id="keOrgLastUpdated" aria-live="polite">
                        <span class="ke-org-last-updated-dot" aria-hidden="true"></span>
                        <span class="ke-org-last-updated-text"><?php esc_html_e( 'Loading…', 'kiwi-events' ); ?></span>
                    </p>
                </div>
                <div class="ke-org-top-actions">
                    <button type="button" class="ke-org-export--csv-top" id="keOrgExportCsvTop">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        <?php esc_html_e( 'Download Database CSV', 'kiwi-events' ); ?>
                    </button>
                    <button type="button" class="ke-org-export--pdf-top" id="keOrgExportPdfTop">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        <?php esc_html_e( 'Generate Full Report PDF', 'kiwi-events' ); ?>
                    </button>
                </div>

                <section class="ke-org-section">
                    <p class="ke-org-section-eyebrow"><?php esc_html_e( 'Overview', 'kiwi-events' ); ?></p>
                    <div class="ke-org-stats" id="keOrgStats" aria-busy="true">
                        <div class="ke-org-stats-loading"><?php esc_html_e( 'Loading dashboard…', 'kiwi-events' ); ?></div>
                    </div>
                </section>

                <section class="ke-org-section">
                    <p class="ke-org-section-eyebrow"><?php esc_html_e( 'Performance', 'kiwi-events' ); ?></p>
                    <div class="ke-org-card">
                        <header class="ke-org-card-header">
                            <div class="ke-org-card-titles">
                                <h2 class="ke-org-card-title" id="keOrgChartTitle"><?php esc_html_e( 'Daily sales', 'kiwi-events' ); ?></h2>
                                <p class="ke-org-card-subtitle" id="keOrgChartSubtitle"><?php esc_html_e( 'Tickets sold and net revenue per day for the selected range.', 'kiwi-events' ); ?></p>
                            </div>
                        </header>
                        <div class="ke-org-chart-wrap">
                            <div class="kep-chart-wrapper">
                                <canvas id="keOrgChart"></canvas>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="ke-org-section">
                    <p class="ke-org-section-eyebrow"><?php esc_html_e( 'Events', 'kiwi-events' ); ?></p>
                    <div class="ke-org-card">
                        <header class="ke-org-card-header">
                            <div class="ke-org-card-titles">
                                <h2 class="ke-org-card-title"><?php esc_html_e( 'Your events', 'kiwi-events' ); ?></h2>
                                <p class="ke-org-card-subtitle"><?php esc_html_e( 'Click an event to filter the attendee list below.', 'kiwi-events' ); ?></p>
                            </div>
                        </header>
                        <div class="ke-org-events" id="keOrgEvents"></div>
                    </div>
                </section>

                <section class="ke-org-section">
                    <p class="ke-org-section-eyebrow"><?php esc_html_e( 'Attendees', 'kiwi-events' ); ?></p>
                    <div class="ke-org-card">
                        <header class="ke-org-card-header ke-org-card-header--toolbar">
                            <div class="ke-org-card-titles">
                                <h2 class="ke-org-card-title"><?php esc_html_e( 'Attendee list', 'kiwi-events' ); ?></h2>
                                <p class="ke-org-card-subtitle"><?php esc_html_e( 'Search, filter by event, or download for analysis.', 'kiwi-events' ); ?></p>
                            </div>
                            <div class="ke-org-toolbar">
                                <input type="search"
                                       class="ke-org-search"
                                       id="keOrgAttendeeSearch"
                                       placeholder="<?php esc_attr_e( 'Search by name or email…', 'kiwi-events' ); ?>" />
                                <button type="button" class="ke-org-export--csv" id="keOrgExportCsv">
                                    <?php esc_html_e( 'Download CSV', 'kiwi-events' ); ?>
                                </button>
                            </div>
                        </header>
                        <div class="ke-org-attendees" id="keOrgAttendees"></div>
                    </div>
                </section>

                <section class="ke-org-section" id="keOrgReservationsSection">
                    <p class="ke-org-section-eyebrow"><?php esc_html_e( 'Reservations', 'kiwi-events' ); ?></p>
                    <div class="ke-org-card">
                        <header class="ke-org-card-header ke-org-card-header--toolbar">
                            <div class="ke-org-card-titles">
                                <h2 class="ke-org-card-title"><?php esc_html_e( 'Bookings', 'kiwi-events' ); ?></h2>
                                <p class="ke-org-card-subtitle"><?php esc_html_e( 'Approve, decline, check in, or cancel reservation requests.', 'kiwi-events' ); ?></p>
                            </div>
                            <div class="ke-org-toolbar ke-org-toolbar--resv">
                                <input type="search"
                                       class="ke-org-search"
                                       id="keOrgResvSearch"
                                       placeholder="<?php esc_attr_e( 'Search name, email, phone, code…', 'kiwi-events' ); ?>" />
                                <select class="ke-org-resv-event-filter" id="keOrgResvEventFilter" aria-label="<?php esc_attr_e( 'Filter by event', 'kiwi-events' ); ?>">
                                    <option value=""><?php esc_html_e( 'All events', 'kiwi-events' ); ?></option>
                                </select>
                                <input type="date" class="ke-org-resv-date" id="keOrgResvFrom" aria-label="<?php esc_attr_e( 'From date', 'kiwi-events' ); ?>" />
                                <input type="date" class="ke-org-resv-date" id="keOrgResvTo" aria-label="<?php esc_attr_e( 'To date', 'kiwi-events' ); ?>" />
                            </div>
                        </header>

                        <div class="ke-org-resv-pills" id="keOrgResvPills" role="tablist" aria-label="<?php esc_attr_e( 'Filter by status', 'kiwi-events' ); ?>">
                            <button type="button" class="ke-org-resv-pill is-active" role="tab" data-status="" aria-selected="true">
                                <?php esc_html_e( 'All', 'kiwi-events' ); ?>
                                <span class="ke-org-resv-pill-count" data-count="all">0</span>
                            </button>
                            <button type="button" class="ke-org-resv-pill" role="tab" data-status="pending" aria-selected="false">
                                <?php esc_html_e( 'Pending', 'kiwi-events' ); ?>
                                <span class="ke-org-resv-pill-count" data-count="pending">0</span>
                            </button>
                            <button type="button" class="ke-org-resv-pill" role="tab" data-status="confirmed" aria-selected="false">
                                <?php esc_html_e( 'Confirmed', 'kiwi-events' ); ?>
                                <span class="ke-org-resv-pill-count" data-count="confirmed">0</span>
                            </button>
                            <button type="button" class="ke-org-resv-pill" role="tab" data-status="cancelled" aria-selected="false">
                                <?php esc_html_e( 'Cancelled', 'kiwi-events' ); ?>
                                <span class="ke-org-resv-pill-count" data-count="cancelled">0</span>
                            </button>
                            <button type="button" class="ke-org-resv-pill" role="tab" data-status="declined" aria-selected="false">
                                <?php esc_html_e( 'Declined', 'kiwi-events' ); ?>
                                <span class="ke-org-resv-pill-count" data-count="declined">0</span>
                            </button>
                            <button type="button" class="ke-org-resv-pill" role="tab" data-status="cancelled_by_venue" aria-selected="false">
                                <?php esc_html_e( 'Venue cancelled', 'kiwi-events' ); ?>
                                <span class="ke-org-resv-pill-count" data-count="cancelled_by_venue">0</span>
                            </button>
                            <button type="button" class="ke-org-resv-pill" role="tab" data-status="cancelled_no_show" aria-selected="false">
                                <?php esc_html_e( 'No-show', 'kiwi-events' ); ?>
                                <span class="ke-org-resv-pill-count" data-count="cancelled_no_show">0</span>
                            </button>
                        </div>

                        <div class="ke-org-reservations" id="keOrgReservations">
                            <div class="ke-org-resv-loading"><?php esc_html_e( 'Loading reservations…', 'kiwi-events' ); ?></div>
                        </div>
                    </div>
                </section>

                <!-- Reservation detail drawer — populated by JS, hidden until opened. -->
                <div class="ke-org-resv-drawer" id="keOrgResvDrawer" hidden>
                    <div class="ke-org-resv-drawer-overlay" data-resv-close="1"></div>
                    <aside class="ke-org-resv-drawer-panel" role="dialog" aria-labelledby="keOrgResvDrawerTitle" aria-modal="true">
                        <header class="ke-org-resv-drawer-header">
                            <h3 class="ke-org-resv-drawer-title" id="keOrgResvDrawerTitle"><?php esc_html_e( 'Reservation', 'kiwi-events' ); ?></h3>
                            <button type="button" class="ke-org-resv-drawer-close" data-resv-close="1" aria-label="<?php esc_attr_e( 'Close', 'kiwi-events' ); ?>">×</button>
                        </header>
                        <div class="ke-org-resv-drawer-body" id="keOrgResvDrawerBody"></div>
                        <footer class="ke-org-resv-drawer-actions" id="keOrgResvDrawerActions"></footer>
                    </aside>
                </div>

                <section class="ke-org-section">
                    <p class="ke-org-section-eyebrow"><?php esc_html_e( 'Live activity', 'kiwi-events' ); ?></p>
                    <div class="ke-org-card">
                        <header class="ke-org-card-header">
                            <div class="ke-org-card-titles">
                                <h2 class="ke-org-card-title"><?php esc_html_e( 'Recent purchases', 'kiwi-events' ); ?></h2>
                                <p class="ke-org-card-subtitle"><?php esc_html_e( 'Latest 10 orders, refreshed automatically.', 'kiwi-events' ); ?></p>
                            </div>
                            <span class="ke-org-activity-pulse" aria-hidden="true"></span>
                        </header>
                        <ul class="ke-org-activity" id="keOrgActivity"></ul>
                    </div>
                </section>

                <section class="ke-org-section" id="keOrgHighlightsSection">
                    <p class="ke-org-section-eyebrow"><?php esc_html_e( 'Historias Destacadas', 'kiwi-events' ); ?></p>
                    <div class="ke-org-card">
                        <header class="ke-org-card-header ke-org-card-header--toolbar">
                            <div class="ke-org-card-titles">
                                <h2 class="ke-org-card-title"><?php esc_html_e( 'Historias Destacadas', 'kiwi-events' ); ?></h2>
                                <p class="ke-org-card-subtitle"><?php esc_html_e( 'Colecciones tipo historia (portada + imágenes) que puedes mostrar en tus eventos.', 'kiwi-events' ); ?></p>
                            </div>
                            <button type="button" class="ke-org-hl-add" id="keOrgHlAdd" hidden><?php esc_html_e( '+ Agregar historia destacada', 'kiwi-events' ); ?></button>
                        </header>
                        <div class="ke-org-hl-grid" id="keOrgHlGrid" aria-busy="true">
                            <div class="ke-org-hl-loading"><?php esc_html_e( 'Cargando…', 'kiwi-events' ); ?></div>
                        </div>
                    </div>
                </section>

                <!-- Highlight editor drawer — populated + toggled by JS. -->
                <div class="ke-org-hl-drawer" id="keOrgHlDrawer" hidden>
                    <div class="ke-org-hl-drawer-overlay" data-hl-close="1"></div>
                    <aside class="ke-org-hl-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="keOrgHlDrawerTitle">
                        <header class="ke-org-hl-drawer-header">
                            <h3 class="ke-org-hl-drawer-title" id="keOrgHlDrawerTitle"><?php esc_html_e( 'Historia destacada', 'kiwi-events' ); ?></h3>
                            <button type="button" class="ke-org-hl-close" data-hl-close="1" aria-label="<?php esc_attr_e( 'Cerrar', 'kiwi-events' ); ?>">×</button>
                        </header>
                        <div class="ke-org-hl-drawer-body">
                            <label class="ke-org-hl-label"><?php esc_html_e( 'Nombre', 'kiwi-events' ); ?>
                                <input type="text" id="keOrgHlName" maxlength="60" class="ke-org-hl-input" placeholder="<?php esc_attr_e( 'Ej. Horario, Menú, Ubicación', 'kiwi-events' ); ?>">
                            </label>
                            <div class="ke-org-hl-field">
                                <span class="ke-org-hl-label-text"><?php esc_html_e( 'Portada · 1:1 (recomendado 600×600, se recorta en círculo)', 'kiwi-events' ); ?></span>
                                <div class="ke-org-hl-cover-preview" id="keOrgHlCoverPreview"></div>
                                <input type="file" id="keOrgHlCoverInput" accept="image/jpeg,image/png,image/webp" hidden>
                                <button type="button" class="ke-org-hl-btn ke-org-hl-btn--ghost" id="keOrgHlCoverBtn"><?php esc_html_e( 'Elegir portada', 'kiwi-events' ); ?></button>
                            </div>
                            <div class="ke-org-hl-field">
                                <span class="ke-org-hl-label-text"><?php esc_html_e( 'Imágenes · 9:16 (recomendado 1080×1920, máx 20, 3 MB c/u)', 'kiwi-events' ); ?></span>
                                <div class="ke-org-hl-images" id="keOrgHlImages"></div>
                                <input type="file" id="keOrgHlImagesInput" accept="image/jpeg,image/png,image/webp" multiple hidden>
                                <button type="button" class="ke-org-hl-btn ke-org-hl-btn--ghost" id="keOrgHlImagesBtn"><?php esc_html_e( 'Agregar imágenes', 'kiwi-events' ); ?></button>
                            </div>
                            <p class="ke-org-hl-error" id="keOrgHlError" role="alert" hidden></p>
                        </div>
                        <footer class="ke-org-hl-drawer-actions">
                            <button type="button" class="ke-org-hl-btn ke-org-hl-btn--ghost" data-hl-close="1"><?php esc_html_e( 'Cancelar', 'kiwi-events' ); ?></button>
                            <button type="button" class="ke-org-hl-btn ke-org-hl-btn--primary" id="keOrgHlSave"><?php esc_html_e( 'Guardar', 'kiwi-events' ); ?></button>
                        </footer>
                    </aside>
                </div>

                <footer class="ke-org-footer">
                    <?php
                    printf(
                        /* translators: %s: site name */
                        esc_html__( 'Powered by Kiwi Events · %s', 'kiwi-events' ),
                        esc_html( get_bloginfo( 'name' ) )
                    );
                    ?>
                </footer>
            </main>

        <?php endif; ?>

    </div>
    <?php wp_footer(); ?>
</body>
</html>
