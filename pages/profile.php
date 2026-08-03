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

require_once __DIR__ . '/../includes/emergency_contact_helper.php';
$emergencyContacts = ridesync_get_user_emergency_contacts($conn, 'rider', (int) $user_id);

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

<!-- Emergency Contacts Card -->
<div class="form-container" style="margin-top: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <div>
            <h2 style="margin-bottom: 0.25rem;">Emergency Contacts 🛡️</h2>
            <p style="color: #94a3b8; font-size: 0.88rem; margin: 0;">Add up to 3 contacts who will be notified during emergency SOS triggers.</p>
        </div>
        <span class="status-badge" style="background: rgba(56,189,248,0.15); color: #38bdf8; font-weight: 600;">
            <?php echo count($emergencyContacts); ?>/3 Saved
        </span>
    </div>

    <?php if (!empty($emergencyContacts)): ?>
        <div style="display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1.5rem;">
            <?php foreach ($emergencyContacts as $contact): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(15,23,42,0.6); border: 1px solid rgba(255,255,255,0.08); padding: 0.9rem 1.1rem; border-radius: 10px;">
                    <div>
                        <strong style="color: #f8fafc; font-size: 0.95rem;"><?php echo htmlspecialchars($contact['name']); ?></strong>
                        <span style="color: #94a3b8; font-size: 0.85rem; margin-left: 0.4rem;">(<?php echo htmlspecialchars($contact['relationship']); ?>)</span>
                        <?php if (!empty($contact['is_primary'])): ?>
                            <span class="status-badge status-accepted" style="margin-left: 0.5rem; font-size: 0.75rem;">Primary</span>
                        <?php endif; ?>
                        <div style="color: #38bdf8; font-size: 0.88rem; margin-top: 0.2rem; font-weight: 500;">
                            <?php echo htmlspecialchars($contact['phone_number']); ?>
                        </div>
                    </div>
                    <form action="/ridesync/actions/emergency_contact_action.php" method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action_type" value="delete">
                        <input type="hidden" name="contact_id" value="<?php echo (int) $contact['id']; ?>">
                        <button type="submit" class="btn btn-secondary btn-sm" style="color: #f87171; border-color: rgba(248,113,113,0.3);" data-confirm-message="Delete emergency contact <?php echo htmlspecialchars($contact['name']); ?>?">Delete</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (count($emergencyContacts) < 3): ?>
        <form method="POST" action="/ridesync/actions/emergency_contact_action.php" style="background: rgba(15,23,42,0.4); border: 1px dashed rgba(255,255,255,0.12); padding: 1.25rem; border-radius: 10px;">
            <input type="hidden" name="action_type" value="add">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="contact_name">Full Name</label>
                    <input type="text" id="contact_name" name="name" placeholder="e.g. Parent / Spouse Name" required>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="contact_relation">Relationship</label>
                    <select id="contact_relation" name="relationship" required>
                        <option value="Parent">Parent</option>
                        <option value="Spouse">Spouse</option>
                        <option value="Sibling">Sibling</option>
                        <option value="Friend">Friend / Guardian</option>
                        <option value="Campus Security">Campus Security</option>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-top: 1rem; margin-bottom: 1rem;">
                <label for="contact_phone">Phone Number</label>
                <input type="tel" id="contact_phone" name="phone_number" placeholder="e.g. +91 9876543210" required>
            </div>

            <button type="submit" class="btn btn-secondary" style="width: 100%;">Add Emergency Contact</button>
        </form>
    <?php endif; ?>
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
