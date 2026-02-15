jQuery(document).ready(function($) {
    let stripe;
    let elements;
    let cardElement;
    let cardMounted = false;
    let currentBookingData = {};
    let oneSignalPlayerId = ''; // Variable to store OneSignal Player ID
    let iti; // International telephone input instance

    // Initialize Stripe if public key is available
    if (typeof cityride_frontend_ajax !== 'undefined' && typeof cityride_frontend_ajax.stripe_public_key === 'string' && cityride_frontend_ajax.stripe_public_key.length > 0) {
        stripe = Stripe(cityride_frontend_ajax.stripe_public_key);
        elements = stripe.elements();

        cardElement = elements.create('card', {
            style: {
                base: {
                    fontSize: '16px',
                    color: '#424770',
                    '::placeholder': { color: '#aab7c4' },
                },
            },
            hidePostalCode: true
        });
    }

    // --- CRUCIAL CHANGE FOR ONESIGNAL: Robustly wait for OneSignal SDK ---
    function ensureOneSignalReady() {
        return new Promise((resolve, reject) => {
            const checkInterval = setInterval(() => {
                if (typeof window.OneSignal !== 'undefined' && typeof window.OneSignal.isPushNotificationsEnabled === 'function' && typeof window.OneSignal.getUserId === 'function') {
                    clearInterval(checkInterval);
                    console.log("frontend-script.js: OneSignal SDK je spreman i potpuno učitan.");
                    resolve(window.OneSignal);
                } else {
                    // console.log("frontend-script.js: Čekam na OneSignal SDK da se potpuno učita i inicijalizira..."); // Sakrijte ovu poruku, bila je previše
                }
            }, 100); // Provjeravaj svakih 100ms
        });
    }

    if (typeof cityride_frontend_ajax !== 'undefined' && cityride_frontend_ajax.enable_push_notifications === '1') {
        ensureOneSignalReady().then(OneSignal => {
            console.log("frontend-script.js: Pokrećem OneSignal logiku u frontend-script.js.");

            OneSignal.isPushNotificationsEnabled(function(isEnabled) {
                console.log("frontend-script.js: OneSignal.isPushNotificationsEnabled status:", isEnabled); // NOVI LOG

                if (isEnabled) {
                    console.log("frontend-script.js: Push notifikacije su omogućene u pregledniku.");
                    OneSignal.getUserId().then(function(userId) {
                        console.log("frontend-script.js: Rezultat OneSignal.getUserId().then() - userId:", userId); // NOVI LOG
                        if (userId) {
                            console.log("frontend-script.js: OneSignal Player ID (iz OneSignal.getUserId().then()):", userId);
                            oneSignalPlayerId = userId;
                            $('#passenger_onesignal_id').val(userId);
                            console.log("frontend-script.js: Vrijednost skrivenog polja '#passenger_onesignal_id' nakon postavljanja:", $('#passenger_onesignal_id').val());
                        } else {
                            console.warn("frontend-script.js: OneSignal Player ID nije dostupan iz getUserId() - userId je null/undefined.");
                            $('#passenger_onesignal_id').val('');
                        }
                    }).catch(function(error) {
                        console.error("frontend-script.js: Greška pri dohvaćanju OneSignal Player ID-a (Promise reject):", error);
                        $('#passenger_onesignal_id').val('');
                    });
                } else {
                    console.log("frontend-script.js: Push notifikacije NISU omogućene za ovog korisnika u pregledniku.");
                    $('#passenger_onesignal_id').val(''); // Ako nisu omogućene, postavi ID na prazno
                }
            });

            OneSignal.on('subscriptionChange', function(isSubscribed) {
                console.log("frontend-script.js: Događaj: subscriptionChange, isSubscribed:", isSubscribed); // NOVI LOG
                if (isSubscribed) {
                    console.log("frontend-script.js: Događaj: Korisnik se upravo pretplatio.");
                    OneSignal.getUserId().then(function(userId) {
                        console.log("frontend-script.js: Događaj: Novi Player ID nakon pretplate (iz subscriptionChange):", userId); // NOVI LOG
                        if (userId) {
                            $('#passenger_onesignal_id').val(userId);
                        }
                    });
                } else {
                    $('#passenger_onesignal_id').val('');
                    console.log("frontend-script.js: Događaj: Korisnik se odjavio sa push notifikacija.");
                }
            });
        }).catch(error => {
            console.error("frontend-script.js: Greška pri čekanju na OneSignal SDK (outer catch):", error);
            $('#passenger_onesignal_id').val(''); // Clear ID if OneSignal never becomes ready
        });
    } else {
        console.log("frontend-script.js: Push notifikacije su onemogućene u WordPress postavkama ili cityride_frontend_ajax nije definiran.");
        $('#passenger_onesignal_id').val('');
    }

    // Initialize International Phone Input
    initializeInternationalPhone();

    // Handle price calculation button click (rest of your script remains the same)
    $('#calculate-price-btn').on('click', function() {
        const addressFrom = $('#address-from').val().trim();
        const addressTo = $('#address-to').val().trim();

        if (!addressFrom || !addressTo) {
            alert('Molimo unesite obje adrese');
            return;
        }

        const btn = $(this);
        btn.prop('disabled', true).text('Izračunavam...');

        $('.map-container').fadeOut();
        $('#price-calculation-section').fadeOut();
        $('#payment-section').fadeOut();
        $('#cityride-message').empty().hide();
        $('#booking-success').hide();

        $.ajax({
            url: cityride_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cityride_calculate_price',
                nonce: cityride_frontend_ajax.nonce,
                address_from: addressFrom,
                address_to: addressTo
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;

                    currentBookingData = {
                        address_from: addressFrom,
                        address_to: addressTo,
                        distance_km: data.distance_km,
                        total_price: data.total_price,
                        start_tariff: data.start_tariff,
                        price_per_km: data.price_per_km
                    };

                    // Update hidden fields with calculated values
                    $('#calculated_distance_km').val(data.distance_km);
                    $('#calculated_total_price').val(data.total_price);
                    $('#calculated_stripe_amount').val(data.stripe_amount);

                    // Display basic pricing info
                    $('#distance-display').text(data.distance_km);
                    $('#start-tariff-display').text(data.start_tariff.toFixed(2));
                    $('#price-per-km-display').text(data.price_per_km.toFixed(2));
                    $('#total-price').text(data.total_price.toFixed(2));

                    // Show discount code section
                    $('#discount-code-section').fadeIn();

                    $('#price-calculation-section').fadeIn();

                    if (data.route_geometry && data.from_coords && data.to_coords) {
                        showRoute(data.route_geometry, data.from_coords, data.to_coords);
                        $('.map-container').fadeIn();
                    }

                    $('#payment-section').fadeIn();
                    if (cardElement && !cardMounted) {
                        cardElement.mount('#stripe-card-element');
                        cardMounted = true;
                    }

                    $('html, body').animate({
                        scrollTop: $('#payment-section').offset().top - 100
                    }, 500);

                } else {
                    $('#cityride-message').text('Greška: ' + response.data).css('color', 'red').fadeIn();
                }
            },
            error: function() {
                $('#cityride-message').text('Greška pri povezivanju s serverom.').css('color', 'red').fadeIn();
            },
            complete: function() {
                btn.prop('disabled', false).text('Izračunaj Cijenu');
            }
        });
    });

    // International Phone Input Functions
    function initializeInternationalPhone() {
        const input = document.querySelector("#passenger-phone");

        if (!input || typeof window.intlTelInput === 'undefined') {
            console.warn('International phone input not available');
            return;
        }

        // Initialize intl-tel-input with auto-detect and preferred countries
        fetch('https://ipapi.co/json/')
            .then(response => response.json())
            .then(data => {
                const countryCode = data.country_code ? data.country_code.toLowerCase() : 'ba';
                initPhoneInput(input, countryCode);
            })
            .catch(error => {
                console.log('Could not detect country, defaulting to Bosnia');
                initPhoneInput(input, 'ba');
            });
    }

    function initPhoneInput(input, initialCountry) {
        iti = window.intlTelInput(input, {
            preferredCountries: ['ba', 'hr', 'rs', 'me', 'si', 'mk'],
            initialCountry: initialCountry,
            separateDialCode: true,
            autoPlaceholder: 'aggressive',
            formatOnDisplay: true,
            nationalMode: false,
            utilsScript: "https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/js/utils.js"
        });

        // Validate on blur
        input.addEventListener('blur', function() {
            validatePhoneNumber();
        });

        // Validate on country change
        input.addEventListener('countrychange', function() {
            validatePhoneNumber();
        });

        // Update hidden field on input
        input.addEventListener('input', function() {
            updateHiddenPhoneField();
        });
    }

    function validatePhoneNumber() {
        const input = document.querySelector("#passenger-phone");
        const errorMsg = document.querySelector("#phone-error");
        const successMsg = document.querySelector(".phone-success");

        if (!input || !input.value.trim()) {
            hidePhoneMessages();
            return false;
        }

        if (iti && iti.isValidNumber()) {
            input.classList.remove('error');
            input.classList.add('valid');
            errorMsg.style.display = 'none';
            successMsg.style.display = 'block';
            updateHiddenPhoneField();
            return true;
        } else {
            input.classList.add('error');
            input.classList.remove('valid');
            successMsg.style.display = 'none';

            const errorCode = iti ? iti.getValidationError() : 0;
            const errorMessages = {
                0: 'Nevažeći broj telefona',
                1: 'Nevažeći kod države',
                2: 'Broj telefona je prekratak',
                3: 'Broj telefona je predug',
                4: 'Nevažeći broj telefona',
                5: 'Nevažeći broj telefona'
            };

            errorMsg.textContent = errorMessages[errorCode] || 'Nevažeći broj telefona';
            errorMsg.style.display = 'block';
            return false;
        }
    }

    function updateHiddenPhoneField() {
        const hiddenField = document.querySelector("#passenger-phone-full");
        const countryField = document.querySelector("#passenger-phone-country");
        const dialcodeField = document.querySelector("#passenger-phone-dialcode");

        if (iti && iti.isValidNumber()) {
            const e164Number = iti.getNumber();
            const countryData = iti.getSelectedCountryData();

            hiddenField.value = e164Number;
            countryField.value = countryData.iso2;
            dialcodeField.value = '+' + countryData.dialCode;
        } else {
            hiddenField.value = '';
            countryField.value = '';
            dialcodeField.value = '';
        }
    }

    function hidePhoneMessages() {
        const input = document.querySelector("#passenger-phone");
        const errorMsg = document.querySelector("#phone-error");
        const successMsg = document.querySelector(".phone-success");

        if (input) input.classList.remove('error', 'valid');
        if (errorMsg) errorMsg.style.display = 'none';
        if (successMsg) successMsg.style.display = 'none';
    }

    // Function to get full international phone number
    function getFullPhoneNumber() {
        if (iti && iti.isValidNumber()) {
            return iti.getNumber(); // Returns E.164 format
        }
        return $('#passenger-phone-full').val() || '';
    }

    // Handle complete payment button click (Stripe payment initiation)
    $('#complete-payment-btn').on('click', function() {
        if (!stripe || !cardElement) {
            $('#cityride-message').text('Stripe nije pravilno učitan. Molimo osvježite stranicu.').css('color', 'red').fadeIn();
            return;
        }

        const btn = $(this);
        btn.prop('disabled', true).text('Obrađujem plaćanje...');
        $('#stripe-card-errors').text('');

        const passengerName = $('#passenger-name').val().trim();
        const passengerPhone = getFullPhoneNumber();
        const passengerEmail = $('#passenger-email').val()?.trim() || '';

        if (!passengerName || !passengerPhone) {
            $('#cityride-message').text('Molimo unesite Vaše ime i telefon.').css('color', 'red').fadeIn();
            btn.prop('disabled', false).text('Pozovi taxi');
            return;
        }

        // Validate phone number using intl-tel-input
        if (!validatePhoneNumber()) {
            $('#cityride-message').text('Molimo unesite važeći broj telefona.').css('color', 'red').fadeIn();
            btn.prop('disabled', false).text('Pozovi taxi');
            return;
        }

        if (!currentBookingData.total_price) {
            $('#cityride-message').text('Prvo izračunajte cijenu vožnje.').css('color', 'red').fadeIn();
            btn.prop('disabled', false).text('Pozovi taxi');
            return;
        }
        
        // Player ID se uzima direktno iz skrivenog polja (popunjeno gore)
        const finalOneSignalPlayerId = $('#passenger_onesignal_id').val();
        console.log("frontend-script.js: Slanje Passenger OneSignal ID-a u AJAX (create_payment_intent):", finalOneSignalPlayerId);

        const paymentData = {
            action: 'cityride_create_payment_intent',
            nonce: cityride_frontend_ajax.nonce,
            amount: Math.round(currentBookingData.total_price * 100),
            address_from: currentBookingData.address_from,
            address_to: currentBookingData.address_to,
            distance_km: currentBookingData.distance_km,
            passenger_name: passengerName,
            passenger_phone: passengerPhone,
            passenger_phone_country: $('#passenger-phone-country').val(),
            passenger_phone_dialcode: $('#passenger-phone-dialcode').val(),
            passenger_email: passengerEmail,
            passenger_onesignal_id: finalOneSignalPlayerId // Proslijeđuje Player ID backendu
        };

        $.ajax({
            url: cityride_frontend_ajax.ajax_url,
            type: 'POST',
            data: paymentData,
            success: function(response) {
                if (response.success) {
                    stripe.confirmCardPayment(response.data.client_secret, {
                        payment_method: {
                            card: cardElement,
                            billing_details: {
                                name: paymentData.passenger_name,
                                phone: paymentData.passenger_phone,
                                email: paymentData.passenger_email
                            }
                        }
                    }).then(function(result) {
                        if (result.error) {
                            $('#stripe-card-errors').text(result.error.message);
                            btn.prop('disabled', false).text('Pozovi taxi');
                        } else {
                            saveBooking(result.paymentIntent.id, paymentData);
                        }
                    });
                } else {
                    $('#cityride-message').text('Greška pri kreiranju plaćanja: ' + response.data).css('color', 'red').fadeIn();
                    btn.prop('disabled', false).text('Pozovi taxi');
                }
            },
            error: function() {
                $('#cityride-message').text('Greška pri povezivanju s serverom.').css('color', 'red').fadeIn();
            },
            complete: function() {
                btn.prop('disabled', false).text('Pozovi taxi');
            }
        });
    });

    /**
     * Saves the booking details to the database after successful Stripe payment.
     * @param {string} paymentIntentId - The Stripe PaymentIntent ID.
     * @param {object} paymentData - Data related to the payment and booking.
     */
    function saveBooking(paymentIntentId, paymentData) {
        // Player ID se uzima direktno iz skrivenog polja (popunjeno na početku)
        const finalOneSignalPlayerId = $('#passenger_onesignal_id').val();
        console.log("frontend-script.js: Slanje Passenger OneSignal ID-a u AJAX (save_booking):", finalOneSignalPlayerId);

        $.ajax({
            url: cityride_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cityride_save_booking',
                nonce: cityride_frontend_ajax.nonce,
                payment_intent_id: paymentIntentId,
                address_from: currentBookingData.address_from,
                address_to: currentBookingData.address_to,
                distance_km: currentBookingData.distance_km,
                total_price: currentBookingData.total_price,
                passenger_name: paymentData.passenger_name,
                passenger_phone: paymentData.passenger_phone,
                passenger_phone_country: paymentData.passenger_phone_country,
                passenger_phone_dialcode: paymentData.passenger_phone_dialcode,
                passenger_email: paymentData.passenger_email,
                passenger_onesignal_id: finalOneSignalPlayerId, // Proslijeđuje Player ID
                discount_code: discountData ? discountData.code : '',
                discount_amount: discountData ? discountData.discount_amount : 0,
                original_price: discountData ? discountData.original_price : currentBookingData.total_price,
                final_price: discountData ? discountData.final_price : currentBookingData.total_price
            },
            success: function(response) {
                if (response.success) {
                    $('#booking-id').text('#' + response.data.booking_id);
                    $('#cityride-booking-form').hide();
                    $('#payment-section').hide();
                    $('#booking-success').fadeIn();

                    $('html, body').animate({
                        scrollTop: $('#booking-success').offset().top - 100
                    }, 500);
                } else {
                    $('#cityride-message').text('Greška pri spremanju rezervacije: ' + response.data).css('color', 'red').fadeIn();
                }
            },
            error: function() {
                $('#cityride-message').text('Greška pri spremanju rezervacije.').css('color', 'red').fadeIn();
            },
            complete: function() {
                $('#complete-payment-btn').prop('disabled', false).text('Pozovi taxi');
            }
        });
    }

    /**
     * Initializes and displays the Mapbox route.
     * @param {object} routeGeometry - GeoJSON geometry object for the route.
     * @param {object} fromCoords - {lng, lat} for the origin.
     * @param {object} toCoords - {lng, lat} for the destination.
     */
    function showRoute(routeGeometry, fromCoords, toCoords) {
        if (!cityride_frontend_ajax.mapbox_api_key) {
            console.error('Mapbox API key is not defined.');
            return;
        }

        const mapContainer = document.getElementById('cityride-map');

        // Check if map instance already exists and remove it to prevent duplicates
        if (mapContainer._mapboxMap) {
            mapContainer._mapboxMap.remove();
        }

        mapboxgl.accessToken = cityride_frontend_ajax.mapbox_api_key;

        const map = new mapboxgl.Map({
            container: 'cityride-map',
            style: 'mapbox://styles/mapbox/streets-v11',
            center: [fromCoords.lng, fromCoords.lat],
            zoom: 12
        });

        // Store the map instance on the container for easy access
        mapContainer._mapboxMap = map;

        map.on('load', function() {
            map.addSource('route', {
                type: 'geojson',
                data: {
                    type: 'Feature',
                    properties: {},
                    geometry: routeGeometry
                }
            });

            map.addLayer({
                id: 'route',
                type: 'line',
                source: 'route',
                layout: {
                    'line-join': 'round',
                    'line-cap': 'round'
                },
                paint: {
                    'line-color': '#3887be',
                    'line-width': 5,
                    'line-opacity': 0.75
                }
            });

            new mapboxgl.Marker({ color: 'green' })
                .setLngLat([fromCoords.lng, fromCoords.lat])
                .setPopup(new mapboxgl.Popup().setHTML('<strong>Polazak</strong><br>' + currentBookingData.address_from))
                .addTo(map);

            new mapboxgl.Marker({ color: 'red' })
                .setLngLat([toCoords.lng, toCoords.lat])
                .setPopup(new mapboxgl.Popup().setHTML('<strong>Odredište</strong><br>' + currentBookingData.address_to))
                .addTo(map);

            const bounds = new mapboxgl.LngLatBounds();
            bounds.extend([fromCoords.lng, fromCoords.lat]);
            bounds.extend([toCoords.lng, toCoords.lat]);
            map.fitBounds(bounds, { padding: 50 });
        });
    }

    // Listen for changes on the Stripe card element to display errors
    if (cardElement) {
        cardElement.on('change', function(event) {
            const displayError = document.getElementById('stripe-card-errors');
            displayError.textContent = event.error ? event.error.message : '';
        });
    }

    /**
     * Discount Code Handling
     */
    let discountApplied = false;
    let discountData = null;

    const discountSection = document.getElementById('discount-code-section');
    const discountInput = document.getElementById('discount-code-input');
    const applyDiscountBtn = document.getElementById('apply-discount-btn');
    const removeDiscountBtn = document.getElementById('remove-discount-btn');
    const discountMessage = document.getElementById('discount-message');
    const discountDetails = document.getElementById('discount-details');

    // Apply discount code
    if (applyDiscountBtn) {
        applyDiscountBtn.addEventListener('click', function() {
            const code = discountInput.value.trim().toUpperCase();

            if (!code) {
                showDiscountMessage('Molimo unesite kod popusta.', 'error');
                return;
            }

            // Get the current price (after surcharges)
            const currentPrice = parseFloat(document.getElementById('calculated_total_price').value);

            // Show loading
            applyDiscountBtn.disabled = true;
            applyDiscountBtn.textContent = 'Provjeravam...';

            jQuery.ajax({
                url: cityride_frontend_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cityride_validate_discount_code',
                    nonce: cityride_frontend_ajax.nonce,
                    code: code,
                    order_amount: currentPrice
                },
                success: function(response) {
                    applyDiscountBtn.disabled = false;
                    applyDiscountBtn.textContent = 'Primijeni';

                    if (response.success) {
                        discountApplied = true;
                        discountData = response.data;

                        // Update hidden fields
                        document.getElementById('calculated_stripe_amount').value = discountData.stripe_amount;
                        document.getElementById('discount_code_applied').value = discountData.code;
                        document.getElementById('discount_amount_applied').value = discountData.discount_amount;

                        // Update UI
                        document.getElementById('discount-original-price').textContent = discountData.original_price.toFixed(2) + ' BAM';
                        document.getElementById('discount-code-used').textContent = discountData.code;
                        document.getElementById('discount-amount').textContent = '-' + discountData.discount_amount.toFixed(2) + ' BAM';
                        document.getElementById('discount-final-price').textContent = discountData.final_price.toFixed(2) + ' BAM';

                        // Update main price display
                        document.getElementById('total-price').textContent = discountData.final_price.toFixed(2);

                        // Show discount details, hide input
                        discountInput.style.display = 'none';
                        applyDiscountBtn.style.display = 'none';
                        discountDetails.style.display = 'block';

                        showDiscountMessage(discountData.message, 'success');
                    } else {
                        showDiscountMessage(response.data, 'error');
                    }
                },
                error: function() {
                    applyDiscountBtn.disabled = false;
                    applyDiscountBtn.textContent = 'Primijeni';
                    showDiscountMessage('Greška pri validaciji koda. Pokušajte ponovo.', 'error');
                }
            });
        });
    }

    // Remove discount code
    if (removeDiscountBtn) {
        removeDiscountBtn.addEventListener('click', function() {
            discountApplied = false;
            discountData = null;

            // Reset hidden fields
            const originalPrice = parseFloat(discountDetails.querySelector('#discount-original-price').textContent);
            const originalStripeAmount = Math.round(originalPrice * 100);

            document.getElementById('calculated_stripe_amount').value = originalStripeAmount;
            document.getElementById('discount_code_applied').value = '';
            document.getElementById('discount_amount_applied').value = '0';

            // Reset main price display
            document.getElementById('total-price').textContent = originalPrice.toFixed(2);

            // Show input, hide details
            discountInput.style.display = 'block';
            applyDiscountBtn.style.display = 'inline-block';
            discountDetails.style.display = 'none';
            discountInput.value = '';

            showDiscountMessage('', '');
        });
    }

    // Helper function to show discount messages
    function showDiscountMessage(message, type) {
        if (!message) {
            discountMessage.textContent = '';
            discountMessage.style.display = 'none';
            return;
        }

        discountMessage.textContent = message;
        discountMessage.style.display = 'block';

        if (type === 'success') {
            discountMessage.style.color = '#4CAF50';
        } else if (type === 'error') {
            discountMessage.style.color = '#f44336';
        }
    }

    // Show discount section when price is calculated
    document.getElementById('calculate-price-btn').addEventListener('click', function() {
        // Reset discount when recalculating
        if (discountApplied) {
            removeDiscountBtn.click();
        }
    });
});