<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/college_suggestions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

require_once __DIR__ . '/../includes/header.php';

$user_id = $_SESSION['user_id'];

// Fetch current user data
$stmt = $conn->prepare("SELECT name, email, college, gender, profile_photo, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$profilePhoto = trim($user['profile_photo'] ?? '');
$profilePhotoUrl = $profilePhoto !== '' ? '/ridesync/' . ltrim($profilePhoto, '/') : '';
$initial = strtoupper(substr($user['name'] ?? 'U', 0, 1));

// Fetch stats in one round trip instead of three separate count queries.
$statsStmt = $conn->prepare("
    SELECT
        (SELECT COUNT(*) FROM rides WHERE user_id = ?) AS total_rides,
        (SELECT COUNT(*) FROM matches WHERE matched_user_id = ?) AS total_matches,
        (SELECT COUNT(*) FROM matches WHERE matched_user_id = ? AND status = 'accepted') AS total_accepted
");
$statsStmt->bind_param("iii", $user_id, $user_id, $user_id);
$statsStmt->execute();
$profileStats = $statsStmt->get_result()->fetch_assoc() ?: [];
$statsStmt->close();
$total_rides = (int) ($profileStats['total_rides'] ?? 0);
$total_matches = (int) ($profileStats['total_matches'] ?? 0);
$total_accepted = (int) ($profileStats['total_accepted'] ?? 0);

$verification = null;
try {
    $stmt = $conn->prepare("
        SELECT *
        FROM user_verifications
        WHERE user_id = ?
        ORDER BY FIELD(status, 'verified', 'pending', 'rejected'), updated_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $verification = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $exception) {
    $verification = null;
}

$verificationStatus = $verification['status'] ?? 'not_started';
$verificationLabel = [
    'verified' => 'Verified',
    'pending' => 'Pending Review',
    'rejected' => 'Needs Update',
    'not_started' => 'Not Started',
][$verificationStatus] ?? 'Not Started';
?>

<div class="page-header">
    <h1>My Profile</h1>
    <p>View and update your information.</p>
</div>

<?php ridesync_flash('profile_success', 'alert-success'); ?>
<?php ridesync_flash('profile_error', 'alert-error'); ?>

<!-- Edit Profile Form -->
<div class="form-container">
    <h2>Edit Profile</h2>
    <form method="POST" action="/ridesync/actions/profile_action.php" enctype="multipart/form-data">
        <input type="hidden" name="action_type" value="update_profile">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="profile-photo-editor">
            <div class="profile-avatar-preview">
                <?php if ($profilePhotoUrl !== ''): ?>
                    <img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" alt="Profile picture">
                <?php else: ?>
                    <span><?php echo htmlspecialchars($initial); ?></span>
                <?php endif; ?>
            </div>
            <div class="profile-photo-controls">
                <label for="profile_photo">Profile Picture</label>
                <input type="file" id="profile_photo" name="profile_photo" accept="image/jpeg,image/png,image/webp,image/gif">
                <p>Upload JPG, PNG, WEBP, or GIF up to 2 MB.</p>
                <?php if ($profilePhotoUrl !== ''): ?>
                    <label class="checkbox-line">
                        <input type="checkbox" name="remove_profile_photo" value="1">
                        Remove current photo
                    </label>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <label for="name">Full Name</label>
            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
        </div>

        <div class="form-group">
            <label for="email">Email (cannot be changed)</label>
            <input type="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
        </div>

        <div class="form-group">
            <label for="college">College / University</label>
            <input type="text" id="college" name="college" list="college-suggestions" value="<?php echo htmlspecialchars($user['college']); ?>" required>
            <?php ridesync_render_college_datalist(); ?>
        </div>

        <div class="form-group">
            <label for="gender">Gender</label>
            <select id="gender" name="gender" required>
                <option value="Male" <?php echo $user['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                <option value="Female" <?php echo $user['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                <option value="Other" <?php echo $user['gender'] === 'Other' ? 'selected' : ''; ?>>Other</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">Save Changes</button>
    </form>
</div>

<!-- Trust Verification -->
<div class="form-container trust-verification-card" style="margin-top: 24px;">
    <div class="trust-card-header">
        <div>
            <span class="fare-kicker">Trust layer</span>
            <h2>Student Verification</h2>
            <p>Verified student/community status improves trust score for smart matching.</p>
        </div>
        <span class="verification-pill verification-<?php echo htmlspecialchars($verificationStatus); ?>">
            <?php echo htmlspecialchars($verificationLabel); ?>
        </span>
    </div>

    <?php if ($verification): ?>
        <div class="verification-current">
            <span>Current method</span>
            <strong><?php echo ucwords(str_replace('_', ' ', htmlspecialchars($verification['verification_type']))); ?></strong>
            <span>Reference</span>
            <strong><?php echo htmlspecialchars($verification['reference'] ?: 'Not provided'); ?></strong>
        </div>
    <?php endif; ?>

    <form method="POST" action="/ridesync/actions/profile_action.php">
        <input type="hidden" name="action_type" value="student_verification">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="form-group">
            <label for="verification_type">Verification Method</label>
            <select id="verification_type" name="verification_type" required>
                <option value="college_email">College Email</option>
                <option value="student_id">Student ID Reference</option>
                <option value="manual">Manual Community Check</option>
            </select>
        </div>

        <div class="form-group">
            <label for="verification_reference">Email / ID / Reference</label>
            <input type="text" id="verification_reference" name="verification_reference" required maxlength="255" placeholder="you@college.edu or student ID">
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">Submit Verification</button>
    </form>
</div>

<!-- Change Password Form -->
<div class="form-container" style="margin-top: 24px;">
    <h2>Change Password</h2>
    <form method="POST" action="/ridesync/actions/profile_action.php">
        <input type="hidden" name="action_type" value="change_password">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="form-group">
            <label for="current_password">Current Password</label>
            <input type="password" id="current_password" name="current_password" required>
        </div>

        <div class="form-group">
            <label for="new_password">New Password (min 8 characters)</label>
            <input type="password" id="new_password" name="new_password" minlength="8" required>
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm New Password</label>
            <input type="password" id="confirm_password" name="confirm_password" minlength="8" required>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">Update Password</button>
    </form>
</div>

<!-- Stats Cards -->
<div class="profile-stats-bottom stats-row">
    <div class="stat-card">
        <span class="stat-number"><?php echo $total_rides; ?></span>
        <span class="stat-label">Rides Posted</span>
    </div>
    <div class="stat-card">
        <span class="stat-number"><?php echo $total_matches; ?></span>
        <span class="stat-label">Join Requests Sent</span>
    </div>
    <div class="stat-card">
        <span class="stat-number"><?php echo $total_accepted; ?></span>
        <span class="stat-label">Accepted</span>
    </div>
    <div class="stat-card">
        <span class="stat-number"><?php echo date('M Y', strtotime($user['created_at'])); ?></span>
        <span class="stat-label">Member Since</span>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
