jQuery(document).ready(function($) {
    // Function to display messages to the user in the admin panel
    function displayAdminMessage(message, type = 'info') {
        let messageContainer = $('#cityride-admin-message');
        if (messageContainer.length === 0) {
            // Create message container if it doesn't exist
            messageContainer = $('<div id="cityride-admin-message" class="notice is-dismissible"></div>');
            $('.wrap h1').after(messageContainer);
        }
        messageContainer.removeClass('notice-info notice-warning notice-error notice-success')
                        .addClass('notice-' + type)
                        .html('<p>' + message + '</p>')
                        .fadeIn();
        // Automatically hide after 5 seconds for info/success messages
        if (type === 'info' || type === 'success') {
            setTimeout(() => {
                messageContainer.fadeOut(() => messageContainer.remove());
            }, 5000);
        }
    }

    // Function to load rides data and update the table
    function loadRides(page = 1) {
        const tableBody = $('#rides-table-body');
        const paginationLinks = $('#rides-pagination .pagination-links');
        const paginationInfo = $('#rides-pagination .pagination-info');
        const resultsInfo = $('#rides-pagination .results-info');

        const status = $('#status-filter').val();
        const date_from = $('#date-from-filter').val();
        const date_to = $('#date-to-filter').val();
        const search = $('#search-filter').val();
        const per_page = $('#rides-per-page').val(); // Promijenjeno iz per-page-filter u rides-per-page

        tableBody.html('<tr><td colspan="12" style="text-align: center; padding: 40px;"><div class="spinner is-active" style="float: none;"></div> Učitavanje vožnji...</td></tr>');
        paginationLinks.empty();
        paginationInfo.empty();
        resultsInfo.empty();

        $.ajax({
            url: cityride_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cityride_load_rides',
                nonce: cityride_admin_ajax.nonce,
                page: page,
                per_page: per_page,
                status: status,
                date_from: date_from,
                date_to: date_to,
                search: search
            },
            success: function(response) {
                if (response.success) {
                    tableBody.html(response.data.rides_html);
                    paginationLinks.html(response.data.pagination_html);
                    paginationInfo.text(`Stranica ${response.data.current_page} od ${response.data.total_pages}`);
                    resultsInfo.text(`Ukupno vožnji: ${response.data.total_rides}`);
                    updateStatsDisplay(response.data.stats); // Update statistics cards
                } else {
                    tableBody.html('<tr><td colspan="12" style="text-align: center; padding: 40px; color: red;">Greška pri učitavanju vožnji: ' + response.data + '</td></tr>');
                    displayAdminMessage('Greška pri učitavanju vožnji: ' + response.data, 'error');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                tableBody.html('<tr><td colspan="12" style="text-align: center; padding: 40px; color: red;">Greška pri povezivanju sa serverom. Status: ' + textStatus + ', Greška: ' + errorThrown + '</td></tr>');
                displayAdminMessage('Greška pri povezivanju sa serverom. Molimo pokušajte ponovo. Status: ' + textStatus, 'error');
            }
        });
    }

    // Function to update statistics display (ISPRAVLJENO)
    function updateStatsDisplay(stats) {
        if (stats) { // Provjeravamo da li stats objekt postoji i nije null
            $('#stat-today-rides').text(stats.today_rides);
            $('#stat-this-month-rides').text(stats.this_month_rides);
            $('#stat-monthly-revenue').text(parseFloat(stats.monthly_revenue).toFixed(2) + ' BAM');
            $('#stat-pending-rides').text(stats.pending_rides);
            $('#stat-assigned-rides').text(stats.assigned_rides);
        } else {
            console.error("Stats data is missing or invalid.");
            // Reset to 0 if stats data is not available
            $('#stat-today-rides').text('0');
            $('#stat-this-month-rides').text('0');
            $('#stat-monthly-revenue').text('0.00 BAM');
            $('#stat-pending-rides').text('0');
            $('#stat-assigned-rides').text('0');
        }
    }

    // Initial load of rides when the document is ready
    loadRides();

    // Auto-refresh every 15 seconds
    setInterval(function() {
        // Get current filter values to maintain state on refresh
        const currentPage = $('#rides-pagination .pagination-link.current').data('page') || 1;
        loadRides(currentPage); // loadRides sada čita sve filtere unutar sebe
    }, 15000);

    // Filter button click handler
    $('#apply-filters').on('click', function() {
        loadRides(1); // Reset to first page on filter
    });
    
    // Per page filter change handler
    $('#rides-per-page').on('change', function() { // Promijenjeno iz per-page-filter u rides-per-page
        loadRides(1); // Reset to first page on per-page change
    });

    // Clear filters button click handler
    $('#clear-filters').on('click', function() {
        $('#status-filter').val('');
        $('#date-from-filter').val('');
        $('#date-to-filter').val('');
        $('#search-filter').val('');
        $('#rides-per-page').val('25'); // Reset per-page filter
        loadRides(1); // Load rides with cleared filters
    });

    // Export CSV button click handler
    $('#export-rides-csv').on('click', function() {
        const status = $('#status-filter').val();
        const date_from = $('#date-from-filter').val();
        const date_to = $('#date-to-filter').val();
        const search = $('#search-filter').val();

        displayAdminMessage('Priprema CSV fajla...', 'info');

        $.ajax({
            url: cityride_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cityride_export_rides',
                nonce: cityride_admin_ajax.nonce,
                status: status,
                date_from: date_from,
                date_to: date_to,
                search: search
            },
            success: function(response) {
                if (response.success) {
                    const csvContent = atob(response.data.data); // Dekodiraj Base64
                    const filename = response.data.filename;
                    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                    const link = document.createElement('a');
                    if (link.download !== undefined) {
                        const url = URL.createObjectURL(blob);
                        link.setAttribute('href', url);
                        link.setAttribute('download', filename);
                        link.style.visibility = 'hidden';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        displayAdminMessage('CSV fajl je uspješno generisan i preuzet.', 'success');
                    } else {
                        displayAdminMessage('Vaš preglednik ne podržava automatsko preuzimanje. Molimo kopirajte sadržaj.', 'warning');
                        window.open('data:text/csv;charset=utf-8,' + encodeURIComponent(csvContent));
                    }
                } else {
                    displayAdminMessage('Greška pri generisanju CSV fajla: ' + response.data, 'error');
                }
            },
            error: function() {
                displayAdminMessage('Greška pri povezivanju sa serverom za export CSV-a.', 'error');
            }
        });
    });

    // Refresh button click handler
    $('#refresh-rides').on('click', function() {
        const currentPage = $('#rides-pagination .pagination-link.current').data('page') || 1;
        loadRides(currentPage);
        displayAdminMessage('Vožnje osvježene.', 'info');
    });

    // Pagination link click handler (delegated to parent for dynamically added buttons)
    $('#rides-pagination').on('click', '.pagination-link', function(e) {
        e.preventDefault();
        const page = $(this).data('page');
        loadRides(page);
    });

    // Assign driver modal logic
    const assignDriverModal = $('#assign-driver-modal');
    const assignRideIdInput = $('#assign-ride-id');
    const cabDriverIdInput = $('#driver-vehicle-number'); // Ispravljen ID
    const etaInput = $('#eta');

    // Open modal when "Dodijeli vozača" button is clicked
    $('#rides-table-body').on('click', '.assign-driver-btn', function() {
        const rideId = $(this).data('ride-id');
        assignRideIdInput.val(rideId);
        cabDriverIdInput.val(''); // Clear previous driver ID
        etaInput.val(''); // Clear previous ETA
        assignDriverModal.fadeIn();
    });

    // Close modal when close button or cancel button is clicked
    assignDriverModal.find('.close, .cancel-modal').on('click', function() {
        assignDriverModal.fadeOut();
    });

    // Handle assign driver form submission
    $('#assign-driver-form').on('submit', function(e) {
        e.preventDefault();
        const rideId = assignRideIdInput.val();
        const cabDriverId = cabDriverIdInput.val().trim();
        const eta = etaInput.val().trim();

        if (!cabDriverId || !eta) {
            displayAdminMessage('Molimo unesite broj vozila i ETA.', 'warning'); // Ažurirana poruka
            return;
        }

        const submitBtn = $(this).find('button[type="submit"]');
        submitBtn.prop('disabled', true).text('Dodjeljujem...');

        $.ajax({
            url: cityride_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cityride_assign_driver',
                nonce: cityride_admin_ajax.nonce,
                ride_id: rideId,
                cab_driver_id: cabDriverId,
                eta: eta
            },
            success: function(response) {
                if (response.success) {
                    displayAdminMessage(response.data, 'success');
                    assignDriverModal.fadeOut();
                    loadRides(); // Reload rides to update the table
                } else {
                    displayAdminMessage('Greška pri dodjeli vozača: ' + response.data, 'error');
                }
            },
            error: function() {
                displayAdminMessage('Greška pri povezivanju sa serverom za dodjelu vozača.', 'error');
            },
            complete: function() {
                submitBtn.prop('disabled', false).text('Dodijeli'); // Promijenjeno nazad na "Dodijeli"
            }
        });
    });

    // Handle complete ride button click
    $('#rides-table-body').on('click', '.complete-ride-btn', function() {
        const rideId = $(this).data('ride-id');
        if (confirm('Jeste li sigurni da želite označiti ovu vožnju kao završenu?')) {
            const btn = $(this);
            btn.prop('disabled', true).text('Završavam...');

            $.ajax({
                url: cityride_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cityride_complete_ride',
                    nonce: cityride_admin_ajax.nonce,
                    ride_id: rideId
                },
                success: function(response) {
                    if (response.success) {
                        displayAdminMessage(response.data, 'success');
                        loadRides();
                    } else {
                        displayAdminMessage('Greška pri završetku vožnje: ' + response.data, 'error');
                    }
                },
                error: function() {
                    displayAdminMessage('Greška pri povezivanju sa serverom za završetak vožnje.', 'error');
                },
                complete: function() {
                    btn.prop('disabled', false).text('Završi'); // Promijenjeno nazad na "Završi"
                }
            });
        }
    });

    // Test Webhook button logic on config page
    $('#test-webhook-btn').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true).text('Testiram...');
        displayAdminMessage('Slanje testnog webhooka...', 'info');

        $.ajax({
            url: cityride_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cityride_test_webhook',
                nonce: cityride_admin_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayAdminMessage('Webhook test uspješan: ' + response.data, 'success');
                } else {
                    displayAdminMessage('Webhook test neuspješan: ' + response.data, 'error');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                displayAdminMessage('Greška pri povezivanju sa serverom za test webhooka. Status: ' + textStatus + ', Greška: ' + errorThrown, 'error');
            },
            complete: function() {
                btn.prop('disabled', false).text('Test Webhook');
            }
        });
    });
});