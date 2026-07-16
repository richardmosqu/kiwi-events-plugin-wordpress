# KiwiEvents 🥝

Plugin de WordPress para gestión completa de eventos y venta de boletos: crea eventos, vende boletos (gratis o de pago vía WooCommerce), genera boletos con código QR, escanéalos en la puerta, gestiona asistentes, reservaciones y promotores, y consulta dashboards de ventas.

- **Versión del plugin:** 2.0.3 (`KE_VERSION`)
- **Requiere:** WordPress 6.0+, PHP 8.0+
- **Licencia:** GPL-2.0+
- **Text domain:** `kiwi-events`

## Funcionalidades

- **Eventos** — post type `ke_event` con fechas, sede/dirección, organizador, categorías, estado (`active`, `cancelled`, `postponed`, …), extras y slug editable con redirecciones 301.
- **Boletos** — tipos de boleto con precio y cupo; un registro por boleto individual con código único (sha256) y QR; generación automática al completar la orden de WooCommerce (o checkout gratuito propio).
- **Wallet del cliente** — shortcode `[kiwi_tickets_purchase]`: el usuario logueado ve sus boletos agrupados por evento en pestañas Próximos / Pasados / Cancelados, con modal de QR y descarga del PDF individual (endpoint con verificación de propiedad en el servidor).
- **Scanner** — página pública de escaneo con cámara (jsQR) y validación por REST, contraseña por organizador y flujo de estados secuencial.
- **Reservaciones** — reservas de grupo por evento (auto-confirmadas o aprobadas por la sede), en paralelo a la venta de boletos; página de administración con exportes CSV y PDF.
- **Organizadores** — dashboard self-service en `/organizer/{slug}`, perfil público en `/organizers/{slug}`, estadísticas y reporte PDF de ventas.
- **Promotores** — atribución por URL (`?ke_promo`), comisiones con política de reembolso configurable, portal del promotor en `/promoter/{slug}`, listas, importación y módulo de administración.
- **Emails** — confirmación con boletos adjuntos en PDF, cola de envío y plantillas.
- **Dashboards** — ventas, ingresos y asistentes en wp-admin, con modo de color.

## Shortcodes

| Shortcode | Descripción |
|---|---|
| `[kiwi_events]` | Listado/carrusel principal de eventos |
| `[kiwi_event]` | Un evento individual |
| `[kiwi_checkout]` | Checkout de boletos |
| `[kiwi_events_carousel]` | Carrusel de eventos (filtrable por categoría) |
| `[kiwi_events_list]` | Lista de eventos |
| `[kiwi_events_calendar]` | Calendario de eventos |
| `[kiwi_organizers]` | Catálogo de organizadores |
| `[kiwi_tickets_purchase]` | Wallet "Mis Boletos" del cliente (requiere login) |
| `[kiwi-promoter-dashboard]` | Mini-dashboard del promotor |

## Instalación

1. Copia la carpeta del plugin a `wp-content/plugins/kiwi-events/` **incluyendo la carpeta `vendor/`** (ver abajo).
2. Activa **KiwiEvents** en wp-admin → Plugins. Las tablas (`wp_ke_orders`, `wp_ke_tickets`, `wp_ke_reservations`, promotores, etc.) se crean/migran automáticamente (`KE_DB_VERSION`).
3. (Opcional) Activa **WooCommerce** para boletos de pago — la integración se registra sola si WooCommerce está activo.
4. Configura en **Eventos → Settings**: control de acceso (URLs de login/registro y mensaje), límite de boletos por persona, service fee, etc.

### ⚠️ La carpeta `vendor/` es obligatoria en producción

Las dependencias de Composer (**TCPDF** para los PDFs de boletos y **chillerlan/php-qrcode**) están commiteadas en el repo a propósito: los hostings gestionados como WordPress.com **no ejecutan Composer**, así que el plugin debe desplegarse con `vendor/` incluido. Si falta, cualquier PDF de boleto (descarga del wallet o adjunto de email) falla con `tcpdf_missing`.

Para regenerarlas en desarrollo:

```bash
composer install --no-dev
```

Nota: `composer.json` **no** debe volver a incluir un classmap sobre `includes/`, `admin/` o `public/` — el plugin carga sus clases con `require_once` y un self-classmap provoca fatales de "class already in use" con los guards `class_exists()` de los reportes FPDF.

## Estructura

```
kiwi-events.php          Bootstrap: constantes, requires, arranque
includes/                Núcleo: post types, tickets, orders, REST API (ke/v1),
                         WooCommerce, QR, PDF, emails, promotores, reservaciones
admin/                   Páginas de wp-admin (dashboard, asistentes, promotores…)
public/                  Frontend: shortcodes, vistas, CSS/JS (tokens --kep-*)
vendor/                  Dependencias Composer (TCPDF, php-qrcode) — se despliega
```

### Constantes de versión de assets

`KE_VERSION` cambia lento; las áreas que iteran rápido tienen su propia constante de cache-busting (importante por el caché de borde de WordPress.com — súbela y purga el caché al desplegar CSS/JS):

| Constante | Área |
|---|---|
| `KE_SCANNER_ASSETS_VER` | Scanner |
| `KE_BUILDER_ASSETS_VER` | Event builder (admin) |
| `KE_TOKENS_ASSETS_VER` | Tokens de admin |
| `KE_ADMIN_CSS_VER` / `KE_ADMIN_JS_VER` | Admin general |
| `KE_PORTAL_ASSETS_VER` | Portal del promotor |
| `KE_WALLET_ASSETS_VER` | Wallet de boletos |

## Notas para desarrollo

- **PHP 8:** nunca pases funciones internas de PHP (`is_numeric`, `absint`, …) como string en `validate_callback`/`sanitize_callback` de rutas REST — WordPress las invoca con 3 argumentos y PHP 8 lanza `ArgumentCountError`. Usa closures: `static function ( $value ) { return is_numeric( $value ); }`.
- **Frontend:** usa los tokens `--kep-*` (definidos en `public/css/ke-public.css`); no hardcodees el verde Kiwi en el frontend. Toda superficie pública nueva necesita el guard anti-override de botones de WordPress.com (patrón de `ke-promoter-portal.css`).
- **Fechas de eventos:** reutiliza `KE_Shortcodes::event_is_expired()` (fail-open, maneja los formatos mixtos de `_ke_event_date_start/_end`); no escribas comparaciones de fecha nuevas.
- **Seguridad:** los endpoints que sirven datos de un boleto deben verificar propiedad contra `wp_ke_orders.user_id` en el servidor — el nonce solo establece la identidad, no la autorización.
