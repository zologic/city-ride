/**
 * CityRide Analytics Dashboard JavaScript
 * Handles chart rendering and data visualization
 */

jQuery(document).ready(function($) {
    // Check if we're on the analytics page and data exists
    if (typeof analyticsData === 'undefined') {
        return;
    }

    /**
     * Render Revenue Line Chart (Last 30 Days)
     */
    function renderRevenueChart() {
        const ctx = document.getElementById('revenue-chart');
        if (!ctx) return;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: analyticsData.revenueChart.dates,
                datasets: [{
                    label: 'Dnevni Prihod (BAM)',
                    data: analyticsData.revenueChart.revenues,
                    borderColor: '#4CAF50',
                    backgroundColor: 'rgba(76, 175, 80, 0.1)',
                    tension: 0.4,
                    fill: true,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#4CAF50'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 13
                        },
                        callbacks: {
                            label: function(context) {
                                return 'Prihod: ' + context.parsed.y.toFixed(2) + ' BAM';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value + ' BAM';
                            },
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });
    }

    /**
     * Render Driver Revenue Bar Chart
     */
    function renderDriverRevenueChart() {
        const ctx = document.getElementById('driver-revenue-chart');
        if (!ctx) return;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: analyticsData.driverRevenue.driver_names,
                datasets: [{
                    label: 'Prihod (BAM)',
                    data: analyticsData.driverRevenue.revenues,
                    backgroundColor: 'rgba(255, 165, 0, 0.7)',
                    borderColor: 'rgba(255, 165, 0, 1)',
                    borderWidth: 2,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return 'Prihod: ' + context.parsed.y.toFixed(2) + ' BAM';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value + ' BAM';
                            },
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 10
                            },
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                }
            }
        });
    }

    /**
     * Render Peak Hours Bar Chart
     */
    function renderPeakHoursChart() {
        const ctx = document.getElementById('peak-hours-chart');
        if (!ctx) return;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: analyticsData.peakHours.hours,
                datasets: [{
                    label: 'Broj Rezervacija',
                    data: analyticsData.peakHours.counts,
                    backgroundColor: 'rgba(33, 150, 243, 0.7)',
                    borderColor: 'rgba(33, 150, 243, 1)',
                    borderWidth: 2,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return 'Rezervacije: ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 10
                            },
                            maxRotation: 0,
                            minRotation: 0
                        }
                    }
                }
            }
        });
    }

    /**
     * Render Status Pie Chart
     */
    function renderStatusPieChart() {
        const ctx = document.getElementById('status-pie-chart');
        if (!ctx) return;

        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: analyticsData.statusDistribution.labels,
                datasets: [{
                    data: analyticsData.statusDistribution.counts,
                    backgroundColor: [
                        'rgba(255, 193, 7, 0.8)',   // Neraspoređeno - Yellow
                        'rgba(33, 150, 243, 0.8)',  // Raspoređeno - Blue
                        'rgba(76, 175, 80, 0.8)',   // Završeno - Green
                        'rgba(244, 67, 54, 0.8)',   // Otkazano - Red
                        'rgba(158, 158, 158, 0.8)'  // Nije se pojavio - Gray
                    ],
                    borderColor: [
                        'rgba(255, 193, 7, 1)',
                        'rgba(33, 150, 243, 1)',
                        'rgba(76, 175, 80, 1)',
                        'rgba(244, 67, 54, 1)',
                        'rgba(158, 158, 158, 1)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            font: {
                                size: 13
                            },
                            padding: 15,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return label + ': ' + value + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    /**
     * Load detailed analytics report
     */
    function loadAnalyticsReport() {
        const startDate = $('#analytics-start-date').val();
        const endDate = $('#analytics-end-date').val();
        const groupBy = $('#analytics-group-by').val();
        const tableBody = $('#report-table-body');

        tableBody.html('<tr><td colspan="7" style="text-align: center; padding: 40px;"><div class="spinner is-active" style="float: none;"></div> Učitavanje izvještaja...</td></tr>');

        $.ajax({
            url: cityride_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cityride_get_analytics_data',
                nonce: cityride_admin_ajax.nonce,
                start_date: startDate,
                end_date: endDate,
                group_by: groupBy
            },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    let html = '';
                    response.data.forEach(function(row) {
                        html += '<tr>';
                        html += '<td>' + row.period + '</td>';
                        html += '<td>' + row.total_rides + '</td>';
                        html += '<td>' + row.completed_rides + '</td>';
                        html += '<td>' + row.cancelled_rides + '</td>';
                        html += '<td>' + parseFloat(row.total_revenue).toFixed(2) + ' BAM</td>';
                        html += '<td>' + parseFloat(row.avg_ride_value).toFixed(2) + ' BAM</td>';
                        html += '<td>' + parseFloat(row.cancellation_rate).toFixed(1) + '%</td>';
                        html += '</tr>';
                    });
                    tableBody.html(html);
                } else {
                    tableBody.html('<tr><td colspan="7" style="text-align: center; padding: 40px; color: #999;">Nema podataka za odabrani period.</td></tr>');
                }
            },
            error: function() {
                tableBody.html('<tr><td colspan="7" style="text-align: center; padding: 40px; color: red;">Greška pri učitavanju izvještaja.</td></tr>');
            }
        });
    }

    /**
     * Export analytics to Excel
     */
    function exportAnalyticsExcel() {
        const startDate = $('#analytics-start-date').val();
        const endDate = $('#analytics-end-date').val();
        const groupBy = $('#analytics-group-by').val();

        // Create a form and submit it
        const form = $('<form>', {
            method: 'POST',
            action: cityride_admin_ajax.ajax_url
        });

        form.append($('<input>', { type: 'hidden', name: 'action', value: 'cityride_export_analytics_excel' }));
        form.append($('<input>', { type: 'hidden', name: 'nonce', value: cityride_admin_ajax.nonce }));
        form.append($('<input>', { type: 'hidden', name: 'start_date', value: startDate }));
        form.append($('<input>', { type: 'hidden', name: 'end_date', value: endDate }));
        form.append($('<input>', { type: 'hidden', name: 'group_by', value: groupBy }));

        $('body').append(form);
        form.submit();
        form.remove();
    }

    // Initialize all charts on page load
    renderRevenueChart();
    renderDriverRevenueChart();
    renderPeakHoursChart();
    renderStatusPieChart();

    // Handle analytics filters form submission
    $('#analytics-filters').on('submit', function(e) {
        e.preventDefault();
        loadAnalyticsReport();
    });

    // Handle Excel export button
    $('#export-analytics-excel').on('click', function(e) {
        e.preventDefault();
        exportAnalyticsExcel();
    });
});
