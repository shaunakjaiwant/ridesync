// RideSync Live GPS Tracking
(function () {
    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    ready(function () {
        initDriverLivePublisher();
        initRiderLiveSubscriber();
    });

    // 1. Driver Side: Publish GPS pings periodically
    function initDriverLivePublisher() {
        var driverContainer = document.querySelector('[data-driver-tracking-ride]');
        if (!driverContainer) return;

        var rideId = driverContainer.dataset.driverTrackingRide;
        var csrfToken = driverContainer.dataset.csrfToken || (document.querySelector('input[name="csrf_token"]') ? document.querySelector('input[name="csrf_token"]').value : '');
        if (!rideId || !csrfToken) return;

        if (!navigator.geolocation) {
            console.warn('[RideSync Live Tracking] Geolocation API not supported by browser.');
            return;
        }

        var lastSendTime = 0;
        var sendIntervalMs = 15000; // 15 seconds throttle

        function sendPing(lat, lng) {
            var now = Date.now();
            if (now - lastSendTime < 10000) return; // Hard throttle 10s minimum
            lastSendTime = now;

            var formData = new FormData();
            formData.append('ride_id', rideId);
            formData.append('latitude', lat);
            formData.append('longitude', lng);
            formData.append('csrf_token', csrfToken);

            fetch('/ridesync/actions/update_location_action.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    var statusEl = driverContainer.querySelector('[data-tracking-status]');
                    if (statusEl) {
                        statusEl.textContent = 'Live GPS active (Last ping: ' + new Date().toLocaleTimeString() + ')';
                    }
                }
            })
            .catch(function (err) {
                console.error('[RideSync Live Tracking] Update failed:', err);
            });
        }

        // Watch position
        navigator.geolocation.watchPosition(
            function (pos) {
                sendPing(pos.coords.latitude, pos.coords.longitude);
            },
            function (err) {
                console.warn('[RideSync Live Tracking] Watch position error:', err.message);
            },
            {
                enableHighAccuracy: true,
                maximumAge: 10000,
                timeout: 15000
            }
        );
    }

    // 2. Rider Side: Subscribe & update Leaflet marker
    function initRiderLiveSubscriber() {
        var trackingEl = document.querySelector('[data-live-tracking-ride]');
        if (!trackingEl) return;

        var rideId = trackingEl.dataset.liveTrackingRide;
        if (!rideId) return;

        var mapElement = trackingEl.querySelector('[data-ride-map]') || document.querySelector('[data-ride-map]');
        var driverMarker = null;
        var pollInterval = null;

        function fetchLatestLocation() {
            fetch('/ridesync/actions/get_location_action.php?ride_id=' + encodeURIComponent(rideId), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.success || !data.location) {
                    var statusBadge = trackingEl.querySelector('[data-live-gps-badge]');
                    if (statusBadge) {
                        statusBadge.textContent = 'GPS: Waiting for driver location...';
                    }
                    return;
                }

                var loc = data.location;
                var lat = loc.latitude;
                var lng = loc.longitude;
                var recTime = new Date(loc.recorded_at).toLocaleTimeString();

                var statusBadge = trackingEl.querySelector('[data-live-gps-badge]');
                if (statusBadge) {
                    statusBadge.textContent = 'Live GPS Active (Updated ' + recTime + ')';
                    statusBadge.classList.add('live-gps-online');
                }

                // If Leaflet map instance exists or can be accessed
                if (window.L && mapElement && mapElement._leaflet_map) {
                    var map = mapElement._leaflet_map;
                    var latlng = L.latLng(lat, lng);

                    if (!driverMarker) {
                        var driverIcon = L.divIcon({
                            className: 'driver-live-marker',
                            html: '<div style="background:#2563eb;color:#fff;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,0.4);font-weight:bold;font-size:14px;">🚗</div>',
                            iconSize: [32, 32],
                            iconAnchor: [16, 16]
                        });
                        driverMarker = L.marker(latlng, { icon: driverIcon }).addTo(map);
                        driverMarker.bindPopup('<strong>Driver Live Location</strong><br>Updated: ' + recTime);
                    } else {
                        driverMarker.setLatLng(latlng);
                        driverMarker.getPopup().setContent('<strong>Driver Live Location</strong><br>Updated: ' + recTime);
                    }
                }
            })
            .catch(function (err) {
                console.error('[RideSync Live Tracking] Poll error:', err);
            });
        }

        // Initial fetch + 10s polling interval
        fetchLatestLocation();
        pollInterval = setInterval(fetchLatestLocation, 10000);
    }
})();
