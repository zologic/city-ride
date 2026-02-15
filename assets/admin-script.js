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
    const driverSelect = $('#driver-select');
    const etaInput = $('#eta');

    // Open modal when "Dodijeli vozača" button is clicked
    $('#rides-table-body').on('click', '.assign-driver-btn', function() {
        const rideId = $(this).data('ride-id');
        assignRideIdInput.val(rideId);
        driverSelect.val(''); // Clear previous driver selection
        etaInput.val(''); // Clear previous ETA

        // Load active drivers into dropdown
        loadActiveDrivers();

        assignDriverModal.fadeIn();
    });

    // Close modal when close button or cancel button is clicked
    assignDriverModal.find('.close, .cancel-modal').on('click', function() {
        assignDriverModal.fadeOut();
    });

    // Function to load active drivers into dropdown
    function loadActiveDrivers() {
        driverSelect.html('<option value="">Učitavam vozače...</option>');

        $.ajax({
            url: cityride_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cityride_get_active_drivers',
                nonce: cityride_admin_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    let options = '<option value="">-- Odaberi vozača --</option>';
                    response.data.forEach(function(driver) {
                        options += `<option value="${driver.id}">${driver.name} (${driver.vehicle_number})</option>`;
                    });
                    driverSelect.html(options);
                } else {
                    driverSelect.html('<option value="">Nema aktivnih vozača</option>');
                    displayAdminMessage('Trenutno nema aktivnih vozača. Molimo dodajte vozača u sekciji "Upravljanje Vozačima".', 'warning');
                }
            },
            error: function() {
                driverSelect.html('<option value="">Greška pri učitavanju</option>');
                displayAdminMessage('Greška pri učitavanju vozača.', 'error');
            }
        });
    }

    // Handle assign driver form submission
    $('#assign-driver-form').on('submit', function(e) {
        e.preventDefault();
        const rideId = assignRideIdInput.val();
        const driverId = driverSelect.val();
        const eta = etaInput.val().trim();

        if (!driverId || !eta) {
            displayAdminMessage('Molimo odaberite vozača i unesite ETA.', 'warning');
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
                driver_id: driverId,
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
                submitBtn.prop('disabled', false).text('Dodijeli');
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

    // ============================================
    // Cancel Ride Modal Logic
    // ============================================

    const cancelRideModal = $('#cancel-ride-modal');
    const cancelRideIdInput = $('#cancel-ride-id');
    const cancelReasonTemplate = $('#cancel-reason-template');
    const cancelReasonCustom = $('#cancel-reason-custom');
    const processRefundCheckbox = $('#process-refund');

    // Show/hide custom reason textarea based on template selection
    cancelReasonTemplate.on('change', function() {
        if ($(this).val() === 'other') {
            cancelReasonCustom.show().prop('required', true);
        } else {
            cancelReasonCustom.hide().prop('required', false);
        }
    });

    // Open cancel modal when "Otkaži" button is clicked
    $('#rides-table-body').on('click', '.cancel-ride-btn', function() {
        const rideId = $(this).data('ride-id');
        cancelRideIdInput.val(rideId);
        cancelReasonTemplate.val('Zahtjev kupca'); // Reset to first option
        cancelReasonCustom.val('').hide(); // Hide custom reason
        processRefundCheckbox.prop('checked', true); // Default to refund
        $('#cancel-modal-message').empty(); // Clear previous messages
        cancelRideModal.fadeIn();
    });

    // Close cancel modal
    cancelRideModal.find('.close, .cancel-modal').on('click', function() {
        cancelRideModal.fadeOut();
    });

    // Handle cancel ride form submission
    $('#cancel-ride-form').on('submit', function(e) {
        e.preventDefault();

        const rideId = cancelRideIdInput.val();
        let cancellationReason = cancelReasonTemplate.val();

        // If "other" is selected, use custom reason
        if (cancellationReason === 'other') {
            cancellationReason = cancelReasonCustom.val().trim();
            if (!cancellationReason) {
                displayModalMessage('Molimo unesite razlog otkazivanja.', 'error', 'cancel');
                return;
            }
        }

        const processRefund = processRefundCheckbox.is(':checked');

        // Confirm cancellation
        const confirmMessage = processRefund
            ? `Jeste li sigurni da želite otkazati ovu vožnju?\n\nRazlog: ${cancellationReason}\n\nPovrat novca će biti procesuiran automatski.`
            : `Jeste li sigurni da želite otkazati ovu vožnju?\n\nRazlog: ${cancellationReason}\n\nPovrat novca NEĆE biti procesuiran.`;

        if (!confirm(confirmMessage)) {
            return;
        }

        const submitButton = $(this).find('button[type="submit"]');
        const originalButtonText = submitButton.text();
        submitButton.prop('disabled', true).text('Otkazivanje...');

        $.ajax({
            url: cityride_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cityride_cancel_ride',
                nonce: cityride_admin_ajax.nonce,
                ride_id: rideId,
                cancellation_reason: cancellationReason,
                process_refund: processRefund
            },
            success: function(response) {
                if (response.success) {
                    displayAdminMessage('Vožnja uspješno otkazana. ' +
                        (response.data.refund_status === 'refunded' ? 'Povrat novca procesuiran.' :
                         response.data.refund_status === 'refund_failed' ? 'UPOZORENJE: Povrat novca nije uspješan!' :
                         'Povrat novca nije procesuiran.'),
                        response.data.refund_status === 'refund_failed' ? 'warning' : 'success');
                    cancelRideModal.fadeOut();
                    loadRides(); // Refresh the table
                } else {
                    displayModalMessage('Greška: ' + response.data, 'error', 'cancel');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                displayModalMessage('Greška pri povezivanju sa serverom. Status: ' + textStatus, 'error', 'cancel');
            },
            complete: function() {
                submitButton.prop('disabled', false).text(originalButtonText);
            }
        });
    });

    // Helper function to display messages in modals
    function displayModalMessage(message, type = 'info', modalType = 'assign') {
        const messageContainer = modalType === 'cancel' ? $('#cancel-modal-message') : $('#modal-message');
        const alertClass = type === 'success' ? 'notice-success' :
                          type === 'error' ? 'notice-error' :
                          type === 'warning' ? 'notice-warning' : 'notice-info';

        messageContainer
            .removeClass('notice-success notice-error notice-warning notice-info')
            .addClass('notice ' + alertClass)
            .html('<p>' + message + '</p>')
            .show();

        // Auto-hide after 5 seconds for success/info
        if (type === 'success' || type === 'info') {
            setTimeout(() => {
                messageContainer.fadeOut();
            }, 5000);
        }
    }

    // ============================================
    // Driver Management Logic
    // ============================================

    // Check if we're on the drivers management page
    const isDriversPage = $('#drivers-table-body').length > 0;

    if (isDriversPage) {
        // Function to load drivers data and update the table
        function loadDrivers() {
            const tableBody = $('#drivers-table-body');

            tableBody.html('<tr><td colspan="10" style="text-align: center; padding: 40px;"><div class="spinner is-active" style="float: none;"></div> Učitavanje vozača...</td></tr>');

            $.ajax({
                url: cityride_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cityride_load_drivers',
                    nonce: cityride_admin_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        tableBody.html(response.data.drivers_html);
                        updateDriverStats(response.data.stats);
                    } else {
                        tableBody.html('<tr><td colspan="10" style="text-align: center; padding: 40px; color: red;">Greška pri učitavanju vozača: ' + response.data + '</td></tr>');
                        displayAdminMessage('Greška pri učitavanju vozača: ' + response.data, 'error');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    tableBody.html('<tr><td colspan="10" style="text-align: center; padding: 40px; color: red;">Greška pri povezivanju sa serverom. Status: ' + textStatus + ', Greška: ' + errorThrown + '</td></tr>');
                    displayAdminMessage('Greška pri povezivanju sa serverom. Status: ' + textStatus, 'error');
                }
            });
        }

        // Function to update driver statistics display
        function updateDriverStats(stats) {
            if (stats) {
                $('#stat-total-drivers').text(stats.total_drivers);
                $('#stat-active-drivers').text(stats.active_drivers);
                $('#stat-total-earnings').text(parseFloat(stats.total_earnings).toFixed(2) + ' BAM');
                $('#stat-top-driver').text(stats.top_driver || 'N/A');
            } else {
                console.error("Driver stats data is missing or invalid.");
                $('#stat-total-drivers').text('0');
                $('#stat-active-drivers').text('0');
                $('#stat-total-earnings').text('0.00 BAM');
                $('#stat-top-driver').text('N/A');
            }
        }

        // Initial load of drivers
        loadDrivers();

        // Populate driver select for earnings export
        function populateDriverSelect() {
            $.ajax({
                url: cityride_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cityride_load_drivers',
                    nonce: cityride_admin_ajax.nonce
                },
                success: function(response) {
                    if (response.success && response.data.drivers) {
                        const select = $('#export-driver-select');
                        select.find('option:not(:first)').remove();
                        response.data.drivers.forEach(function(driver) {
                            select.append($('<option>', {
                                value: driver.id,
                                text: driver.name + ' (' + driver.vehicle_number + ')'
                            }));
                        });
                    }
                }
            });
        }

        populateDriverSelect();

        // Handle date range change
        $('#export-date-range').on('change', function() {
            if ($(this).val() === 'custom') {
                $('#export-custom-dates, #export-custom-dates-to').show();
            } else {
                $('#export-custom-dates, #export-custom-dates-to').hide();
            }
        });

        // Handle earnings export form submission
        $('#driver-earnings-export-form').on('submit', function(e) {
            e.preventDefault();

            const driverId = $('#export-driver-select').val();
            const dateRange = $('#export-date-range').val();
            const startDate = $('#export-start-date').val();
            const endDate = $('#export-end-date').val();

            if (!driverId) {
                alert('Molimo odaberite vozača.');
                return;
            }

            if (dateRange === 'custom' && (!startDate || !endDate)) {
                alert('Molimo unesite početni i krajnji datum.');
                return;
            }

            // Create form and submit to trigger download
            const form = $('<form>', {
                method: 'POST',
                action: cityride_admin_ajax.ajax_url
            });

            form.append($('<input>', { type: 'hidden', name: 'action', value: 'cityride_export_driver_earnings' }));
            form.append($('<input>', { type: 'hidden', name: 'nonce', value: cityride_admin_ajax.nonce }));
            form.append($('<input>', { type: 'hidden', name: 'driver_id', value: driverId }));
            form.append($('<input>', { type: 'hidden', name: 'date_range', value: dateRange }));
            form.append($('<input>', { type: 'hidden', name: 'start_date', value: startDate }));
            form.append($('<input>', { type: 'hidden', name: 'end_date', value: endDate }));

            $('body').append(form);
            form.submit();
            form.remove();
        });

        // Driver modal elements
        const driverModal = $('#driver-modal');
        const driverForm = $('#driver-form');
        const driverIdInput = $('#driver-id');
        const driverModalTitle = $('#driver-modal-title');

        // Add driver button - open modal for new driver
        $('#add-driver-btn').on('click', function() {
            driverForm[0].reset();
            driverIdInput.val('');
            driverModalTitle.text('Dodaj Novog Vozača');
            $('#driver-modal-message').empty();
            driverModal.fadeIn();
        });

        // Edit driver button - open modal with driver data
        $('#drivers-table-body').on('click', '.edit-driver-btn', function() {
            const driverId = $(this).data('driver-id');
            driverModalTitle.text('Uredi Vozača');
            $('#driver-modal-message').empty();

            // Fetch driver data
            $.ajax({
                url: cityride_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cityride_get_driver',
                    nonce: cityride_admin_ajax.nonce,
                    driver_id: driverId
                },
                success: function(response) {
                    if (response.success) {
                        const driver = response.data;
                        driverIdInput.val(driver.id);
                        $('#driver-name').val(driver.name);
                        $('#driver-phone').val(driver.phone);
                        $('#driver-vehicle-number').val(driver.vehicle_number);
                        $('#driver-vehicle-model').val(driver.vehicle_model || '');
                        $('#driver-license-number').val(driver.license_number || '');
                        $('#driver-status').val(driver.status);
                        driverModal.fadeIn();
                    } else {
                        displayAdminMessage('Greška pri učitavanju podataka o vozaču: ' + response.data, 'error');
                    }
                },
                error: function() {
                    displayAdminMessage('Greška pri povezivanju sa serverom.', 'error');
                }
            });
        });

        // Close driver modal
        driverModal.find('.close, .cancel-modal').on('click', function() {
            driverModal.fadeOut();
        });

        // Handle driver form submission (Add/Edit)
        driverForm.on('submit', function(e) {
            e.preventDefault();

            const driverId = driverIdInput.val();
            const formData = {
                action: 'cityride_save_driver',
                nonce: cityride_admin_ajax.nonce,
                driver_id: driverId,
                name: $('#driver-name').val().trim(),
                phone: $('#driver-phone').val().trim(),
                vehicle_number: $('#driver-vehicle-number').val().trim(),
                vehicle_model: $('#driver-vehicle-model').val().trim(),
                license_number: $('#driver-license-number').val().trim(),
                status: $('#driver-status').val()
            };

            const submitBtn = driverForm.find('button[type="submit"]');
            const originalBtnText = submitBtn.text();
            submitBtn.prop('disabled', true).text('Spremam...');

            $.ajax({
                url: cityride_admin_ajax.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        displayAdminMessage(response.data, 'success');
                        driverModal.fadeOut();
                        loadDrivers(); // Reload drivers table
                    } else {
                        displayModalMessage('Greška: ' + response.data, 'error', 'driver');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    displayModalMessage('Greška pri povezivanju sa serverom. Status: ' + textStatus, 'error', 'driver');
                },
                complete: function() {
                    submitBtn.prop('disabled', false).text(originalBtnText);
                }
            });
        });

        // Handle delete driver button click
        $('#drivers-table-body').on('click', '.delete-driver-btn', function() {
            const driverId = $(this).data('driver-id');
            const driverName = $(this).data('driver-name');

            if (confirm(`Jeste li sigurni da želite obrisati vozača "${driverName}"?\n\nOva akcija ne može biti poništena.`)) {
                const btn = $(this);
                btn.prop('disabled', true);

                $.ajax({
                    url: cityride_admin_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cityride_delete_driver',
                        nonce: cityride_admin_ajax.nonce,
                        driver_id: driverId
                    },
                    success: function(response) {
                        if (response.success) {
                            displayAdminMessage(response.data, 'success');
                            loadDrivers(); // Reload drivers table
                        } else {
                            displayAdminMessage('Greška pri brisanju vozača: ' + response.data, 'error');
                            btn.prop('disabled', false);
                        }
                    },
                    error: function() {
                        displayAdminMessage('Greška pri povezivanju sa serverom za brisanje vozača.', 'error');
                        btn.prop('disabled', false);
                    }
                });
            }
        });

        // Update displayModalMessage to support driver modal
        const originalDisplayModalMessage = displayModalMessage;
        displayModalMessage = function(message, type = 'info', modalType = 'assign') {
            if (modalType === 'driver') {
                const messageContainer = $('#driver-modal-message');
                const alertClass = type === 'success' ? 'notice-success' :
                                  type === 'error' ? 'notice-error' :
                                  type === 'warning' ? 'notice-warning' : 'notice-info';

                messageContainer
                    .removeClass('notice-success notice-error notice-warning notice-info')
                    .addClass('notice ' + alertClass)
                    .html('<p>' + message + '</p>')
                    .show();

                if (type === 'success' || type === 'info') {
                    setTimeout(() => {
                        messageContainer.fadeOut();
                    }, 5000);
                }
            } else {
                originalDisplayModalMessage(message, type, modalType);
            }
        };
    }

    // ============================================
    // DISCOUNT CODE MANAGEMENT
    // ============================================

    const isDiscountsPage = $('#discount-codes-table-body').length > 0;

    if (isDiscountsPage) {
        const discountCodeModal = $('#discount-code-modal');
        const discountCodeForm = $('#discount-code-form');
        const discountCodeIdInput = $('#discount-code-id');
        const discountCodeModalTitle = $('#discount-code-modal-title');
        const discountCodeModalMessage = $('#discount-code-modal-message');
        const discountCodesTableBody = $('#discount-codes-table-body');

        /**
         * Load all discount codes
         */
        function loadDiscountCodes() {
            $.ajax({
                url: cityride_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cityride_load_discount_codes',
                    nonce: cityride_admin_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        renderDiscountCodesTable(response.data);
                    } else {
                        discountCodesTableBody.html('<tr><td colspan="10" style="text-align: center; padding: 40px; color: red;">Greška pri učitavanju kodova.</td></tr>');
                    }
                },
                error: function() {
                    discountCodesTableBody.html('<tr><td colspan="10" style="text-align: center; padding: 40px; color: red;">Greška pri povezivanju sa serverom.</td></tr>');
                }
            });
        }

        /**
         * Render discount codes table
         */
        function renderDiscountCodesTable(codes) {
            if (codes.length === 0) {
                discountCodesTableBody.html('<tr><td colspan="10" style="text-align: center; padding: 40px; color: #999;">Nema kodova popusta. Dodajte prvi kod!</td></tr>');
                return;
            }

            let html = '';
            codes.forEach(function(code) {
                const typeLabel = code.discount_type === 'percent' ? code.discount_value + '%' : code.discount_value + ' BAM';
                const statusClass = code.is_active == 1 ? 'status-active' : 'status-inactive';
                const statusLabel = code.is_active == 1 ? 'Aktivan' : 'Neaktivan';
                const validFrom = code.valid_from ? new Date(code.valid_from).toLocaleDateString('bs-BA') : '-';
                const validUntil = code.valid_until ? new Date(code.valid_until).toLocaleDateString('bs-BA') : '-';

                html += '<tr>';
                html += '<td><strong>' + code.code + '</strong></td>';
                html += '<td>' + (code.discount_type === 'percent' ? 'Procenat' : 'Fiksno') + '</td>';
                html += '<td>' + typeLabel + '</td>';
                html += '<td>' + parseFloat(code.min_order_amount).toFixed(2) + ' BAM</td>';
                html += '<td>' + (code.usage_limit || 'Neograničeno') + '</td>';
                html += '<td><strong>' + code.usage_count + '</strong></td>';
                html += '<td>' + validFrom + '</td>';
                html += '<td>' + validUntil + '</td>';
                html += '<td><span class="status-badge ' + statusClass + '">' + statusLabel + '</span></td>';
                html += '<td>';
                html += '<button class="button button-small edit-discount-code-btn" data-id="' + code.id + '">Uredi</button> ';
                html += '<button class="button button-small toggle-discount-code-btn" data-id="' + code.id + '" data-active="' + code.is_active + '">' + (code.is_active == 1 ? 'Deaktiviraj' : 'Aktiviraj') + '</button> ';
                html += '<button class="button button-small button-link-delete delete-discount-code-btn" data-id="' + code.id + '">Obriši</button>';
                html += '</td>';
                html += '</tr>';
            });

            discountCodesTableBody.html(html);
        }

        // Load discount codes on page load
        loadDiscountCodes();

        // Add new discount code button
        $('#add-discount-code-btn').on('click', function() {
            discountCodeForm[0].reset();
            discountCodeIdInput.val('');
            discountCodeModalTitle.text('Dodaj Novi Kod Popusta');
            discountCodeModalMessage.hide();
            $('#is-active').prop('checked', true);
            discountCodeModal.fadeIn();
        });

        // Edit discount code
        discountCodesTableBody.on('click', '.edit-discount-code-btn', function() {
            const codeId = $(this).data('id');
            discountCodeModalTitle.text('Uredi Kod Popusta');
            discountCodeModalMessage.hide();

            $.ajax({
                url: cityride_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cityride_get_discount_code',
                    nonce: cityride_admin_ajax.nonce,
                    id: codeId
                },
                success: function(response) {
                    if (response.success) {
                        const code = response.data;
                        discountCodeIdInput.val(code.id);
                        $('#discount-code-code').val(code.code);
                        $('#discount-type').val(code.discount_type);
                        $('#discount-value').val(code.discount_value);
                        $('#min-order-amount').val(code.min_order_amount);
                        $('#max-discount-amount').val(code.max_discount_amount || '');
                        $('#usage-limit').val(code.usage_limit || '');
                        $('#is-active').prop('checked', code.is_active == 1);
                        $('#valid-from').val(code.valid_from ? code.valid_from.replace(' ', 'T') : '');
                        $('#valid-until').val(code.valid_until ? code.valid_until.replace(' ', 'T') : '');
                        discountCodeModal.fadeIn();
                    } else {
                        alert('Greška pri učitavanju koda: ' + response.data);
                    }
                }
            });
        });

        // Save discount code (create/update)
        discountCodeForm.on('submit', function(e) {
            e.preventDefault();

            const formData = $(this).serializeArray();
            const data = {
                action: 'cityride_save_discount_code',
                nonce: cityride_admin_ajax.nonce
            };

            formData.forEach(function(item) {
                data[item.name] = item.value;
            });

            // Add checkbox value
            data.is_active = $('#is-active').is(':checked') ? '1' : '0';

            $.ajax({
                url: cityride_admin_ajax.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        showDiscountModalMessage(response.data.message, 'success');
                        setTimeout(function() {
                            discountCodeModal.fadeOut();
                            loadDiscountCodes();
                        }, 1500);
                    } else {
                        showDiscountModalMessage(response.data, 'error');
                    }
                },
                error: function() {
                    showDiscountModalMessage('Greška pri spremanju koda.', 'error');
                }
            });
        });

        // Delete discount code
        discountCodesTableBody.on('click', '.delete-discount-code-btn', function() {
            if (!confirm('Jeste li sigurni da želite obrisati ovaj kod popusta?')) {
                return;
            }

            const codeId = $(this).data('id');
            const btn = $(this);
            btn.prop('disabled', true);

            $.ajax({
                url: cityride_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cityride_delete_discount_code',
                    nonce: cityride_admin_ajax.nonce,
                    id: codeId
                },
                success: function(response) {
                    if (response.success) {
                        loadDiscountCodes();
                    } else {
                        alert('Greška: ' + response.data);
                        btn.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Greška pri brisanju koda.');
                    btn.prop('disabled', false);
                }
            });
        });

        // Toggle discount code active status
        discountCodesTableBody.on('click', '.toggle-discount-code-btn', function() {
            const codeId = $(this).data('id');
            const currentActive = $(this).data('active');
            const newActive = currentActive == 1 ? 0 : 1;
            const btn = $(this);
            btn.prop('disabled', true);

            $.ajax({
                url: cityride_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cityride_toggle_discount_code',
                    nonce: cityride_admin_ajax.nonce,
                    id: codeId,
                    is_active: newActive
                },
                success: function(response) {
                    if (response.success) {
                        loadDiscountCodes();
                    } else {
                        alert('Greška: ' + response.data);
                        btn.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Greška pri ažuriranju statusa.');
                    btn.prop('disabled', false);
                }
            });
        });

        // Close modal
        discountCodeModal.find('.close, .modal-cancel').on('click', function() {
            discountCodeModal.fadeOut();
        });

        // Helper function to show modal messages
        function showDiscountModalMessage(message, type) {
            const messageClass = type === 'success' ? 'notice-success' : 'notice-error';
            discountCodeModalMessage
                .removeClass('notice-success notice-error')
                .addClass(messageClass)
                .html('<p>' + message + '</p>')
                .show();

            if (type === 'success') {
                setTimeout(() => {
                    discountCodeModalMessage.fadeOut();
                }, 3000);
            }
        }
    }
});