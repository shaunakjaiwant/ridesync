<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/asset_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

$rideFormOld = $_SESSION['ride_form_old'] ?? [];
unset($_SESSION['ride_form_old']);

function ridesync_post_ride_old_value(array $oldInput, string $key, string $default = ''): string
{
    return htmlspecialchars((string) ($oldInput[$key] ?? $default));
}

$oldSeats = (int) ($rideFormOld['seats_available'] ?? 1);
if ($oldSeats < 1 || $oldSeats > 5) {
    $oldSeats = 1;
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
            <input type="text" id="origin" name="origin" required placeholder="e.g. SDMIT Campus" value="<?php echo ridesync_post_ride_old_value($rideFormOld, 'origin'); ?>">
        </div>

        <div class="form-group">
            <label for="destination">Destination</label>
            <input type="text" id="destination" name="destination" required placeholder="e.g. Ujire Bus Stand" value="<?php echo ridesync_post_ride_old_value($rideFormOld, 'destination'); ?>">
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

            <input type="hidden" name="origin_lat" data-origin-lat value="<?php echo ridesync_post_ride_old_value($rideFormOld, 'origin_lat'); ?>">
            <input type="hidden" name="origin_lng" data-origin-lng value="<?php echo ridesync_post_ride_old_value($rideFormOld, 'origin_lng'); ?>">
            <input type="hidden" name="destination_lat" data-destination-lat value="<?php echo ridesync_post_ride_old_value($rideFormOld, 'destination_lat'); ?>">
            <input type="hidden" name="destination_lng" data-destination-lng value="<?php echo ridesync_post_ride_old_value($rideFormOld, 'destination_lng'); ?>">
            <input type="hidden" name="route_distance_km" data-route-distance-input value="<?php echo ridesync_post_ride_old_value($rideFormOld, 'route_distance_km'); ?>">
            <input type="hidden" name="route_polyline" data-route-polyline-input value="<?php echo ridesync_post_ride_old_value($rideFormOld, 'route_polyline'); ?>">
        </section>

        <div class="form-group">
            <label for="travel_date">Travel Date</label>
            <input type="date" id="travel_date" name="travel_date" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo ridesync_post_ride_old_value($rideFormOld, 'travel_date'); ?>">
        </div>

        <div class="form-group">
            <label for="travel_time">Departure Time</label>
            <input type="time" id="travel_time" name="travel_time" required value="<?php echo ridesync_post_ride_old_value($rideFormOld, 'travel_time'); ?>">
        </div>

        <div class="form-group">
            <label for="seats_available">Available Seats</label>
            <select id="seats_available" name="seats_available" required>
                <?php for ($seats = 1; $seats <= 5; $seats++): ?>
                    <option value="<?php echo $seats; ?>" <?php echo $oldSeats === $seats ? 'selected' : ''; ?>>
                        <?php echo $seats; ?> <?php echo $seats === 1 ? 'seat' : 'seats'; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">Post Ride</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
