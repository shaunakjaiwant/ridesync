// RideSync map picker and route preview.
(function () {
    var FARE_RATE_PER_KM = 25.6;

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    ready(function () {
        initRideMapPickers();
        initRideDetailMaps();
    });

    function initRideMapPickers() {
        document.querySelectorAll('[data-map-picker]').forEach(function (picker) {
            var canvas = picker.querySelector('[data-map-canvas]');

            if (!canvas) {
                setStatus(picker, 'Map container is unavailable. You can still type locations manually.');
                return;
            }

            if (!window.L) {
                markMapUnavailable(canvas, 'Map tools are temporarily unavailable. You can still type locations manually.');
                picker.querySelectorAll('[data-map-mode], [data-use-current-location], [data-map-search-origin], [data-map-search-destination], [data-map-swap], [data-map-clear]').forEach(function (button) {
                    button.disabled = true;
                });
                setStatus(picker, 'Map could not load. You can still type locations manually.');
                return;
            }

            var originInput = document.getElementById('origin');
            var destinationInput = document.getElementById('destination');
            var originLat = picker.querySelector('[data-origin-lat]');
            var originLng = picker.querySelector('[data-origin-lng]');
            var destinationLat = picker.querySelector('[data-destination-lat]');
            var destinationLng = picker.querySelector('[data-destination-lng]');
            var routeDistanceInput = picker.querySelector('[data-route-distance-input]');
            var routePolylineInput = picker.querySelector('[data-route-polyline-input]');
            var routeDistanceLabel = picker.querySelector('[data-route-distance]');
            var routeFareLabel = picker.querySelector('[data-route-fare]');
            var activeMode = 'origin';
            var markers = {};
            var routeLayer = null;

            var map = L.map(canvas, {
                zoomControl: true,
                scrollWheelZoom: true
            }).setView([12.9141, 74.8560], 11);

            addTiles(map);
            setTimeout(function () { map.invalidateSize(); }, 150);

            picker.querySelectorAll('[data-map-mode]').forEach(function (button) {
                button.addEventListener('click', function () {
                    activeMode = button.dataset.mapMode;
                    setActiveMode(picker, activeMode);
                    setStatus(picker, activeMode === 'origin' ? 'Click the map to set departure.' : 'Click the map to set destination.');
                });
            });

            map.on('click', function (event) {
                setPoint(activeMode, event.latlng, true);
                activeMode = activeMode === 'origin' ? 'destination' : 'origin';
                setActiveMode(picker, activeMode);
            });

            var currentButton = picker.querySelector('[data-use-current-location]');
            if (currentButton) {
                currentButton.addEventListener('click', function () {
                    if (!navigator.geolocation) {
                        setStatus(picker, 'Your browser does not support location detection.');
                        return;
                    }

                    setStatus(picker, 'Finding your current location...');
                    navigator.geolocation.getCurrentPosition(function (position) {
                        var latlng = L.latLng(position.coords.latitude, position.coords.longitude);
                        setPoint('origin', latlng, true);
                        map.setView(latlng, 15);
                        activeMode = 'destination';
                        setActiveMode(picker, activeMode);
                    }, function () {
                        setStatus(picker, 'Location permission was blocked. Click the map to set departure.');
                    }, {
                        enableHighAccuracy: true,
                        timeout: 10000
                    });
                });
            }

            var originSearch = picker.querySelector('[data-map-search-origin]');
            if (originSearch) {
                originSearch.addEventListener('click', function () {
                    geocodeInput('origin', originInput, map, setPoint, picker);
                });
            }

            var destinationSearch = picker.querySelector('[data-map-search-destination]');
            if (destinationSearch) {
                destinationSearch.addEventListener('click', function () {
                    geocodeInput('destination', destinationInput, map, setPoint, picker);
                });
            }

            var clearButton = picker.querySelector('[data-map-clear]');
            if (clearButton) {
                clearButton.addEventListener('click', function () {
                    Object.keys(markers).forEach(function (key) {
                        map.removeLayer(markers[key]);
                    });
                    markers = {};
                    if (routeLayer) map.removeLayer(routeLayer);
                    routeLayer = null;
                    originLat.value = '';
                    originLng.value = '';
                    destinationLat.value = '';
                    destinationLng.value = '';
                    routeDistanceInput.value = '';
                    if (routePolylineInput) routePolylineInput.value = '';
                    routeDistanceLabel.textContent = 'Distance not set';
                    if (routeFareLabel) routeFareLabel.textContent = 'Fare estimate appears here';
                    activeMode = 'origin';
                    setActiveMode(picker, activeMode);
                    setStatus(picker, 'Choose departure, then destination.');
                });
            }

            var swapButton = picker.querySelector('[data-map-swap]');
            if (swapButton) {
                swapButton.addEventListener('click', function () {
                    var originText = originInput ? originInput.value : '';
                    var destinationText = destinationInput ? destinationInput.value : '';
                    var originPoint = markers.origin ? markers.origin.getLatLng() : null;
                    var destinationPoint = markers.destination ? markers.destination.getLatLng() : null;

                    if (originInput) originInput.value = destinationText;
                    if (destinationInput) destinationInput.value = originText;

                    if (originPoint && destinationPoint) {
                        markers.origin.setLatLng(destinationPoint);
                        markers.destination.setLatLng(originPoint);
                        updateHidden('origin', destinationPoint);
                        updateHidden('destination', originPoint);
                        updateRoute();
                        setStatus(picker, 'Route swapped. You can drag either pin to adjust.');
                    } else {
                        var originLatValue = originLat.value;
                        var originLngValue = originLng.value;
                        originLat.value = destinationLat.value;
                        originLng.value = destinationLng.value;
                        destinationLat.value = originLatValue;
                        destinationLng.value = originLngValue;
                        if (routeLayer) map.removeLayer(routeLayer);
                        routeLayer = null;
                        routeDistanceInput.value = '';
                        if (routePolylineInput) routePolylineInput.value = '';
                        routeDistanceLabel.textContent = 'Distance not set';
                        if (routeFareLabel) routeFareLabel.textContent = 'Fare estimate appears here';
                        setStatus(picker, 'Locations swapped. Set both pins to calculate distance.');
                    }

                    activeMode = 'origin';
                    setActiveMode(picker, activeMode);
                });
            }

            initLocationSuggestions('origin', originInput);
            initLocationSuggestions('destination', destinationInput);

            restorePointFromHidden('origin', originLat.value, originLng.value);
            restorePointFromHidden('destination', destinationLat.value, destinationLng.value);

            function restorePointFromHidden(type, latValue, lngValue) {
                var lat = parseFloat(latValue);
                var lng = parseFloat(lngValue);
                if (!isFinite(lat) || !isFinite(lng)) return;

                var latlng = L.latLng(lat, lng);
                setPoint(type, latlng, false);
                if (type === 'origin') {
                    map.setView(latlng, 13);
                }
            }

            function setPoint(type, latlng, shouldReverseGeocode) {
                var isOrigin = type === 'origin';
                var icon = makePinIcon(isOrigin ? 'A' : 'D', isOrigin ? 'origin' : 'destination');

                if (!markers[type]) {
                    markers[type] = L.marker(latlng, {
                        draggable: true,
                        icon: icon
                    }).addTo(map);

                    markers[type].on('dragend', function () {
                        var nextLatLng = markers[type].getLatLng();
                        updateHidden(type, nextLatLng);
                        reverseGeocode(type, nextLatLng, isOrigin ? originInput : destinationInput);
                        updateRoute();
                    });
                } else {
                    markers[type].setLatLng(latlng);
                }

                updateHidden(type, latlng);
                if (shouldReverseGeocode) {
                    reverseGeocode(type, latlng, isOrigin ? originInput : destinationInput);
                }
                updateRoute();
            }

            function updateHidden(type, latlng) {
                if (type === 'origin') {
                    originLat.value = latlng.lat.toFixed(7);
                    originLng.value = latlng.lng.toFixed(7);
                } else {
                    destinationLat.value = latlng.lat.toFixed(7);
                    destinationLng.value = latlng.lng.toFixed(7);
                }
            }

            function updateRoute() {
                if (!markers.origin || !markers.destination) {
                    setStatus(picker, markers.origin ? 'Now set destination.' : 'Choose departure, then destination.');
                    return;
                }

                var start = markers.origin.getLatLng();
                var end = markers.destination.getLatLng();
                setStatus(picker, 'Calculating route distance...');

                fetchRoute(start, end).then(function (route) {
                    if (routeLayer) map.removeLayer(routeLayer);
                    routeLayer = L.polyline(route.latlngs, {
                        color: '#1f9d66',
                        weight: 5,
                        opacity: 0.9,
                        lineCap: 'round'
                    }).addTo(map);
                    map.fitBounds(routeLayer.getBounds(), { padding: [28, 28] });
                    routeDistanceInput.value = route.distanceKm.toFixed(2);
                    if (routePolylineInput) {
                        routePolylineInput.value = compactRouteGeometry(route.latlngs);
                    }
                    routeDistanceLabel.textContent = route.distanceKm.toFixed(2) + ' km route';
                    if (routeFareLabel) {
                        routeFareLabel.textContent = farePreviewLabel(route.distanceKm);
                    }
                    setStatus(picker, 'Route set. You can drag either pin to adjust.');
                });
            }

            function initLocationSuggestions(type, input) {
                if (!input) return;

                var suggestions = document.createElement('div');
                suggestions.className = 'map-location-suggestions';
                input.insertAdjacentElement('afterend', suggestions);
                input.setAttribute('autocomplete', 'off');

                var timer = null;
                input.addEventListener('input', function () {
                    clearTimeout(timer);
                    var query = input.value.trim();
                    if (query.length < 3) {
                        suggestions.innerHTML = '';
                        suggestions.classList.remove('is-visible');
                        return;
                    }

                    timer = setTimeout(function () {
                        suggestions.innerHTML = '<button type="button">Searching locations...</button>';
                        suggestions.classList.add('is-visible');

                        fetchLocationSuggestions(query).then(function (results) {
                            if (!results.length) {
                                suggestions.innerHTML = '<button type="button">No suggestions found</button>';
                                return;
                            }

                            suggestions.innerHTML = results.map(function (item, index) {
                                return '<button type="button" data-suggestion-index="' + index + '">' + escapeHtml(item.display_name) + '</button>';
                            }).join('');

                            suggestions.querySelectorAll('[data-suggestion-index]').forEach(function (button) {
                                button.addEventListener('click', function () {
                                    var selected = results[parseInt(button.dataset.suggestionIndex, 10)];
                                    var latlng = L.latLng(parseFloat(selected.lat), parseFloat(selected.lon));
                                    input.value = selected.display_name;
                                    setPoint(type, latlng, false);
                                    map.setView(latlng, 15);
                                    suggestions.innerHTML = '';
                                    suggestions.classList.remove('is-visible');
                                    activeMode = type === 'origin' ? 'destination' : 'origin';
                                    setActiveMode(picker, activeMode);
                                });
                            });
                        }).catch(function () {
                            suggestions.innerHTML = '<button type="button">Could not load suggestions</button>';
                        });
                    }, 350);
                });

                document.addEventListener('click', function (event) {
                    if (event.target !== input && !suggestions.contains(event.target)) {
                        suggestions.classList.remove('is-visible');
                    }
                });
            }
        });
    }

    function initRideDetailMaps() {
        document.querySelectorAll('[data-ride-map]').forEach(function (canvas) {
            if (!window.L) {
                markMapUnavailable(canvas, 'Route map is temporarily unavailable.');
                return;
            }

            var origin = L.latLng(parseFloat(canvas.dataset.originLat), parseFloat(canvas.dataset.originLng));
            var destination = L.latLng(parseFloat(canvas.dataset.destinationLat), parseFloat(canvas.dataset.destinationLng));

            if (!isFinite(origin.lat) || !isFinite(origin.lng) || !isFinite(destination.lat) || !isFinite(destination.lng)) {
                return;
            }

            var map = L.map(canvas, {
                zoomControl: true,
                scrollWheelZoom: false
            }).setView(origin, 12);

            addTiles(map);
            L.marker(origin, { icon: makePinIcon('A', 'origin') }).addTo(map).bindPopup(canvas.dataset.origin || 'Departure');
            L.marker(destination, { icon: makePinIcon('D', 'destination') }).addTo(map).bindPopup(canvas.dataset.destination || 'Destination');

            fetchRoute(origin, destination).then(function (route) {
                var routeLayer = L.polyline(route.latlngs, {
                    color: '#1f9d66',
                    weight: 5,
                    opacity: 0.9,
                    lineCap: 'round'
                }).addTo(map);
                map.fitBounds(routeLayer.getBounds(), { padding: [28, 28] });
                setTimeout(function () { map.invalidateSize(); }, 150);
            });
        });
    }

    function addTiles(map) {
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);
    }

    function makePinIcon(label, type) {
        return L.divIcon({
            className: 'map-pin-icon map-pin-' + type,
            html: '<span>' + label + '</span>',
            iconSize: [34, 34],
            iconAnchor: [17, 17],
            popupAnchor: [0, -18]
        });
    }

    function setActiveMode(picker, activeMode) {
        picker.querySelectorAll('[data-map-mode]').forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.mapMode === activeMode);
        });
    }

    function setStatus(picker, message) {
        var status = picker.querySelector('[data-map-status]');
        if (status) status.textContent = message;
    }

    function markMapUnavailable(canvas, message) {
        if (!canvas) return;

        canvas.classList.add('map-unavailable');
        canvas.textContent = message;
    }

    function geocodeInput(type, input, map, setPoint, picker) {
        var query = input && input.value ? input.value.trim() : '';
        if (query.length < 2) {
            setStatus(picker, 'Type a location first.');
            return;
        }

        setStatus(picker, 'Searching ' + query + '...');
        fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(query))
            .then(function (response) { return response.json(); })
            .then(function (results) {
                if (!results.length) {
                    setStatus(picker, 'No location found. Try a more specific place.');
                    return;
                }

                var latlng = L.latLng(parseFloat(results[0].lat), parseFloat(results[0].lon));
                setPoint(type, latlng, false);
                map.setView(latlng, 15);
                if (input) input.value = results[0].display_name;
            })
            .catch(function () {
                setStatus(picker, 'Location search failed. You can click the map instead.');
            });
    }

    function fetchLocationSuggestions(query) {
        var normalized = /\b(india|karnataka|mangaluru|mangalore|dakshina kannada)\b/i.test(query)
            ? query
            : query + ' Karnataka India';

        return fetch('https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=6&countrycodes=in&q=' + encodeURIComponent(normalized))
            .then(function (response) { return response.json(); });
    }

    function formatINR(value) {
        return new Intl.NumberFormat('en-IN', {
            style: 'currency',
            currency: 'INR',
            maximumFractionDigits: 0
        }).format(Math.max(0, Math.round(value)));
    }

    function formatRate(value) {
        return new Intl.NumberFormat('en-IN', {
            style: 'currency',
            currency: 'INR',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(Math.max(0, value));
    }

    function farePreviewLabel(distanceKm) {
        var distance = Math.max(0.1, Number(distanceKm) || 0);
        return formatINR(distance * FARE_RATE_PER_KM) + ' ride fare @ ' + formatRate(FARE_RATE_PER_KM) + '/km';
    }

    function compactRouteGeometry(latlngs) {
        if (!Array.isArray(latlngs) || !latlngs.length) return '';

        var maxPoints = 42;
        var step = Math.max(1, Math.ceil(latlngs.length / maxPoints));
        var compact = [];

        latlngs.forEach(function (latlng, index) {
            if (index % step === 0 || index === latlngs.length - 1) {
                compact.push([
                    Number(latlng.lat.toFixed(5)),
                    Number(latlng.lng.toFixed(5))
                ]);
            }
        });

        return JSON.stringify(compact);
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function reverseGeocode(type, latlng, input) {
        fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + encodeURIComponent(latlng.lat) + '&lon=' + encodeURIComponent(latlng.lng))
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (!input || !result) return;
                input.value = result.display_name || (latlng.lat.toFixed(5) + ', ' + latlng.lng.toFixed(5));
            })
            .catch(function () {
                if (input && input.value.trim() === '') {
                    input.value = latlng.lat.toFixed(5) + ', ' + latlng.lng.toFixed(5);
                }
            });
    }

    function fetchRoute(start, end) {
        var fallback = function () {
            return {
                latlngs: [start, end],
                distanceKm: haversineDistance(start, end)
            };
        };

        var url = 'https://router.project-osrm.org/route/v1/driving/'
            + start.lng + ',' + start.lat + ';'
            + end.lng + ',' + end.lat
            + '?overview=full&geometries=geojson';

        return fetch(url)
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.routes || !data.routes.length) return fallback();
                return {
                    latlngs: data.routes[0].geometry.coordinates.map(function (coord) {
                        return L.latLng(coord[1], coord[0]);
                    }),
                    distanceKm: data.routes[0].distance / 1000
                };
            })
            .catch(fallback);
    }

    function haversineDistance(start, end) {
        var radius = 6371;
        var dLat = toRadians(end.lat - start.lat);
        var dLng = toRadians(end.lng - start.lng);
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2)
            + Math.cos(toRadians(start.lat)) * Math.cos(toRadians(end.lat))
            * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return radius * c;
    }

    function toRadians(value) {
        return value * Math.PI / 180;
    }
})();
