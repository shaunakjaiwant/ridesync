// =============================================
// RIDESYNC - JAVASCRIPT
// =============================================

// ---------- MOBILE NAV TOGGLE ----------
const navToggle = document.getElementById('navToggle');
const navLinks = document.getElementById('navLinks');

if (navToggle) {
    navToggle.addEventListener('click', function () {
        navLinks.classList.toggle('active');
    });
}

if (navLinks) {
    navLinks.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            navLinks.classList.remove('active');
        });
    });
}

// ---------- FORM VALIDATION ----------
document.addEventListener('DOMContentLoaded', function () {
    initEmailValidation();
    initFareSliders();
    initRealtimeEvents();
    initAdminCommandCenter();
    initDriverVerificationIntelligence();
    initLiveRideStatus();
    initDriverLiveStatus();
    initDriverLocationCapture();
    initTripShare();

    // --- Registration Form ---
    var regForm = document.querySelector('form[action*="register_action"]');
    if (regForm) {
        regForm.addEventListener('submit', function (e) {
            var name = regForm.querySelector('[name="name"]');
            var email = regForm.querySelector('[name="email"]');
            var password = regForm.querySelector('[name="password"]');
            var confirmPw = regForm.querySelector('[name="confirm_password"]');
            var errors = [];

            if (name && name.value.trim().length < 2) {
                errors.push('Name must be at least 2 characters.');
            }

            if (email && !isValidEmail(email.value)) {
                errors.push('Please enter a valid email address.');
            }

            if (password && password.value.length < 8) {
                errors.push('Password must be at least 8 characters.');
            }

            if (confirmPw && password && confirmPw.value !== password.value) {
                errors.push('Passwords do not match.');
            }

            if (errors.length > 0) {
                e.preventDefault();
                showFormErrors(regForm, errors);
            }
        });
    }

    // --- Login Form ---
    var loginForm = document.querySelector('form[action*="login_action"]');
    if (loginForm) {
        loginForm.addEventListener('submit', function (e) {
            var email = loginForm.querySelector('[name="email"]');
            var password = loginForm.querySelector('[name="password"]');
            var errors = [];

            if (email && !isValidEmail(email.value)) {
                errors.push('Please enter a valid email.');
            }
            if (password && password.value.length === 0) {
                errors.push('Password cannot be empty.');
            }

            if (errors.length > 0) {
                e.preventDefault();
                showFormErrors(loginForm, errors);
            }
        });
    }

    // --- Driver Login / Registration / Profile Forms ---
    var driverForms = document.querySelectorAll('form[action*="driver_auth_action"], form[action*="driver_account_action"]');
    driverForms.forEach(function (form) {
        var actionType = form.querySelector('[name="action_type"]');
        if (!actionType || ['login', 'register', 'update_profile'].indexOf(actionType.value) === -1) {
            return;
        }

        form.addEventListener('submit', function (e) {
            var email = form.querySelector('[name="email"]');
            var password = form.querySelector('[name="password"]');
            var confirmPw = form.querySelector('[name="confirm_password"]');
            var phone = form.querySelector('[name="phone"]');
            var license = form.querySelector('[name="license_number"]');
            var vehicleNumber = form.querySelector('[name="vehicle_number"]');
            var seats = form.querySelector('[name="seating_capacity"]');
            var errors = [];

            if (email && !isValidEmail(email.value)) {
                errors.push('Please enter a valid driver email.');
            }

            if (password && password.value.length < 8) {
                errors.push('Password must be at least 8 characters.');
            }

            if (confirmPw && password && confirmPw.value !== password.value) {
                errors.push('Passwords do not match.');
            }

            if (phone && !/^[0-9+\- ]{8,20}$/.test(phone.value.trim())) {
                errors.push('Please enter a valid phone number.');
            }

            if (license && license.value.trim().length < 4) {
                errors.push('License number is too short.');
            }

            if (vehicleNumber && !/^[A-Za-z0-9 -]{4,40}$/.test(vehicleNumber.value.trim())) {
                errors.push('Vehicle number can use only letters, numbers, spaces, and hyphens.');
            }

            if (seats && (parseInt(seats.value) < 1 || parseInt(seats.value) > 8)) {
                errors.push('Passenger seats must be between 1 and 8.');
            }

            if (errors.length > 0) {
                e.preventDefault();
                showFormErrors(form, errors);
            }
        });
    });

    // --- Post Ride Form ---
    var rideForm = document.querySelector('form[action*="post_ride_action"]');
    if (rideForm) {
        rideForm.addEventListener('submit', function (e) {
            var origin = rideForm.querySelector('[name="origin"]');
            var destination = rideForm.querySelector('[name="destination"]');
            var travelDate = rideForm.querySelector('[name="travel_date"]');
            var seats = rideForm.querySelector('[name="seats_available"]');
            var errors = [];

            if (origin && origin.value.trim().length < 2) {
                errors.push('Origin is too short.');
            }
            if (destination && destination.value.trim().length < 2) {
                errors.push('Destination is too short.');
            }
            if (origin && destination && origin.value.trim().toLowerCase() === destination.value.trim().toLowerCase()) {
                errors.push('Origin and destination cannot be the same.');
            }
            if (travelDate) {
                var selected = new Date(travelDate.value);
                var today = new Date();
                today.setHours(0, 0, 0, 0);
                if (selected < today) {
                    errors.push('Travel date cannot be in the past.');
                }
            }
            if (seats && (parseInt(seats.value) < 1 || parseInt(seats.value) > 5)) {
                errors.push('Seats must be between 1 and 5.');
            }

            if (errors.length > 0) {
                e.preventDefault();
                showFormErrors(rideForm, errors);
            }
        });
    }

    // --- Auto-dismiss alerts after 5 seconds ---
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function () {
                alert.remove();
            }, 500);
        }, 5000);
    });

    // --- Set min date on date inputs to today ---
    var dateInputs = document.querySelectorAll('input[type="date"]');
    dateInputs.forEach(function (input) {
        var today = new Date().toISOString().split('T')[0];
        input.setAttribute('min', today);
    });

    initSubmitGuards();
});

// ---------- HELPER FUNCTIONS ----------

function isValidEmail(email) {
    email = (email || '').trim();
    return /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/.test(email);
}

function initEmailValidation() {
    var emailInputs = document.querySelectorAll('input[type="email"], input[name="email"]');

    emailInputs.forEach(function (input) {
        var message = document.createElement('div');
        message.className = 'email-validation-message';
        input.insertAdjacentElement('afterend', message);

        function updateEmailState() {
            var value = input.value.trim();
            input.classList.remove('email-valid', 'email-invalid');
            message.className = 'email-validation-message';

            if (value === '') {
                message.textContent = '';
                return;
            }

            if (isValidEmail(value)) {
                input.classList.add('email-valid');
                message.classList.add('is-valid');
                message.textContent = 'Valid email address';
            } else {
                input.classList.add('email-invalid');
                message.classList.add('is-invalid');
                message.textContent = 'Invalid email address';
            }
        }

        input.addEventListener('input', updateEmailState);
        input.addEventListener('blur', updateEmailState);
        updateEmailState();
    });
}

function initFareSliders() {
    var sliders = document.querySelectorAll('[data-fare-slider]');

    sliders.forEach(function (slider) {
        var card = slider.closest('.fare-slider-card');
        if (!card) return;

        var output = card.querySelector('[data-fare-slider-output]');
        var savings = card.querySelector('[data-fare-slider-savings]');
        var total = parseFloat(slider.dataset.total || '0');
        var solo = parseFloat(slider.dataset.solo || '0');
        var distance = parseFloat(slider.dataset.distance || '0');
        var rate = parseFloat(slider.dataset.rate || '25.6');
        var timeRate = parseFloat(slider.dataset.timeRate || '1.25');
        var seed = parseInt(slider.dataset.seed || '0', 10) || 0;

        function formatINR(value) {
            return new Intl.NumberFormat('en-IN', {
                style: 'currency',
                currency: 'INR',
                maximumFractionDigits: 0
            }).format(Math.max(0, Math.round(value)));
        }

        function round1(value) {
            return Math.round(value * 10) / 10;
        }

        function syncedFareEstimate(riders) {
            if (!isFinite(distance) || distance <= 0 || !isFinite(rate) || rate <= 0) {
                return null;
            }

            var directDistance = round1(Math.max(0.1, Math.min(1000, distance)));
            var detourKm = riders > 1
                ? round1(Math.min(5.8, Math.max(0.4, ((seed % 24) / 10) + ((riders - 1) * 0.45))))
                : 0;
            var overlapRatio = riders > 1 ? Math.min(0.88, 0.42 + (riders * 0.09)) : 0;
            var sharedDistance = round1(directDistance * overlapRatio);
            var personalDistance = round1(Math.max(0, directDistance - sharedDistance));
            var timeAddedMinutes = riders > 1
                ? Math.ceil((detourKm / 18) * 60 + Math.max(0, riders - 1) * 2)
                : 0;
            var baseRouteFare = Math.ceil(directDistance * rate);
            var detourTotalCost = detourKm * rate;
            var timeTotalCost = timeAddedMinutes * timeRate;
            var baseShare = Math.ceil(baseRouteFare / riders);
            var detourCharge = Math.ceil(riders > 1 ? detourTotalCost / riders : 0);
            var timeAdjustment = Math.ceil(riders > 1 ? timeTotalCost / riders : 0);
            var estimate = Math.max(1, baseShare + detourCharge + timeAdjustment);

            return {
                estimate: estimate,
                solo: baseRouteFare
            };
        }

        function updateFarePreview() {
            var riders = Math.max(1, parseInt(slider.value, 10) || 1);
            var synced = syncedFareEstimate(riders);
            var estimate = synced
                ? synced.estimate
                : Math.max(1, Math.ceil(total / riders));
            var soloBasis = synced ? synced.solo : solo;
            var savedPercent = soloBasis > 0 ? Math.max(0, Math.round(((soloBasis - estimate) / soloBasis) * 100)) : 0;

            if (output) output.textContent = formatINR(estimate);
            if (savings) savings.textContent = savedPercent + '% saved with ' + riders + ' rider' + (riders === 1 ? '' : 's');
        }

        slider.addEventListener('input', updateFarePreview);
        updateFarePreview();
    });
}

function initLiveRideStatus() {
    var liveCards = document.querySelectorAll('[data-live-ride]');
    if (!liveCards.length || !window.fetch) return;

    liveCards.forEach(function (card) {
        var rideId = card.dataset.liveRide;
        if (!rideId) return;

        function applyState(data) {
            if (!data || !data.ok) return;

            var label = card.querySelector('[data-live-status-label]');
            var note = card.querySelector('[data-live-note]');
            var accepted = card.querySelector('[data-live-accepted]');
            var pending = card.querySelector('[data-live-pending]');
            var seats = card.querySelector('[data-live-seats]');

            if (label) label.textContent = data.live_status_label || 'Searching';
            if (note) {
                var driverText = data.driver_name ? ' Driver: ' + data.driver_name + '.' : '';
                var etaText = data.eta_minutes ? ' ETA: ' + data.eta_minutes + ' min.' : '';
                note.textContent = (data.note || 'Ride status updated.') + driverText + etaText;
            }
            if (accepted) accepted.textContent = data.accepted_count;
            if (pending) pending.textContent = data.pending_count;
            if (seats) seats.textContent = data.seats_available;

            if (Array.isArray(data.steps)) {
                data.steps.forEach(function (step) {
                    var node = card.querySelector('[data-step="' + step.key + '"]');
                    if (!node) return;
                    node.classList.remove('timeline-done', 'timeline-current', 'timeline-upcoming');
                    node.classList.add('timeline-' + step.state);
                });
            }
        }

        function poll() {
            fetch('/ridesync/api/ride_status.php?ride_id=' + encodeURIComponent(rideId), {
                credentials: 'same-origin'
            })
                .then(function (response) { return response.json(); })
                .then(applyState)
                .catch(function () {});
        }

        poll();
        setInterval(poll, 8000);
    });
}

function initDriverLiveStatus() {
    var dashboard = document.querySelector('[data-driver-live]');
    if (!dashboard || !window.fetch) return;

    function setText(selector, value) {
        var node = dashboard.querySelector(selector);
        if (node) node.textContent = value;
    }

    function poll() {
        fetch('/ridesync/api/driver_state.php', { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.ok) return;
                setText('[data-driver-pending]', data.pending_requests);
                setText('[data-driver-today]', '\u20b9' + data.today_earnings);
                setText('[data-driver-week]', '\u20b9' + data.week_earnings);
                setText('[data-driver-trips]', data.completed_trips);
            })
            .catch(function () {});
    }

    poll();
    setInterval(poll, 8000);
}

function initDriverLocationCapture() {
    var forms = document.querySelectorAll('[data-driver-availability-form]');
    if (!forms.length || !navigator.geolocation) return;

    forms.forEach(function (form) {
        var status = form.querySelector('[name="status"]');
        if (!status || status.value !== 'online') return;

        navigator.geolocation.getCurrentPosition(function (position) {
            var lat = form.querySelector('[name="current_lat"]');
            var lng = form.querySelector('[name="current_lng"]');
            if (lat) lat.value = position.coords.latitude.toFixed(7);
            if (lng) lng.value = position.coords.longitude.toFixed(7);
        }, function () {}, {
            enableHighAccuracy: true,
            timeout: 8000
        });
    });
}

function initTripShare() {
    var buttons = document.querySelectorAll('[data-share-trip]');
    if (!buttons.length) return;

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            var shareData = {
                title: button.dataset.shareTitle || 'RideSync trip',
                text: button.dataset.shareText || 'RideSync trip details',
                url: window.location.href
            };

            if (navigator.share) {
                navigator.share(shareData).catch(function () {});
                return;
            }

            if (navigator.clipboard) {
                navigator.clipboard.writeText(shareData.text + ' ' + shareData.url).then(function () {
                    var original = button.textContent;
                    button.textContent = 'Link Copied';
                    setTimeout(function () {
                        button.textContent = original;
                    }, 1800);
                }).catch(function () {});
            }
        });
    });
}

function initRealtimeEvents() {
    var notificationLink = document.querySelector('a[href*="notifications.php"]');
    if (!notificationLink || !window.EventSource) return;

    var requestCounter = document.querySelector('[data-driver-pending]');
    var source = new EventSource('/ridesync/api/events.php');

    function setBadge(count) {
        var badge = notificationLink.querySelector('.nav-badge');
        count = Math.max(0, parseInt(count, 10) || 0);

        if (count === 0) {
            if (badge) badge.remove();
            return;
        }

        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'nav-badge';
            notificationLink.appendChild(badge);
        }

        badge.textContent = count > 99 ? '99' : String(count);
    }

    source.addEventListener('ridesync', function (event) {
        try {
            var data = JSON.parse(event.data || '{}');
            if (!data || !data.ok) return;
            setBadge(data.unread_notifications);

            if (requestCounter && typeof data.pending_driver_requests !== 'undefined') {
                requestCounter.textContent = data.pending_driver_requests;
            }
        } catch (error) {}
    });

    source.onerror = function () {};
}

function initAdminCommandCenter() {
    var center = document.querySelector('[data-admin-command-center]');
    if (!center) return;

    initAdminDrawer();
    initAdminTableFilters();
    initAdminKeyboardSearch();
    initAdminMap();
    initAdminRealtime();
}

function initAdminDrawer() {
    var drawer = document.getElementById('adminDrawer');
    if (!drawer) return;

    var title = drawer.querySelector('[data-admin-drawer-title]');
    var kicker = drawer.querySelector('[data-admin-drawer-kicker]');
    var body = drawer.querySelector('[data-admin-drawer-body]');
    var closeButton = drawer.querySelector('[data-admin-drawer-close]');

    function closeDrawer() {
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
    }

    function appendFields(fields) {
        var list = document.createElement('dl');
        list.className = 'admin-drawer-fields';

        Object.keys(fields || {}).forEach(function (key) {
            var row = document.createElement('div');
            var term = document.createElement('dt');
            var value = document.createElement('dd');
            term.textContent = key.replace(/_/g, ' ');
            value.textContent = fields[key] === null || typeof fields[key] === 'undefined' ? 'Not available' : String(fields[key]);
            row.appendChild(term);
            row.appendChild(value);
            list.appendChild(row);
        });

        body.appendChild(list);
    }

    function appendLinks(links) {
        if (!Array.isArray(links) || links.length === 0) return;

        var actions = document.createElement('div');
        actions.className = 'admin-drawer-actions';

        links.forEach(function (link) {
            if (!link || !link.href || !link.label) return;
            var anchor = document.createElement('a');
            anchor.className = 'btn btn-primary btn-sm';
            anchor.href = link.href;
            anchor.textContent = link.label;
            actions.appendChild(anchor);
        });

        body.appendChild(actions);
    }

    document.querySelectorAll('.admin-inspect-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            var payload = {};
            try {
                payload = JSON.parse(button.dataset.adminDrawer || '{}');
            } catch (error) {}

            if (kicker) kicker.textContent = payload.kicker || 'Inspect';
            if (title) title.textContent = payload.title || 'Details';
            if (body) {
                body.innerHTML = '';
                appendFields(payload.fields || {});
                appendLinks(payload.links || []);
            }

            drawer.classList.add('is-open');
            drawer.setAttribute('aria-hidden', 'false');
        });
    });

    if (closeButton) closeButton.addEventListener('click', closeDrawer);
    drawer.addEventListener('click', function (event) {
        if (event.target === drawer) closeDrawer();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeDrawer();
    });
}

function initAdminTableFilters() {
    var controls = document.querySelectorAll('[data-admin-table-search], [data-admin-table-status]');
    if (!controls.length) return;

    function applyFilter(tableId) {
        var table = document.getElementById(tableId);
        if (!table) return;

        var search = document.querySelector('[data-admin-table-search="' + tableId + '"]');
        var status = document.querySelector('[data-admin-table-status="' + tableId + '"]');
        var query = search ? search.value.trim().toLowerCase() : '';
        var selectedStatus = status ? status.value.trim().toLowerCase() : '';

        table.querySelectorAll('tbody tr').forEach(function (row) {
            var haystack = (row.dataset.search || row.textContent || '').toLowerCase();
            var rowStatus = (row.dataset.status || '').toLowerCase();
            var matchesQuery = query === '' || haystack.indexOf(query) !== -1;
            var matchesStatus = selectedStatus === '' || rowStatus.indexOf(selectedStatus) !== -1;
            row.hidden = !(matchesQuery && matchesStatus);
        });
    }

    controls.forEach(function (control) {
        var tableId = control.dataset.adminTableSearch || control.dataset.adminTableStatus;
        control.addEventListener('input', function () { applyFilter(tableId); });
        control.addEventListener('change', function () { applyFilter(tableId); });
        applyFilter(tableId);
    });
}

function initAdminKeyboardSearch() {
    var input = document.querySelector('[data-admin-global-search]');
    if (!input) return;

    document.addEventListener('keydown', function (event) {
        var isShortcut = (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k';
        if (!isShortcut) return;
        event.preventDefault();
        input.focus();
        input.select();
    });
}

function initAdminMap() {
    var canvas = document.getElementById('adminLiveMap');
    if (!canvas) return;
    if (!window.L) {
        canvas.classList.add('map-unavailable');
        canvas.textContent = 'Operational map is temporarily unavailable.';
        return;
    }

    var payload = window.RideSyncAdminMap || {};
    var points = []
        .concat(payload.drivers || [])
        .concat(payload.rides || [])
        .concat(payload.demand || []);

    var map = L.map(canvas, {
        zoomControl: true,
        scrollWheelZoom: false
    }).setView([12.8698, 74.8430], 10);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    var markers = [];
    points.forEach(function (point) {
        if (!isFinite(point.lat) || !isFinite(point.lng)) return;
        var marker = L.marker([point.lat, point.lng], {
            icon: L.divIcon({
                className: 'admin-map-marker admin-map-marker-' + point.type,
                html: '<span></span>',
                iconSize: [18, 18],
                iconAnchor: [9, 9]
            })
        }).addTo(map);
        marker.bindPopup('<strong>' + escapeHtml(point.name || 'Signal') + '</strong><br>' + escapeHtml(point.status || point.type || 'Active'));
        markers.push(marker);
    });

    if (markers.length > 0) {
        var group = L.featureGroup(markers);
        map.fitBounds(group.getBounds().pad(0.18), { maxZoom: 13 });
    } else {
        L.circleMarker([12.8698, 74.8430], {
            radius: 9,
            color: '#4aa3ff',
            fillColor: '#36d399',
            fillOpacity: 0.55
        }).addTo(map).bindPopup('Dakshina Kannada operating region');
    }

    setTimeout(function () {
        map.invalidateSize();
    }, 250);
}

function initAdminRealtime() {
    if (!window.EventSource || !document.querySelector('[data-admin-command-center]')) return;

    var source = new EventSource('/ridesync/api/admin_events.php');
    var lastSignature = '';

    function updateMetrics(metrics) {
        Object.keys(metrics || {}).forEach(function (key) {
            document.querySelectorAll('[data-admin-metric="' + key + '"]').forEach(function (node) {
                node.textContent = metrics[key];
            });
        });
    }

    function prependFeed(latest) {
        var feed = document.querySelector('[data-admin-feed]');
        if (!feed || !latest || !latest.title) return;

        var signature = latest.title + '|' + latest.detail + '|' + latest.created_at;
        if (signature === lastSignature) return;
        lastSignature = signature;

        var item = document.createElement('div');
        item.className = 'admin-feed-item is-live';

        var dot = document.createElement('span');
        dot.className = 'admin-feed-dot badge-open';
        var content = document.createElement('div');
        var title = document.createElement('strong');
        var detail = document.createElement('p');
        var time = document.createElement('small');

        title.textContent = latest.title;
        detail.textContent = latest.detail || 'System activity updated';
        time.textContent = 'Live update';

        content.appendChild(title);
        content.appendChild(detail);
        content.appendChild(time);
        item.appendChild(dot);
        item.appendChild(content);
        feed.insertBefore(item, feed.firstChild);

        while (feed.children.length > 18) {
            feed.removeChild(feed.lastChild);
        }
    }

    source.addEventListener('admin', function (event) {
        try {
            var data = JSON.parse(event.data || '{}');
            if (!data || !data.ok) return;
            updateMetrics(data.metrics || {});
            prependFeed(data.latest || null);
        } catch (error) {}
    });

    source.onerror = function () {};
}

function initDriverVerificationIntelligence() {
    var panel = document.querySelector('[data-driver-verification]');
    if (!panel || !window.fetch) return;

    var driverId = panel.dataset.driverVerification;
    if (!driverId) return;

    function setBadge(selector, label, badgeClass) {
        var node = panel.querySelector(selector);
        if (!node) return;
        node.className = 'badge badge-' + (badgeClass || 'pending');
        node.textContent = label || 'Pending';
    }

    function applySession(session) {
        session = session || {};
        var score = panel.querySelector('[data-verification-score]');
        var title = panel.querySelector('[data-verification-title]');
        var reason = panel.querySelector('[data-verification-reason]');

        if (score) score.textContent = session.confidence_score || 0;
        if (title) title.textContent = session.status_label || 'Processing';
        if (reason && Array.isArray(session.reasons) && session.reasons.length) {
            reason.textContent = session.reasons[0];
        }

        setBadge('[data-verification-status]', session.status_label, session.badge_class);
        setBadge('[data-verification-risk]', session.risk_label, session.risk_badge_class);
        setBadge('[data-verification-stage]', session.progress_stage_label, 'pending');
    }

    function poll() {
        fetch('/ridesync/api/driver_verification_status.php?driver_id=' + encodeURIComponent(driverId), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.ok || !data.has_session) return;
                applySession(data.session);
            })
            .catch(function () {});
    }

    if (window.EventSource) {
        var source = new EventSource('/ridesync/api/driver_verification_events.php?driver_id=' + encodeURIComponent(driverId));
        source.addEventListener('driver_verification', function (event) {
            try {
                var data = JSON.parse(event.data || '{}');
                if (data && data.ok) applySession(data.session);
            } catch (error) {}
        });
        source.onerror = function () {};
    }

    poll();
    setInterval(poll, 5000);
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function initSubmitGuards() {
    var forms = document.querySelectorAll('form');

    forms.forEach(function (form) {
        if (form.dataset.submitGuard === 'off') return;

        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented || form.dataset.submitting === 'true') {
                if (form.dataset.submitting === 'true') event.preventDefault();
                return;
            }

            form.dataset.submitting = 'true';
            setTimeout(function () {
                form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
                    button.dataset.originalText = button.value || button.textContent || '';
                    button.disabled = true;
                    button.classList.add('is-submitting');

                    if (button.tagName === 'INPUT') {
                        button.value = button.dataset.loadingLabel || 'Working...';
                    } else {
                        button.textContent = button.dataset.loadingLabel || 'Working...';
                    }
                });
            }, 0);
        });
    });
}

function showFormErrors(form, errors) {
    // Remove any existing client-side error box
    var existing = form.querySelector('.client-errors');
    if (existing) existing.remove();

    var errorDiv = document.createElement('div');
    errorDiv.className = 'alert alert-error client-errors';
    errorDiv.innerHTML = errors.map(function (err) {
        return '<div>' + err + '</div>';
    }).join('');

    form.insertBefore(errorDiv, form.firstChild);

    // Scroll to error
    errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
