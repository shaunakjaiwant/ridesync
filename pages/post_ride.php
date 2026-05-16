<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/asset_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

ridesync_enable_map_assets();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-container ride-form-container">
    <h2>Post a Ride</h2>

    <?php ridesync_flash('ride_error', 'alert-error'); ?>
    <?php ridesync_flash('ride_success', 'alert-success'); ?>

    <form action="/ridesync/actions/post_ride_action.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="form-group">
            <label for="origin">Departure Location</label>
            <input type="text" id="origin" name="origin" required placeholder="e.g. SDMIT Campus">
        </div>

        <div class="form-group">
            <label for="destination">Destination</label>
            <input type="text" id="destination" name="destination" required placeholder="e.g. Ujire Bus Stand">
        </div>

        <section class="map-picker-card" data-map-picker>
            <div class="map-picker-header">
                <div>
                    <span class="map-kicker">Exact location</span>
                    <h3>Set departure and destination on map</h3>
                    <p>Type in Departure/Destination for suggestions, use current location, or click the map to place pins.</p>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" data-use-current-location>Use my location</button>
            </div>

            <div class="map-picker-tools">
                <button type="button" class="btn btn-secondary btn-sm is-active" data-map-mode="origin">Set departure</button>
                <button type="button" class="btn btn-secondary btn-sm" data-map-mode="destination">Set destination</button>
                <button type="button" class="btn btn-secondary btn-sm" data-map-search-origin>Find departure</button>
                <button type="button" class="btn btn-secondary btn-sm" data-map-search-destination>Find destination</button>
                <button type="button" class="btn btn-secondary btn-sm" data-map-swap>Swap</button>
                <button type="button" class="btn btn-secondary btn-sm" data-map-clear>Clear</button>
            </div>

            <div id="rideMapPicker" class="ride-map" data-map-canvas></div>

            <div class="map-picker-status">
                <span data-map-status>Choose departure, then destination.</span>
                <div class="map-distance-summary">
                    <strong data-route-distance>Distance not set</strong>
                    <strong class="map-fare-highlight" data-route-fare>Fare estimate appears here</strong>
                </div>
            </div>

            <input type="hidden" name="origin_lat" data-origin-lat>
            <input type="hidden" name="origin_lng" data-origin-lng>
            <input type="hidden" name="destination_lat" data-destination-lat>
            <input type="hidden" name="destination_lng" data-destination-lng>
            <input type="hidden" name="route_distance_km" data-route-distance-input>
            <input type="hidden" name="route_polyline" data-route-polyline-input>
        </section>

        <div class="form-group">
            <label for="travel_date">Travel Date</label>
            <input type="date" id="travel_date" name="travel_date" required min="<?php echo date('Y-m-d'); ?>">
        </div>

        <div class="form-group">
            <label for="travel_time">Departure Time</label>
            <input type="time" id="travel_time" name="travel_time" required>
        </div>

        <div class="form-group">
            <label for="seats_available">Available Seats</label>
            <select id="seats_available" name="seats_available" required>
                <option value="1">1 seat</option>
                <option value="2">2 seats</option>
                <option value="3">3 seats</option>
                <option value="4">4 seats</option>
                <option value="5">5 seats</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">Post Ride</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
