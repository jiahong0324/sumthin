/**
 * ============================================================================
 * File: js/admin.js
 * Project: sumthin (Tech Gadget Online Store)
 * Module: Admin Operations & Dashboard Visualizations
 * Description: Unobtrusive jQuery event handlers for admin panels, including
 *              Chart.js dashboard analytics, batch table selection, and templates.
 *              Strictly avoids inline JS attributes (onclick, onchange).
 * ============================================================================
 */
$(function() {
    'use strict';

    // ========================================================================
    // 1. CHART.JS ADMIN DASHBOARD VISUALIZATIONS (TASK D6)
    // ========================================================================
    var chartDataEl = document.getElementById('dashboard-charts-data');
    if (chartDataEl && typeof Chart !== 'undefined') {
        try {
            var charts = JSON.parse(chartDataEl.textContent);

            // Chart A: Revenue per Day (Last 14 Days) - Line Chart
            var ctxRev = document.getElementById('chart-revenue-14-days');
            if (ctxRev && charts.revenue_14_days) {
                new Chart(ctxRev, {
                    type: 'line',
                    data: {
                        labels: charts.revenue_14_days.labels,
                        datasets: [{
                            label: 'Revenue (RM)',
                            data: charts.revenue_14_days.data,
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37, 99, 235, 0.1)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            pointBackgroundColor: '#2563eb',
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return 'Revenue: RM ' + Number(context.parsed.y).toFixed(2);
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) { return 'RM ' + value; }
                                }
                            }
                        }
                    }
                });
            }

            // Chart B: Revenue by Category - Doughnut Chart
            var ctxCat = document.getElementById('chart-revenue-by-category');
            if (ctxCat && charts.revenue_by_category && charts.revenue_by_category.data.length > 0) {
                new Chart(ctxCat, {
                    type: 'doughnut',
                    data: {
                        labels: charts.revenue_by_category.labels,
                        datasets: [{
                            data: charts.revenue_by_category.data,
                            backgroundColor: [
                                '#2563eb', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#64748b'
                            ],
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: { boxWidth: 12, font: { size: 11 } }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return ' ' + context.label + ': RM ' + Number(context.parsed).toFixed(2);
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Chart C: Top 5 Selling Products - Bar Chart
            var ctxTop = document.getElementById('chart-top-5-products');
            if (ctxTop && charts.top_5_products && charts.top_5_products.data.length > 0) {
                new Chart(ctxTop, {
                    type: 'bar',
                    data: {
                        labels: charts.top_5_products.labels,
                        datasets: [{
                            label: 'Units Sold',
                            data: charts.top_5_products.data,
                            backgroundColor: '#3b82f6',
                            borderRadius: 6,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { stepSize: 1 }
                            }
                        }
                    }
                });
            }

            // Chart D: Orders by Status - Bar Chart
            var ctxStatus = document.getElementById('chart-orders-by-status');
            if (ctxStatus && charts.orders_by_status) {
                new Chart(ctxStatus, {
                    type: 'bar',
                    data: {
                        labels: charts.orders_by_status.labels,
                        datasets: [{
                            label: 'Orders',
                            data: charts.orders_by_status.data,
                            backgroundColor: [
                                '#f59e0b', // Pending (amber)
                                '#3b82f6', // Processing (blue)
                                '#8b5cf6', // Shipped (purple)
                                '#10b981', // Delivered (green)
                                '#ef4444'  // Cancelled (red)
                            ],
                            borderRadius: 6,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { stepSize: 1 }
                            }
                        }
                    }
                });
            }
        } catch (err) {
            console.error('Failed to parse dashboard charts dataset:', err);
        }
    }

    // ========================================================================
    // 2. PRODUCT TABLE BATCH OPERATIONS & CHECKBOXES (TASK D7)
    // ========================================================================
    var $checkAll     = $('#check-all-products');
    var $productChecks = $('.product-checkbox');
    var $batchToolbar  = $('#batch-toolbar');
    var $selectedCount = $('#batch-selected-count');

    function updateBatchSelectionState() {
        var checkedCount = $('.product-checkbox:checked').length;
        if ($selectedCount.length) {
            $selectedCount.text(checkedCount);
        }

        if ($batchToolbar.length) {
            if (checkedCount > 0) {
                $batchToolbar.slideDown(150);
            } else {
                $batchToolbar.slideUp(150);
            }
        }

        if ($checkAll.length && $productChecks.length) {
            $checkAll.prop('checked', checkedCount === $productChecks.length && checkedCount > 0);
        }
    }

    if ($checkAll.length) {
        $checkAll.on('change', function() {
            var isChecked = $(this).is(':checked');
            $('.product-checkbox').prop('checked', isChecked);
            updateBatchSelectionState();
        });
    }

    $(document).on('change', '.product-checkbox', function() {
        updateBatchSelectionState();
    });

    // Batch Price Scope Selection Toggle
    $('#scope_type').on('change', function() {
        if ($(this).val() === 'checked') {
            $('.scope-filter-field').css('opacity', '0.4').find('select').prop('disabled', true);
        } else {
            $('.scope-filter-field').css('opacity', '1').find('select').prop('disabled', false);
        }
    });
    if ($('#scope_type').length && $('#scope_type').val() === 'checked') {
        $('.scope-filter-field').css('opacity', '0.4').find('select').prop('disabled', true);
    }

    // ========================================================================
    // 3. EMAIL TEMPLATE SELECTION (TASK D4)
    // ========================================================================
    $('#template_select').on('change', function() {
        var selectedId = $(this).val();
        if (!selectedId) return;

        var jsonElement = document.getElementById('email-templates-json');
        if (!jsonElement) return;

        try {
            var templates = JSON.parse(jsonElement.textContent);
            var match = templates.find(function(t) {
                return String(t.id) === String(selectedId);
            });

            if (match) {
                $('#subject').val(match.subject);
                $('#message').val(match.body);
            }
        } catch (err) {
            console.error('Error parsing email template data:', err);
        }
    });

    // Message Character Counter
    $('#message').on('input', function() {
        var len = $(this).val().length;
        $('#char-count').text(len);
        if (len < 10 || len > 5000) {
            $('#char-count').css('color', 'var(--error)');
        } else {
            $('#char-count').css('color', 'var(--gray)');
        }
    });

    if ($('#message').length > 0) {
        $('#char-count').text($('#message').val().length);
    }

    // ========================================================================
    // 4. VOUCHER FORM DISCOUNT TYPE TOGGLE (TASK D8)
    // ========================================================================
    $('#discount_type').on('change', function() {
        var type = $(this).val();
        if (type === 'percentage') {
            $('#max_discount_group').slideDown(150);
            $('#discount_unit_label').text('(%)');
            $('#discount_value').attr('max', '100');
        } else {
            $('#max_discount_group').slideUp(150);
            $('#discount_unit_label').text('(RM)');
            $('#discount_value').removeAttr('max');
        }
    });

    // Chat Member Jump Selector (TASK D11)
    $('#chat-member-select').on('change', function() {
        if ($(this).val()) {
            $(this).closest('form').trigger('submit');
        }
    });
});
