<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/email_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/profile.php");
    exit();
}

if (!ridesync_csrf_is_valid()) {
    $_SESSION['profile_error'] = "Invalid request. Please try again.";
    header("Location: /ridesync/pages/profile.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$action = $_POST['action_type'] ?? '';

// =============================================
// HANDLE PROFILE UPDATE
// =============================================
if ($action === 'update_profile') {
    $name    = trim($_POST['name'] ?? '');
    $college = trim($_POST['college'] ?? '');
    $gender  = $_POST['gender'] ?? '';
    $removeProfilePhoto = ($_POST['remove_profile_photo'] ?? '') === '1';

    // Validate
    if ($name === '' || $college === '' || $gender === '') {
        $_SESSION['profile_error'] = "All fields are required.";
        header("Location: /ridesync/pages/profile.php");
        exit();
    }

    if (strlen($name) > 100 || strlen($college) > 150) {
        $_SESSION['profile_error'] = "Name or college value is too long.";
        header("Location: /ridesync/pages/profile.php");
        exit();
    }

    if (!in_array($gender, ['Male', 'Female', 'Other'])) {
        $_SESSION['profile_error'] = "Invalid gender selection.";
        header("Location: /ridesync/pages/profile.php");
        exit();
    }

    $stmt = $conn->prepare("SELECT profile_photo FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $currentUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$currentUser) {
        $_SESSION['profile_error'] = "Profile not found. Please login again.";
        header("Location: /ridesync/pages/login.php");
        exit();
    }

    $profilePhoto = $currentUser['profile_photo'] ?? null;
    $oldProfilePhoto = $profilePhoto;

    if ($removeProfilePhoto) {
        $profilePhoto = null;
    }

    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['profile_error'] = "Profile photo upload failed. Please try another image.";
            header("Location: /ridesync/pages/profile.php");
            exit();
        }

        if ($_FILES['profile_photo']['size'] > 2 * 1024 * 1024) {
            $_SESSION['profile_error'] = "Profile photo must be 2 MB or smaller.";
            header("Location: /ridesync/pages/profile.php");
            exit();
        }

        $imageInfo = getimagesize($_FILES['profile_photo']['tmp_name']);
        $allowedTypes = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp'
        ];

        if (!$imageInfo || !isset($allowedTypes[$imageInfo[2]])) {
            $_SESSION['profile_error'] = "Upload a valid JPG, PNG, WEBP, or GIF image.";
            header("Location: /ridesync/pages/profile.php");
            exit();
        }

        $uploadDir = __DIR__ . '/../uploads/profile_photos';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            $_SESSION['profile_error'] = "Could not prepare the profile photo folder.";
            header("Location: /ridesync/pages/profile.php");
            exit();
        }

        $extension = $allowedTypes[$imageInfo[2]];
        $fileName = 'user_' . (int) $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $targetPath = $uploadDir . '/' . $fileName;

        if (!move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetPath)) {
            $_SESSION['profile_error'] = "Could not save the uploaded profile photo.";
            header("Location: /ridesync/pages/profile.php");
            exit();
        }

        $profilePhoto = 'uploads/profile_photos/' . $fileName;
    }

    $stmt = $conn->prepare("UPDATE users SET name = ?, college = ?, gender = ?, profile_photo = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $name, $college, $gender, $profilePhoto, $user_id);

    if ($stmt->execute()) {
        if ($oldProfilePhoto && $oldProfilePhoto !== $profilePhoto) {
            $oldPath = realpath(__DIR__ . '/../' . $oldProfilePhoto);
            $uploadRoot = realpath(__DIR__ . '/../uploads/profile_photos');
            if ($oldPath && $uploadRoot && str_starts_with($oldPath, $uploadRoot) && is_file($oldPath)) {
                unlink($oldPath);
            }
        }

        // Update session name in case it's displayed somewhere
        $_SESSION['user_name'] = $name;
        $_SESSION['profile_success'] = "Profile updated successfully!";
    } else {
        $_SESSION['profile_error'] = "Something went wrong. Try again.";
    }
    $stmt->close();

    header("Location: /ridesync/pages/profile.php");
    exit();
}

// =============================================
// HANDLE PASSWORD CHANGE
// =============================================
if ($action === 'change_password') {
    $current  = $_POST['current_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($current) || empty($new_pass) || empty($confirm)) {
        $_SESSION['profile_error'] = "All password fields are required.";
        header("Location: /ridesync/pages/profile.php");
        exit();
    }

    if ($new_pass !== $confirm) {
        $_SESSION['profile_error'] = "New passwords don't match.";
        header("Location: /ridesync/pages/profile.php");
        exit();
    }

    if (strlen($new_pass) < 8) {
        $_SESSION['profile_error'] = "New password must be at least 8 characters.";
        header("Location: /ridesync/pages/profile.php");
        exit();
    }

    // Fetch current hash
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $_SESSION['profile_error'] = "Profile not found. Please login again.";
        header("Location: /ridesync/pages/login.php");
        exit();
    }

    if (!password_verify($current, $row['password'])) {
        $_SESSION['profile_error'] = "Current password is incorrect.";
        header("Location: /ridesync/pages/profile.php");
        exit();
    }

    // Hash and save new password
    $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $new_hash, $user_id);

    if ($stmt->execute()) {
        $_SESSION['profile_success'] = "Password changed successfully!";
    } else {
        $_SESSION['profile_error'] = "Something went wrong. Try again.";
    }
    $stmt->close();

    header("Location: /ridesync/pages/profile.php");
    exit();
}

// =============================================
// HANDLE STUDENT / COMMUNITY VERIFICATION
// =============================================
if ($action === 'student_verification') {
    $verificationType = $_POST['verification_type'] ?? '';
    $reference = trim($_POST['verification_reference'] ?? '');
    $allowedTypes = ['college_email', 'student_id', 'manual'];

    if (!in_array($verificationType, $allowedTypes, true) || $reference === '') {
        $_SESSION['profile_error'] = "Choose a verification method and enter a reference.";
        header("Location: /ridesync/pages/profile.php");
        exit();
    }

    if ($verificationType === 'college_email' && !ridesync_is_valid_email($reference)) {
        $_SESSION['profile_error'] = "Enter a valid college email address.";
        header("Location: /ridesync/pages/profile.php");
        exit();
    }

    if ($verificationType !== 'college_email' && strlen($reference) < 4) {
        $_SESSION['profile_error'] = "Verification reference is too short.";
        header("Location: /ridesync/pages/profile.php");
        exit();
    }

    $verificationTable = mysqli_query($conn, "SHOW TABLES LIKE 'user_verifications'");
    if (!$verificationTable || mysqli_num_rows($verificationTable) === 0) {
        $_SESSION['profile_error'] = "Verification table is not ready yet.";
        header("Location: /ridesync/pages/profile.php");
        exit();
    }

    $stmt = $conn->prepare("
        INSERT INTO user_verifications (user_id, verification_type, status, reference)
        VALUES (?, ?, 'pending', ?)
        ON DUPLICATE KEY UPDATE status = 'pending', reference = VALUES(reference), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param("iss", $user_id, $verificationType, $reference);

    if ($stmt->execute()) {
        $_SESSION['profile_success'] = "Verification submitted for review.";
    } else {
        $_SESSION['profile_error'] = "Could not submit verification. Try again.";
    }
    $stmt->close();

    header("Location: /ridesync/pages/profile.php");
    exit();
}

// If no valid action, go back
header("Location: /ridesync/pages/profile.php");
exit();
?>
