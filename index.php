<?php
require_once __DIR__ . '/config/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}

if (isset($_SESSION['driver_id'])) {
    header("Location: /ridesync/pages/driver_dashboard.php");
    exit();
}

require_once 'includes/public_header.php';
?>

        <div class="page-header">
            <h1>Choose how you want to use RideSync</h1>
            <p>Riders and drivers use separate accounts, so each experience stays clear.</p>
        </div>

        <div class="role-card-grid">
            <a href="/ridesync/pages/login.php?role=rider" class="role-card rider-card">
                <span class="role-icon">Ride</span>
                <h2>Continue as Rider</h2>
                <p>Find shared rides, send join requests, and manage your student ride activity.</p>
                <strong>Login or sign up as Rider</strong>
            </a>

            <a href="/ridesync/pages/driver_login.php" class="role-card driver-card">
                <span class="role-icon">Drive</span>
                <h2>Continue as Driver</h2>
                <p>Use a dedicated driver account to go online, receive requests, and track earnings.</p>
                <strong>Login or sign up as Driver</strong>
            </a>
        </div>


</main>

<?php require_once 'includes/footer.php'; ?>
