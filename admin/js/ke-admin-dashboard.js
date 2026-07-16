/**
 * KiwiEvents — Admin Dashboard Charts (Chart.js)
 *
 * Chart.js doesn't subscribe to CSS variable changes, so colors are read
 * ONCE at render time via getComputedStyle(html). When the user flips the
 * admin color mode, a full reload is needed to repaint the charts with the
 * new palette — accepted limitation, documented in dark-mode sprint notes.
 */
(function($) {
    'use strict';

    let revenueChart = null;
    let ticketsChart = null;
    let typeChart = null;

    /**
     * Read a --kiwi-* token off <html> at call time, falling back to a
     * hard-coded literal when the var is missing (e.g. unit-test contexts
     * or pages where the tokens stylesheet didn't load).
     */
    function keVar(name, fallback) {
        try {
            var v = getComputedStyle(document.documentElement).getPropertyValue(name);
            return (v && v.trim()) || fallback;
        } catch (e) {
            return fallback;
        }
    }

    var KE_CHART_COLORS = {
        text:         keVar('--kiwi-text',           '#1a1a1a'),
        textMuted:    keVar('--kiwi-text-muted',     '#6b7280'),
        textDarker:   keVar('--kiwi-text-darker',    '#000000'),
        border:       keVar('--kiwi-border',         '#e5e7eb'),
        surface:      keVar('--kiwi-surface',        '#ffffff'),
        // Tooltips stay dark slate in BOTH modes (Apple-style inverted
        // tooltip). Using --kiwi-surface here would flip them to white in
        // light mode, which is a visual regression from the pre-dark-mode
        // chart styling.
        tooltipBg:    '#1f2937',
        tooltipText:  '#f9fafb',
        tooltipBody:  '#e5e7eb',
        gridLine:     keVar('--kiwi-border',         '#f3f4f6'),
    };

    // Chart.js global defaults — driven by tokens so dark mode reads correctly.
    Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    Chart.defaults.color = KE_CHART_COLORS.textMuted;
    Chart.defaults.plugins.legend.display = false;

    /**
     * Fetch chart data from REST API
     */
    function fetchChartData(eventId) {
        const params = new URLSearchParams();
        if (eventId) params.set('event_id', eventId);
        params.set('days', '30');

        $.ajax({
            url: keAdmin.restUrl + 'dashboard/chart-data?' + params.toString(),
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', keAdmin.nonce);
            },
            success: function(data) {
                renderRevenueChart(data.revenue);
                renderTicketsChart(data.tickets_per_event);
                renderTypeChart(data.type_distribution);
            },
            error: function(xhr) {
                console.error('KiwiEvents: Failed to load chart data', xhr);
            }
        });
    }

    /**
     * Fetch KPI stats
     */
    function fetchStats(eventId) {
        const params = eventId ? '?event_id=' + eventId : '';

        $.ajax({
            url: keAdmin.restUrl + 'dashboard/stats' + params,
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', keAdmin.nonce);
            },
            success: function(data) {
                $('#kpi-revenue').text('$' + parseFloat(data.total_revenue || 0).toLocaleString('en-US', {minimumFractionDigits: 2}));
                $('#kpi-tickets').text(parseInt(data.total_tickets || 0).toLocaleString());
                $('#kpi-events').text(parseInt(data.active_events || 0));
                $('#kpi-checkin').text((data.checkin_rate || 0) + '%');
            }
        });
    }

    /**
     * Revenue over time line chart
     */
    function renderRevenueChart(data) {
        const ctx = document.getElementById('ke-revenue-chart');
        if (!ctx) return;

        if (revenueChart) revenueChart.destroy();

        const labels = (data || []).map(d => {
            const date = new Date(d.date);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        const revenues = (data || []).map(d => parseFloat(d.revenue));
        const tickets = (data || []).map(d => parseInt(d.tickets));

        revenueChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Revenue ($)',
                        data: revenues,
                        borderColor: '#84cc16',
                        backgroundColor: 'rgba(163, 230, 53, 0.1)',
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#84cc16',
                        pointBorderColor: KE_CHART_COLORS.surface,
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                    },
                    {
                        label: 'Tickets',
                        data: tickets,
                        borderColor: '#9ca3af',
                        backgroundColor: 'rgba(156, 163, 175, 0.05)',
                        borderWidth: 2,
                        fill: false,
                        tension: 0.4,
                        borderDash: [5, 5],
                        pointRadius: 3,
                        yAxisID: 'y1',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top', align: 'end' },
                    tooltip: {
                        backgroundColor: KE_CHART_COLORS.tooltipBg,
                        titleColor: KE_CHART_COLORS.tooltipText,
                        bodyColor: KE_CHART_COLORS.tooltipBody,
                        borderWidth: 0,
                        cornerRadius: 8,
                        padding: 12,
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { maxTicksLimit: 10, color: KE_CHART_COLORS.textMuted }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: KE_CHART_COLORS.gridLine },
                        ticks: {
                            color: KE_CHART_COLORS.textMuted,
                            callback: function(val) { return '$' + val; }
                        }
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        grid: { display: false },
                        ticks: {
                            stepSize: 1,
                            color: KE_CHART_COLORS.textMuted,
                            callback: function(val) { return val + ' tix'; }
                        }
                    }
                }
            }
        });
    }

    /**
     * Tickets per event horizontal bar chart
     */
    function renderTicketsChart(data) {
        const ctx = document.getElementById('ke-tickets-chart');
        if (!ctx) return;

        if (ticketsChart) ticketsChart.destroy();

        const labels = (data || []).map(d => d.event_name || 'Unknown');
        const values = (data || []).map(d => parseInt(d.ticket_count));

        const colors = [
            '#84cc16', '#a3e635', '#65a30d', '#9ca3af', '#6b7280',
            '#4b5563', '#bef264', '#d9f99d', '#374151', '#ecfccb'
        ];

        ticketsChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Tickets Sold',
                    data: values,
                    backgroundColor: values.map((_, i) => colors[i % colors.length] + '33'),
                    borderColor: values.map((_, i) => colors[i % colors.length]),
                    borderWidth: 2,
                    borderRadius: 6,
                    barThickness: 28,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    tooltip: {
                        backgroundColor: KE_CHART_COLORS.tooltipBg,
                        titleColor: KE_CHART_COLORS.tooltipText,
                        bodyColor: KE_CHART_COLORS.tooltipBody,
                        borderWidth: 0,
                        cornerRadius: 8,
                        padding: 12,
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: KE_CHART_COLORS.gridLine },
                        ticks: { stepSize: 1, color: KE_CHART_COLORS.textMuted }
                    },
                    y: {
                        grid: { display: false },
                        ticks: {
                            font: { weight: 600 },
                            color: KE_CHART_COLORS.text
                        }
                    }
                }
            }
        });
    }

    /**
     * Ticket type distribution doughnut chart
     */
    function renderTypeChart(data) {
        const ctx = document.getElementById('ke-type-chart');
        if (!ctx) return;

        if (typeChart) typeChart.destroy();

        const labels = (data || []).map(d => d.name);
        const values = (data || []).map(d => parseInt(d.sold));

        const colors = [
            '#84cc16', '#a3e635', '#65a30d', '#9ca3af', '#6b7280',
            '#4b5563', '#bef264', '#d9f99d', '#374151', '#ecfccb'
        ];

        typeChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors.slice(0, values.length),
                    borderWidth: 0,
                    hoverBorderWidth: 3,
                    hoverBorderColor: KE_CHART_COLORS.surface,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: {
                        display: true,
                        position: 'right',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 16,
                            font: { size: 12 },
                            color: KE_CHART_COLORS.text
                        }
                    },
                    tooltip: {
                        backgroundColor: KE_CHART_COLORS.tooltipBg,
                        titleColor: KE_CHART_COLORS.tooltipText,
                        bodyColor: KE_CHART_COLORS.tooltipBody,
                        borderWidth: 0,
                        cornerRadius: 8,
                        padding: 12,
                    }
                }
            }
        });
    }

    /**
     * Initialize
     */
    $(document).ready(function() {
        // Load initial chart data
        fetchChartData(0);

        // Event filter handler
        $('#ke-event-filter').on('change', function() {
            const eventId = $(this).val();
            fetchStats(eventId);
            fetchChartData(eventId);
        });
    });

})(jQuery);
