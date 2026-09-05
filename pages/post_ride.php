<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/asset_helper.php';
require_once __DIR__ . '/../includes/rider_experience_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

$rideFormOld = $_SESSION['ride_form_old'] ?? [];
unset($_SESSION['ride_form_old']);

$rebookRideId = isset($_GET['rebook_ride_id']) ? (int) $_GET['rebook_ride_id'] : 0;
if ($rebookRideId > 0) {
    $rebookPrefill = ridesync_fetch_rebook_prefill($conn, (int) $_SESSION['user_id'], $rebookRideId);
    if ($rebookPrefill !== null) {
        $rideFormOld = array_merge($rebookPrefill, $rideFormOld);
        $_SESSION['ride_success'] = 'Route loaded. Choose the date/time you want and post again.';
    } else {
        $_SESSION['ride_error'] = 'Could not load that previous ride for rebooking.';
    }
} elseif (isset($_GET['origin'], $_GET['destination']) && $rideFormOld === []) {
    $rideFormOld['origin'] = substr(trim((string) $_GET['origin']), 0, 150);
    $rideFormOld['destination'] = substr(trim((string) $_GET['destination']), 0, 150);
}

function ridesync_post_ride_old_value(array $oldInput, string $key, string $default = ''): string
{
    return htmlspecialchars((string) ($oldInput[$key] ?? $default));
}

$oldSeats = (int) ($rideFormOld['seats_available'] ?? 1);
if ($oldSeats < 1 || $oldSeats > 5) {
    $oldSeats = 1;
}

$routeShortcuts = ridesync_build_rider_route_shortcuts($conn, (int) $_SESSION['user_id'], 6);

ridesync_enable_map_assets();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-container ride-form-container">
    <h1>Post a Ride</h1>

    <?php ridesync_flash('ride_error', 'alert-error'); ?>
    <?php ridesync_flash('ride_success', 'alert-success'); ?>

    <?php if (count($routeShortcuts) > 0): ?>
        <section class="smart-shortcut-panel">
            <div class="smart-shortcut-head">
                <span class="fare-kicker">Smart ride suggestions</span>
                <h2>Post faster from your usual routes</h2>
                <p>Use a frequent, recent, college, or return route shortcut. You can still edit every field before posting.</p>
            </div>
            <div class="smart-shortcut-grid">
                <?php foreach ($routeShortcuts as $shortcut): ?>
                    <?php $shortcutExtra = (int) ($shortcut['sample_ride_id'] ?? 0) > 0 ? ['rebook_ride_id' => (int) $shortcut['sample_ride_id']] : []; ?>
                    <a class="smart-route-chip" href="/ridesync/pages/post_ride.php?<?php echo htmlspecialchars(ridesync_route_query($shortcut, $shortcutExtra)); ?>">
                        <span><?php echo htmlspecialchars($shortcut['label']); ?></span>
                        <strong><?php echo htmlspecialchars($shortcut['origin']); ?> &rarr; <?php echo htmlspecialchars($shortcut['destination']); ?></strong>
                        <small><?php echo htmlspecialchars($shortcut['meta']); ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <form action="/ridesync/actions/post_ride_action.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="form-group">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 0.35rem;">
                <label for="origin" style="margin-bottom: 0; white-space: nowrap;">Departure Location</label>
                <button type="button" class="btn btn-secondary btn-sm" data-use-current-location-departure style="font-size: 0.78rem; padding: 0.2rem 0.6rem; min-height: 32px; display: inline-flex; align-items: center; gap: 0.3rem; white-space: nowrap;">
                    <svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                    Use My Location
                </button>
            </div>
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
                    <h2>Set departure and destination on map</h2>
                    <p>Type in Departure/Destination for suggestions, use current location, or click the map to place pins.</p>
                </div>
            </div>

            <div class="map-pin-readiness-banner" style="display: flex; gap: 1rem; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0.6rem 1rem; margin: 0.75rem 0; align-items: center; justify-content: space-between; flex-wrap: wrap;">
                <div style="font-size: 0.88rem; font-weight: 500; color: #334155;">
                    <span style="margin-right: 1rem;" data-pin-origin-badge>Departure Pin: <strong style="color: #d97706;">Not set</strong></span>
                    <span data-pin-destination-badge>Destination Pin: <strong style="color: #d97706;">Not set</strong></span>
                </div>
                <span style="font-size: 0.78rem; color: #64748b; font-weight: 500; display: inline-flex; align-items: center; gap: 0.3rem;">
                    <svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3L12 3Z"/></svg>
                    Typing locations auto-places map pins
                </span>
            </div>

            <div class="map-picker-tools">
                <button type="button" class="btn btn-secondary btn-sm is-active" data-map-mode="origin">Set departure</button>
                <button type="button" class="btn btn-secondary btn-sm" data-map-mode="destination">Set destination</button>
                <button type="button" class="btn btn-secondary btn-sm" data-map-search-origin>Find departure</button>
                <button type="button" class="btn btn-secondary btn-sm" data-map-search-destination>Find destination</button>
                <button type="button" class="btn btn-secondary btn-sm" data-map-swap>Swap</button>
                <button type="button" class="btn btn-secondary btn-sm" data-map-clear>Clear</button>
            </div>

            <div id="rideMapPicker" class="ride-map" data-map-canvas role="region" aria-label="Interactive map for setting departure and destination"></div>

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
