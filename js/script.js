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
    initNavigationPrefetch();
    initEmailValidation();
    initFareSliders();
    initRealtimeEvents();
    initAdminCommandCenter();
    initMobileActiveNavigation();
    initDriverVerificationIntelligence();
    initLiveRideStatus();
    initDriverLiveStatus();
    initDriverLocationCapture();
    initTripShare();
    initScrollableRegions();
    initResponsiveDataTables();
    initConfirmActions();
    initAdminPanelFilters();
    initSmartSearchSuggestions();

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

function initNavigationPrefetch() {
    var links = document.querySelectorAll('.nav-links a[href], .driver-nav-links a[href], .admin-side-nav a[href], .admin-section-tabs a[href], .nav-right .btn-user[href], .driver-action-row a[href], .quick-actions a[href]');
    if (!links.length || !document.head) {
        return;
    }

    var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    var isCoarsePointer = window.matchMedia && window.matchMedia('(hover: none), (pointer: coarse)').matches;
    var saveData = connection && connection.saveData;
    var slowConnection = connection && /^(slow-2g|2g)$/i.test(String(connection.effectiveType || ''));
    if (isCoarsePointer || saveData || slowConnection) {
        return;
    }

    var prefetched = new Set();
    var hoverTimers = new WeakMap();

    function canPrefetch(link) {
        if (!link || link.dataset.prefetch === 'off') {
            return null;
        }

        var url;
        try {
            url = new URL(link.href, window.location.href);
        } catch (error) {
            return null;
        }

        if (url.origin !== window.location.origin || url.href === window.location.href || url.hash) {
            return null;
        }

        if (!url.pathname.startsWith('/ridesync/pages/') && !url.pathname.endsWith('/ridesync/index.php')) {
            return null;
        }

        return url;
    }

    function prefetch(link) {
        var url = canPrefetch(link);
        if (!url || prefetched.has(url.href)) {
            return;
        }

        prefetched.add(url.href);
        var hint = document.createElement('link');
        hint.rel = 'prefetch';
        hint.as = 'document';
        hint.href = url.href;
        document.head.appendChild(hint);
    }

    links.forEach(function (link) {
        link.addEventListener('pointerenter', function () {
            var timer = window.setTimeout(function () { prefetch(link); }, 140);
            hoverTimers.set(link, timer);
        }, { passive: true });
        link.addEventListener('pointerleave', function () {
            var timer = hoverTimers.get(link);
            if (timer) window.clearTimeout(timer);
        }, { passive: true });
        link.addEventListener('focus', function () { prefetch(link); });
    });
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
                setText('[data-driver-active]', data.active_workload);
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
    if (!forms.length) return;

    forms.forEach(function (form) {
        var status = form.querySelector('[name="status"]');
        if (!status || status.value !== 'online') return;

        form.addEventListener('submit', function (event) {
            if (form.dataset.locationResolved === 'true' || !navigator.geolocation) {
                return;
            }

            event.preventDefault();
            var submitter = event.submitter || form.querySelector('button[type="submit"]');
            var originalLabel = submitter ? submitter.textContent : '';

            if (submitter) {
                submitter.disabled = true;
                submitter.textContent = 'Finding location...';
            }

            function continueSubmit() {
                form.dataset.locationResolved = 'true';
                if (submitter) {
                    submitter.disabled = false;
                    submitter.textContent = originalLabel;
                }

                if (typeof form.requestSubmit === 'function') {
                    if (submitter) {
                        form.requestSubmit(submitter);
                    } else {
                        form.requestSubmit();
                    }
                } else {
                    form.submit();
                }
            }

            navigator.geolocation.getCurrentPosition(function (position) {
                var lat = form.querySelector('[name="current_lat"]');
                var lng = form.querySelector('[name="current_lng"]');
                if (lat) lat.value = position.coords.latitude.toFixed(7);
                if (lng) lng.value = position.coords.longitude.toFixed(7);
                continueSubmit();
            }, continueSubmit, {
                enableHighAccuracy: true,
                timeout: 8000
            });
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
    window.addEventListener('pagehide', function () {
        source.close();
    }, { once: true });
}

function initAdminCommandCenter() {
    var center = document.querySelector('[data-admin-command-center]');
    if (!center) return;

    initAdminDrawer();
    initAdminTableFilters();
    initAdminKeyboardSearch();
    initAdminMap();
    initAdminRealtime();
    initAdminServicesMonitor();
}

function initMobileActiveNavigation() {
    if (!window.matchMedia('(max-width: 767px), (hover: none) and (max-width: 1024px)').matches) {
        return;
    }

    document.querySelectorAll('.admin-side-nav, .rider-app .nav-links, .driver-app:not(.admin-app) .driver-nav-links').forEach(function (nav) {
        var active = nav.querySelector('a.is-active');
        if (!active || typeof active.scrollIntoView !== 'function') return;

        window.requestAnimationFrame(function () {
            active.scrollIntoView({
                block: 'nearest',
                inline: 'center',
                behavior: 'auto'
            });
        });
    });
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

    document.addEventListener('click', function (event) {
        var button = event.target && event.target.closest ? event.target.closest('.admin-inspect-btn') : null;
        if (!button) return;
        if (!document.body.contains(button)) return;
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

function initAdminPanelFilters() {
    document.querySelectorAll('[data-admin-panel-search]').forEach(function (input) {
        var panelId = input.dataset.adminPanelSearch;
        var panel = document.getElementById(panelId);
        if (!panel) return;

        function applyFilter() {
            var query = input.value.trim().toLowerCase();
            panel.querySelectorAll('[data-search]').forEach(function (node) {
                var haystack = (node.dataset.search || node.textContent || '').toLowerCase();
                node.hidden = query !== '' && haystack.indexOf(query) === -1;
            });
        }

        input.addEventListener('input', applyFilter);
        input.addEventListener('change', applyFilter);
        applyFilter();
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

function initSmartSearchSuggestions() {
    if (!window.fetch) return;

    var fields = document.querySelectorAll('[data-search-context], [data-admin-table-search], [data-admin-panel-search], [data-admin-global-search]');
    if (!fields.length) return;

    var popup = document.createElement('div');
    popup.className = 'smart-search-suggestions';
    popup.setAttribute('role', 'listbox');
    popup.setAttribute('aria-label', 'Search suggestions');
    document.body.appendChild(popup);

    var activeInput = null;
    var activeRequest = null;
    var cache = {};
    var debounceTimer = null;
    var sequence = 0;

    function contextFor(input) {
        return input.dataset.searchContext
            || input.dataset.adminTableSearch
            || input.dataset.adminPanelSearch
            || (input.dataset.adminGlobalSearch !== undefined ? 'admin_global' : 'default');
    }

    function storageKey(context) {
        return 'ridesync.searchSuggestions.' + context;
    }

    function readHistory(context) {
        try {
            var parsed = JSON.parse(localStorage.getItem(storageKey(context)) || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function writeHistory(context, item) {
        if (!item || !item.label) return;

        var now = Date.now();
        var history = readHistory(context).filter(function (entry) {
            return entry.label !== item.label || entry.value !== item.value;
        });
        history.unshift({
            label: item.label,
            value: item.value || item.label,
            meta: item.meta || '',
            category: item.category || 'Recent',
            url: item.url || null,
            hits: (item.hits || 0) + 1,
            lastUsed: now,
            source: 'recent'
        });
        history = history
            .sort(function (left, right) {
                var leftScore = (left.hits || 0) * 10000000000000 + (left.lastUsed || 0);
                var rightScore = (right.hits || 0) * 10000000000000 + (right.lastUsed || 0);
                return rightScore - leftScore;
            })
            .slice(0, 12);
        try {
            localStorage.setItem(storageKey(context), JSON.stringify(history));
        } catch (error) {}
    }

    function scoreText(text, query) {
        text = String(text || '').toLowerCase();
        query = String(query || '').toLowerCase().trim();
        if (!query) return 1;
        if (text === query) return 1000;
        if (text.indexOf(query) === 0) return 820;
        if (text.indexOf(query) !== -1) return 620;

        return query.split(/\s+/).reduce(function (score, token) {
            return token.length > 1 && text.indexOf(token) !== -1 ? score + 120 : score;
        }, 0);
    }

    function dedupe(items, query, limit) {
        var seen = {};
        return items
            .map(function (item) {
                item.score = Math.max(item.score || 0, scoreText([item.label, item.value, item.meta, item.category].join(' '), query));
                return item;
            })
            .filter(function (item) {
                var key = [item.category || '', item.label || '', item.value || '', item.url || ''].join('|').toLowerCase();
                if (!item.label || seen[key] || (query && item.score <= 0)) return false;
                seen[key] = true;
                return true;
            })
            .sort(function (left, right) {
                if ((right.score || 0) === (left.score || 0)) {
                    return String(left.label).localeCompare(String(right.label));
                }
                return (right.score || 0) - (left.score || 0);
            })
            .slice(0, limit || 8);
    }

    function localSuggestions(input, query) {
        var context = contextFor(input);
        var rows = [];
        var tableId = input.dataset.adminTableSearch;
        var panelId = input.dataset.adminPanelSearch;
        var scope = tableId ? document.getElementById(tableId) : (panelId ? document.getElementById(panelId) : null);

        if (scope) {
            scope.querySelectorAll('[data-search]').forEach(function (node) {
                var labelNode = node.querySelector('strong') || node;
                var metaNode = node.querySelector('span');
                rows.push({
                    context: context,
                    category: 'Current page',
                    label: (labelNode.textContent || '').trim().slice(0, 160),
                    value: (labelNode.textContent || '').trim().slice(0, 160),
                    meta: (metaNode ? metaNode.textContent : node.dataset.search || '').trim().slice(0, 180),
                    source: 'page'
                });
            });
        }

        return dedupe(rows, query, 6);
    }

    function historySuggestions(input, query) {
        return dedupe(readHistory(contextFor(input)).map(function (item) {
            item.category = item.hits > 1 ? 'Frequent' : 'Recent';
            item.score = (item.hits || 1) * 80 + scoreText([item.label, item.value, item.meta].join(' '), query);
            return item;
        }), query, 5);
    }

    function fetchSuggestions(input, query, requestId) {
        if (query.length < 2) {
            return Promise.resolve([]);
        }

        var context = contextFor(input);
        var cacheKey = context + '|' + query.toLowerCase();
        if (cache[cacheKey]) {
            return Promise.resolve(cache[cacheKey]);
        }

        if (activeRequest && typeof activeRequest.abort === 'function') {
            activeRequest.abort();
        }
        activeRequest = window.AbortController ? new AbortController() : null;

        return fetch('/ridesync/api/search_suggestions.php?context=' + encodeURIComponent(context) + '&q=' + encodeURIComponent(query) + '&limit=10', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: activeRequest ? activeRequest.signal : undefined
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Suggestion request failed');
                return response.json();
            })
            .then(function (payload) {
                if (requestId !== sequence) return [];
                var suggestions = payload && Array.isArray(payload.suggestions) ? payload.suggestions : [];
                cache[cacheKey] = suggestions;
                return suggestions;
            })
            .catch(function () {
                return [];
            });
    }

    function positionPopup(input) {
        var rect = input.getBoundingClientRect();
        var viewportWidth = window.innerWidth || document.documentElement.clientWidth || 320;
        var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 640;
        var width = Math.min(Math.max(260, rect.width), Math.max(220, viewportWidth - 20));
        var left = Math.min(Math.max(10, rect.left), Math.max(10, viewportWidth - width - 10));
        var top = rect.bottom + 6;
        var availableBelow = viewportHeight - top - 12;

        if (availableBelow < 180 && rect.top > 220) {
            top = Math.max(10, rect.top - 226);
            availableBelow = Math.max(180, rect.top - top - 6);
        }

        popup.style.left = left + 'px';
        popup.style.top = top + 'px';
        popup.style.width = width + 'px';
        popup.style.maxHeight = Math.max(180, Math.min(360, availableBelow)) + 'px';
    }

    function hidePopup() {
        popup.classList.remove('is-visible');
        popup.innerHTML = '';
        activeInput = null;
    }

    function render(input, suggestions, query) {
        activeInput = input;
        positionPopup(input);

        if (!suggestions.length) {
            if (query.length >= 2) {
                popup.innerHTML = '<div class="smart-search-empty">No suggestions found</div>';
                popup.classList.add('is-visible');
            } else {
                hidePopup();
            }
            return;
        }

        popup.innerHTML = suggestions.map(function (item, index) {
            return '<button type="button" role="option" data-index="' + index + '">'
                + '<span>' + escapeHtml(item.category || item.source || 'Suggestion') + '</span>'
                + '<strong>' + escapeHtml(item.label || item.value || '') + '</strong>'
                + (item.meta ? '<small>' + escapeHtml(item.meta) + '</small>' : '')
                + '</button>';
        }).join('');

        popup.querySelectorAll('[data-index]').forEach(function (button) {
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });
            button.addEventListener('click', function () {
                var item = suggestions[parseInt(button.dataset.index, 10)];
                if (!item) return;

                writeHistory(contextFor(input), item);
                if (item.url && contextFor(input) === 'admin_global') {
                    window.location.href = item.url;
                    return;
                }

                input.value = item.value || item.label || '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                hidePopup();
            });
        });

        popup.classList.add('is-visible');
    }

    function update(input) {
        clearTimeout(debounceTimer);
        var query = input.value.trim();
        var requestId = ++sequence;

        debounceTimer = setTimeout(function () {
            var immediate = dedupe(historySuggestions(input, query).concat(localSuggestions(input, query)), query, 8);
            if (immediate.length || query.length < 2) {
                render(input, immediate, query);
            } else {
                activeInput = input;
                positionPopup(input);
                popup.innerHTML = '<div class="smart-search-empty">Searching...</div>';
                popup.classList.add('is-visible');
            }

            fetchSuggestions(input, query, requestId).then(function (remote) {
                if (requestId !== sequence) return;
                var merged = dedupe(historySuggestions(input, query).concat(localSuggestions(input, query), remote), query, 10);
                render(input, merged, query);
            });
        }, query.length < 2 ? 0 : 180);
    }

    fields.forEach(function (input) {
        if (input.dataset.smartSuggestionsBound === 'true') return;
        input.dataset.smartSuggestionsBound = 'true';
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('aria-autocomplete', 'list');

        input.addEventListener('focus', function () { update(input); });
        input.addEventListener('input', function () { update(input); });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') hidePopup();
            if (event.key === 'Enter' && input.value.trim() !== '') {
                writeHistory(contextFor(input), {
                    label: input.value.trim(),
                    value: input.value.trim(),
                    category: 'Recent'
                });
            }
        });
    });

    window.addEventListener('scroll', function () {
        if (activeInput && popup.classList.contains('is-visible')) positionPopup(activeInput);
    }, true);
    window.addEventListener('resize', function () {
        if (activeInput && popup.classList.contains('is-visible')) positionPopup(activeInput);
    });
    document.addEventListener('mousedown', function (event) {
        if (event.target !== activeInput && !popup.contains(event.target)) {
            hidePopup();
        }
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

    var tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    });

    tiles.on('tileload', function (event) {
        if (!event.tile) return;
        event.tile.alt = '';
        event.tile.setAttribute('aria-hidden', 'true');
    });

    tiles.addTo(map);

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
        var markerElement = marker.getElement && marker.getElement();
        if (markerElement) {
            markerElement.setAttribute('aria-label', (point.name || 'Map signal') + ' ' + (point.status || point.type || 'active'));
            markerElement.setAttribute('title', point.name || 'Map signal');
        }
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

    bindMapResize(canvas, map);
}

function bindMapResize(canvas, map) {
    if (!canvas || !map) return;

    var timer = null;
    var refresh = function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            map.invalidateSize();
        }, 120);
    };

    window.addEventListener('resize', refresh, { passive: true });
    window.addEventListener('orientationchange', refresh, { passive: true });

    if (window.ResizeObserver) {
        var observer = new ResizeObserver(refresh);
        observer.observe(canvas);
    }

    setTimeout(refresh, 250);
}

function initScrollableRegions() {
    document.querySelectorAll('.admin-table-wrap, .admin-feed-list').forEach(function (node, index) {
        node.setAttribute('tabindex', '0');
        if (!node.getAttribute('aria-label')) {
            node.setAttribute('aria-label', index === 0 ? 'Scrollable admin data' : 'Scrollable admin data ' + (index + 1));
        }
    });
}

function initResponsiveDataTables() {
    document.querySelectorAll('.admin-smart-table').forEach(function (table) {
        var headers = Array.prototype.slice.call(table.querySelectorAll('thead th')).map(function (header) {
            return header.textContent.trim();
        });

        if (!headers.length) return;

        table.querySelectorAll('tbody tr').forEach(function (row) {
            row.querySelectorAll('td').forEach(function (cell, index) {
                if (cell.colSpan > 1 && row.children.length === 1) {
                    cell.classList.add('admin-table-empty');
                }
                if (!cell.dataset.label && headers[index]) {
                    cell.dataset.label = headers[index];
                }
            });
        });
    });
}

function initAdminRealtime() {
    if (!window.EventSource || !document.querySelector('[data-admin-feed]')) return;

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
    window.addEventListener('pagehide', function () {
        source.close();
    }, { once: true });
}

function initAdminServicesMonitor() {
    var panel = document.querySelector('[data-admin-services]');
    if (!panel) return;

    var serviceList = panel.querySelector('[data-service-list]');
    var alertsNode = panel.querySelector('[data-service-alerts]');
    var workflowsNode = panel.querySelector('[data-service-workflows]');
    var queuesNode = panel.querySelector('[data-service-queues]');
    var apiChecksNode = panel.querySelector('[data-service-api-checks]');
    var logsNode = panel.querySelector('[data-service-logs]');
    var refreshNode = panel.querySelector('[data-service-last-refresh]');
    var repairFindingsNode = panel.querySelector('[data-repair-findings]');
    var repairRunsNode = panel.querySelector('[data-repair-runs]');
    var currentRequest = null;
    var refreshTimer = null;
    var initialTimer = null;

    function statusBadgeClass(status) {
        if (status === 'operational') return 'accepted';
        if (status === 'down' || status === 'critical') return 'rejected';
        return 'pending';
    }

    function formatValue(value) {
        if (value === null || typeof value === 'undefined' || value === '') return 'Not available';
        if (typeof value === 'boolean') return value ? 'yes' : 'no';
        if (typeof value === 'object') return 'details';
        return String(value);
    }

    function labelFor(key) {
        return String(key || '')
            .replace(/_/g, ' ')
            .replace(/\b\w/g, function (letter) { return letter.toUpperCase(); });
    }

    function setSummary(summary) {
        Object.keys(summary || {}).forEach(function (key) {
            panel.querySelectorAll('[data-service-summary="' + key + '"]').forEach(function (node) {
                node.textContent = formatValue(summary[key]);
            });
        });
    }

    function renderMetricRows(metrics) {
        return Object.keys(metrics || {}).slice(0, 4).map(function (key) {
            return '<div><dt>' + escapeHtml(labelFor(key)) + '</dt><dd>' + escapeHtml(formatValue(metrics[key])) + '</dd></div>';
        }).join('');
    }

    function drawerPayload(service) {
        var fields = {
            Status: service.status_label || 'Unknown',
            Group: service.group || 'Service',
            Summary: service.summary || '',
            Latency: service.latency_ms === null || typeof service.latency_ms === 'undefined' ? 'Not measured' : service.latency_ms + ' ms',
            'Checked At': service.checked_at || ''
        };
        Object.keys(service.metrics || {}).forEach(function (key) {
            fields[labelFor(key)] = formatValue(service.metrics[key]);
        });
        Object.keys(service.details || {}).forEach(function (key) {
            fields[labelFor(key)] = formatValue(service.details[key]);
        });

        return escapeHtml(JSON.stringify({
            kicker: 'Service',
            title: service.name || 'Service',
            fields: fields
        }));
    }

    function renderServices(services) {
        if (!serviceList || !Array.isArray(services)) return;

        serviceList.innerHTML = services.map(function (service) {
            var status = service.status || 'unknown';
            var latency = service.latency_ms === null || typeof service.latency_ms === 'undefined' ? 'Not measured' : service.latency_ms + ' ms';
            var uptime = service.uptime_percent === null || typeof service.uptime_percent === 'undefined' ? 'Not tracked' : service.uptime_percent + '%';

            return '<article class="admin-service-tile is-' + escapeHtml(status) + '" data-service-key="' + escapeHtml(service.key || 'service') + '">'
                + '<div><span>' + escapeHtml(service.group || 'Service') + '</span><strong>' + escapeHtml(service.name || 'Service') + '</strong></div>'
                + '<span class="badge badge-' + statusBadgeClass(status) + '">' + escapeHtml(service.status_label || 'Unknown') + '</span>'
                + '<p>' + escapeHtml(service.summary || 'No summary available.') + '</p>'
                + '<dl><div><dt>Latency</dt><dd>' + escapeHtml(latency) + '</dd></div>'
                + '<div><dt>Uptime</dt><dd>' + escapeHtml(uptime) + '</dd></div>'
                + renderMetricRows(service.metrics || {})
                + '</dl>'
                + '<button type="button" class="btn btn-secondary btn-sm admin-inspect-btn" data-admin-drawer="' + drawerPayload(service) + '">Inspect</button>'
                + '</article>';
        }).join('');
    }

    function renderAlerts(alerts) {
        if (!alertsNode || !Array.isArray(alerts)) return;
        if (!alerts.length) {
            alertsNode.innerHTML = '<article class="admin-risk-card is-healthy"><span>Healthy</span><strong>No active service alerts</strong><p>All monitored AI and operations services are within current thresholds.</p></article>';
            return;
        }

        alertsNode.innerHTML = alerts.map(function (alert) {
            var severity = alert.severity === 'critical' ? 'critical' : 'warning';
            return '<article class="admin-risk-card is-' + severity + '">'
                + '<span>' + escapeHtml(labelFor(alert.severity || 'warning')) + '</span>'
                + '<strong>' + escapeHtml(alert.title || 'Service alert') + '</strong>'
                + '<p>' + escapeHtml(alert.detail || 'Review service details.') + '</p>'
                + '</article>';
        }).join('');
    }

    function renderStack(node, items) {
        if (!node) return;
        node.innerHTML = items.map(function (item) {
            return '<div><span>' + escapeHtml(item.label) + '</span><strong>' + escapeHtml(formatValue(item.value)) + '</strong></div>';
        }).join('');
    }

    function renderLogs(logs) {
        if (!logsNode) return;
        var recent = logs && Array.isArray(logs.recent) ? logs.recent : [];
        if (!recent.length) {
            logsNode.innerHTML = '<div class="admin-feed-item"><div><strong>No recent warning or error logs</strong><p>Runtime logs have no service-level warnings in the current window.</p><small>Live update</small></div></div>';
            return;
        }

        logsNode.innerHTML = recent.map(function (log) {
            var level = String(log.level || 'warning').toUpperCase();
            var badge = level === 'ERROR' || level === 'CRITICAL' ? 'rejected' : 'pending';
            return '<div class="admin-feed-item">'
                + '<span class="admin-feed-dot badge-' + badge + '"></span>'
                + '<div><strong>' + escapeHtml(level + ' - ' + (log.message || 'Log event')) + '</strong>'
                + '<p>' + escapeHtml(log.request_id || 'No request id') + '</p>'
                + '<small>' + escapeHtml(log.timestamp || '') + '</small></div>'
                + '</div>';
        }).join('');
    }

    function setRepairSummary(summary) {
        Object.keys(summary || {}).forEach(function (key) {
            panel.querySelectorAll('[data-repair-summary="' + key + '"]').forEach(function (node) {
                node.textContent = formatValue(summary[key]);
            });
        });
    }

    function renderRepairFindings(findings) {
        if (!repairFindingsNode || !Array.isArray(findings)) return;
        if (!findings.length) {
            repairFindingsNode.innerHTML = '<div class="admin-feed-item"><div><strong>No critical repair findings</strong><p>Core services, schema, queues, storage, security settings, and AI diagnostics are within current scanner thresholds.</p><small>Live update</small></div></div>';
            return;
        }

        repairFindingsNode.innerHTML = findings.slice(0, 10).map(function (finding) {
            var severity = finding.severity || 'info';
            return '<div class="admin-repair-finding is-' + escapeHtml(severity) + '">'
                + '<span>' + escapeHtml(labelFor(finding.area || 'System')) + '</span>'
                + '<strong>' + escapeHtml(finding.title || 'Repair finding') + '</strong>'
                + '<p>' + escapeHtml(finding.detail || 'Review this repair signal.') + '</p>'
                + '<small>' + escapeHtml(labelFor(finding.action_key || 'deep_scan')) + '</small>'
                + '</div>';
        }).join('');
    }

    function renderRepairRuns(runs) {
        if (!repairRunsNode || !Array.isArray(runs)) return;
        if (!runs.length) {
            repairRunsNode.innerHTML = '<div class="admin-feed-item"><div><strong>No Repair Kit runs yet</strong><p>Recovery actions will appear here with encrypted payloads, hashes, checkpoints, and admin attribution.</p><small>Waiting for first run</small></div></div>';
            return;
        }

        repairRunsNode.innerHTML = runs.slice(0, 8).map(function (run) {
            var result = run.result || {};
            var hash = String(run.log_hash || '').slice(0, 12);
            return '<div class="admin-repair-log-row is-' + escapeHtml(run.status || 'queued') + '">'
                + '<div><span>' + escapeHtml(String(run.status || 'queued').toUpperCase()) + '</span>'
                + '<strong>' + escapeHtml(labelFor(run.action_key || 'repair')) + '</strong>'
                + '<p>' + escapeHtml(result.message || 'Encrypted recovery record captured.') + '</p></div>'
                + '<small>' + escapeHtml((run.created_at || '') + (hash ? ' / ' + hash : '')) + '</small>'
                + '</div>';
        }).join('');
    }

    function applyPayload(payload) {
        if (!payload || !payload.summary) return;
        setSummary(payload.summary || {});
        renderServices(payload.services || []);
        renderAlerts(payload.alerts || []);
        renderStack(workflowsNode, [
            { label: 'Queued', value: payload.workflows && payload.workflows.queued },
            { label: 'Processing', value: payload.workflows && payload.workflows.processing },
            { label: 'Slow', value: payload.workflows && payload.workflows.slow_processing },
            { label: 'Avg Time', value: (payload.workflows && payload.workflows.avg_processing_ms || 0) + ' ms' },
            { label: 'Token Usage', value: payload.workflows && payload.workflows.token_usage_24h }
        ]);
        renderStack(queuesNode, [
            { label: 'Processing', value: payload.queues && payload.queues.processing },
            { label: 'Stale Processing', value: payload.queues && payload.queues.stale_processing },
            { label: 'Failed Verification', value: payload.queues && payload.queues.failed_verification },
            { label: 'Succeeded 24h', value: payload.queues && payload.queues.succeeded_24h }
        ]);
        renderStack(apiChecksNode, [
            { label: 'Passed 24h', value: payload.api_checks && payload.api_checks.passed_24h },
            { label: 'Needs Review', value: payload.api_checks && payload.api_checks.needs_review_24h },
            { label: 'Failed 24h', value: payload.api_checks && payload.api_checks.failed_24h },
            { label: 'Total Checks', value: payload.api_checks && payload.api_checks.checks_24h }
        ]);
        renderLogs(payload.logs || {});
        if (payload.repair_kit && !payload.repair_kit.locked) {
            setRepairSummary(payload.repair_kit.summary || {});
            renderRepairFindings(payload.repair_kit.findings || []);
            renderRepairRuns(payload.repair_kit.recent_runs || []);
        }
        if (refreshNode) {
            refreshNode.textContent = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
        }
    }

    function requestJson(url, signal) {
        if (window.fetch) {
            return fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                signal: signal
            }).then(function (response) {
                if (!response.ok) throw new Error('Service monitor request failed');
                return response.json();
            });
        }

        if (window.XMLHttpRequest) {
            return new Promise(function (resolve, reject) {
                var request = new XMLHttpRequest();
                request.open('GET', url, true);
                request.setRequestHeader('Accept', 'application/json');
                request.withCredentials = true;
                request.onload = function () {
                    if (request.status < 200 || request.status >= 300) {
                        reject(new Error('Service monitor request failed'));
                        return;
                    }
                    try {
                        resolve(JSON.parse(request.responseText || '{}'));
                    } catch (error) {
                        reject(error);
                    }
                };
                request.onerror = function () { reject(new Error('Service monitor request failed')); };
                request.send();
            });
        }

        return Promise.reject(new Error('No browser request API available'));
    }

    function refresh() {
        if (document.hidden) return;
        if (currentRequest && typeof currentRequest.abort === 'function') {
            currentRequest.abort();
        }
        currentRequest = window.AbortController ? new AbortController() : null;

        requestJson('/ridesync/api/admin_services.php', currentRequest ? currentRequest.signal : undefined)
            .then(applyPayload)
            .catch(function () {});
    }

    function clearRefreshSchedule() {
        if (initialTimer) {
            window.clearTimeout(initialTimer);
            initialTimer = null;
        }
        if (refreshTimer) {
            window.clearInterval(refreshTimer);
            refreshTimer = null;
        }
        if (currentRequest && typeof currentRequest.abort === 'function') {
            currentRequest.abort();
        }
        currentRequest = null;
    }

    function scheduleRefresh() {
        if (refreshTimer || initialTimer) return;
        initialTimer = window.setTimeout(function () {
            initialTimer = null;
            refresh();
        }, 5000);
        refreshTimer = window.setInterval(refresh, 30000);
    }

    window.addEventListener('pagehide', clearRefreshSchedule, { once: true });
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            clearRefreshSchedule();
            return;
        }
        refresh();
        scheduleRefresh();
    });

    scheduleRefresh();
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

function initConfirmActions() {
    document.querySelectorAll('[data-history-back]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (window.history.length > 1) {
                event.preventDefault();
                window.history.back();
            }
        });
    });

    document.querySelectorAll('form[data-confirm-message], button[data-confirm-message]').forEach(function (node) {
        var form = node.tagName === 'FORM' ? node : node.closest('form');
        if (!form || form.dataset.confirmBound === 'true') return;

        form.dataset.confirmBound = 'true';
        form.addEventListener('submit', function (event) {
            var submitter = event.submitter || document.activeElement;
            var message = (submitter && submitter.dataset && submitter.dataset.confirmMessage)
                || form.dataset.confirmMessage
                || 'Continue with this action?';
            var phrase = (submitter && submitter.dataset && submitter.dataset.confirmPhrase)
                || form.dataset.confirmPhrase
                || '';

            if (phrase) {
                var typed = window.prompt(message + '\n\nType "' + phrase + '" to confirm.');
                if (typed !== phrase) {
                    event.preventDefault();
                    return;
                }

                var confirmationInput = form.querySelector('input[name="confirmation_text"]');
                if (confirmationInput) {
                    confirmationInput.value = typed;
                }
                return;
            }

            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
}

function showFormErrors(form, errors) {
    // Remove any existing client-side error box
    var existing = form.querySelector('.client-errors');
    if (existing) existing.remove();

    var errorDiv = document.createElement('div');
    errorDiv.className = 'alert alert-error client-errors';
    errors.forEach(function (err) {
        var item = document.createElement('div');
        item.textContent = String(err || 'Please check this field.');
        errorDiv.appendChild(item);
    });

    form.insertBefore(errorDiv, form.firstChild);

    // Scroll to error
    errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
