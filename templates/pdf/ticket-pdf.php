<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * PDF ticket template reference
 * The actual PDF generation is handled by KE_PDF_Generator class using TCPDF.
 * This file serves as documentation for the ticket layout.
 *
 * Layout: Landscape 210mm x 100mm
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │ ████████████████████ GREEN ACCENT BAR ██████████████████ │
 * │                                                    ┆    │
 * │ 🥝 KIWIEVENTS                                     ┆    │
 * │ ┌──────────────┐                                   ┆    │
 * │ │ TICKET TYPE  │                                   ┆    │
 * │ └──────────────┘                                   ┆    │
 * │                                                    ┆    │
 * │  EVENT TITLE (large, white)                        ┆ QR │
 * │                                                    ┆    │
 * │  📅 Date & Time                                    ┆    │
 * │  📍 Venue — Address                                ┆    │
 * │  ─────────────────────────────────────             ┆    │
 * │  ATTENDEE          TICKET #                        ┆    │
 * │  Name              #0001                           ┆    │
 * │                                                    ┆    │
 * │  ticket-code-hash...                               ┆    │
 * │                                                    ┆    │
 * │  $XX.XX or FREE                                    ┆    │
 * │  Present this ticket at the venue.                 ┆    │
 * └─────────────────────────────────────────────────────────┘
 */
