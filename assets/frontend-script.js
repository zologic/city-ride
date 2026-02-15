jQuery(document).ready(function($) {
    let stripe;
    let elements;
    let cardElement;
    let cardMounted = false;
    let currentBookingData = {};
    let oneSignalPlayerId = ''; // Variable to store OneSignal Player ID

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

                    $('#distance-display').text(data.distance_km);
                    $('#start-tariff-display').text(data.start_tariff.toFixed(2));
                    $('#price-per-km-display').text(data.price_per_km.toFixed(2));
                    $('#total-price').text(data.total_price.toFixed(2));
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
        const passengerPhone = $('#passenger-phone').val().trim();
        const passengerEmail = $('#passenger-email').val()?.trim() || '';

        if (!passengerName || !passengerPhone) {
            $('#cityride-message').text('Molimo unesite Vaše ime i telefon.').css('color', 'red').fadeIn();
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
                passenger_email: paymentData.passenger_email,
                passenger_onesignal_id: finalOneSignalPlayerId // Proslijeđuje Player ID
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
});