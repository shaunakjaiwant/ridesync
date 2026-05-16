<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/driver_account_helper.php';

ridesync_require_driver_login();

$driverId = (int) $_SESSION['driver_id'];
$state = ridesync_fetch_driver_state($conn, $driverId);
$account = $state['account'];
$profile = $state['profile'];
$vehicle = $state['vehicle'];

$documentsByType = [];
$stmt = mysqli_prepare($conn,
    "SELECT *
     FROM driver_account_documents
     WHERE driver_id = ?
     ORDER BY id DESC"
);
mysqli_stmt_bind_param($stmt, "i", $driverId);
mysqli_stmt_execute($stmt);
$documentsResult = mysqli_stmt_get_result($stmt);
while ($document = mysqli_fetch_assoc($documentsResult)) {
    $documentType = $document['document_type'];
    if (!isset($documentsByType[$documentType])) {
        $documentsByType[$documentType] = $document;
    }
}

require_once __DIR__ . '/../includes/driver_header.php';
?>

<div class="driver-page-header">
    <div>
        <span class="driver-kicker">Profile</span>
        <h1>Driver profile</h1>
    </div>
    <span class="driver-status-pill driver-status-<?php echo htmlspecialchars($state['availability']); ?>">
        <?php echo $state['availability'] === 'online' ? 'Online' : 'Offline'; ?>
    </span>
</div>

<?php ridesync_flash('driver_success', 'alert-success'); ?>
<?php ridesync_flash('driver_error', 'alert-error'); ?>

<div class="driver-profile-grid">
    <section class="driver-panel">
        <h2>Account Status</h2>
        <div class="driver-list compact">
            <div class="driver-list-item">
                <span>Account</span>
                <strong><?php echo ucfirst(htmlspecialchars($account['status'] ?? 'active')); ?></strong>
            </div>
            <div class="driver-list-item">
                <span>Verification</span>
                <strong><?php echo ridesync_driver_is_verified($state) ? 'Verified' : ucfirst(htmlspecialchars($profile['verification_status'] ?? 'pending')); ?></strong>
            </div>
            <div class="driver-list-item">
                <span>Vehicle</span>
                <strong><?php echo htmlspecialchars($vehicle['vehicle_number'] ?? 'Not added'); ?></strong>
            </div>
            <div class="driver-list-item">
                <span>Documents</span>
                <strong>
                    <?php
                    $verifiedDocs = 0;
                    foreach (['license', 'aadhaar', 'pan', 'vehicle_rc', 'insurance', 'selfie', 'vehicle_image'] as $requiredType) {
                        if (($state['documents'][$requiredType]['verification_status'] ?? '') === 'verified') {
                            $verifiedDocs++;
                        }
                    }
                    echo $verifiedDocs . '/7 verified';
                    ?>
                </strong>
            </div>
        </div>
    </section>

    <section class="form-container driver-profile-form">
        <h2>Vehicle & Documents</h2>
        <form action="/ridesync/actions/driver_account_action.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action_type" value="update_profile">

            <div class="form-group">
                <label for="name">Full Name</label>
                <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($account['name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" required value="<?php echo htmlspecialchars($account['phone'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="license_number">License Number</label>
                <input type="text" id="license_number" name="license_number" required value="<?php echo htmlspecialchars($profile['license_number'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="vehicle_type">Vehicle Type</label>
                <select id="vehicle_type" name="vehicle_type" required>
                    <?php
                    $selectedType = $vehicle['vehicle_type'] ?? 'Car';
                    foreach (['Bike', 'Car', 'Auto', 'Van', 'Other'] as $type):
                    ?>
                        <option value="<?php echo $type; ?>" <?php echo $selectedType === $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="vehicle_number">Vehicle Number</label>
                <input type="text" id="vehicle_number" name="vehicle_number" required value="<?php echo htmlspecialchars($vehicle['vehicle_number'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="seating_capacity">Passenger Seats</label>
                <select id="seating_capacity" name="seating_capacity" required>
                    <?php
                    $selectedSeats = (int) ($vehicle['seating_capacity'] ?? 4);
                    for ($i = 1; $i <= 8; $i++):
                    ?>
                        <option value="<?php echo $i; ?>" <?php echo $selectedSeats === $i ? 'selected' : ''; ?>><?php echo $i; ?> seat<?php echo $i === 1 ? '' : 's'; ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="document_reference">License Document Reference</label>
                <input type="text" id="document_reference" name="document_reference" value="<?php echo htmlspecialchars($documentsByType['license']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="license_file">Upload license document</label>
                <input type="file" id="license_file" name="license_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="aadhaar_reference">Aadhaar Card Reference</label>
                <input type="text" id="aadhaar_reference" name="aadhaar_reference" value="<?php echo htmlspecialchars($documentsByType['aadhaar']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="aadhaar_file">Upload Aadhaar card</label>
                <input type="file" id="aadhaar_file" name="aadhaar_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="pan_reference">PAN Card Reference</label>
                <input type="text" id="pan_reference" name="pan_reference" value="<?php echo htmlspecialchars($documentsByType['pan']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="pan_file">Upload PAN card</label>
                <input type="file" id="pan_file" name="pan_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="id_proof_reference">ID Proof Reference</label>
                <input type="text" id="id_proof_reference" name="id_proof_reference" value="<?php echo htmlspecialchars($documentsByType['id_proof']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="id_proof_file">Upload ID proof document</label>
                <input type="file" id="id_proof_file" name="id_proof_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="vehicle_rc_reference">Vehicle RC Reference</label>
                <input type="text" id="vehicle_rc_reference" name="vehicle_rc_reference" value="<?php echo htmlspecialchars($documentsByType['vehicle_rc']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="vehicle_rc_file">Upload vehicle RC document</label>
                <input type="file" id="vehicle_rc_file" name="vehicle_rc_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="insurance_reference">Insurance Reference</label>
                <input type="text" id="insurance_reference" name="insurance_reference" value="<?php echo htmlspecialchars($documentsByType['insurance']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="insurance_file">Upload insurance document</label>
                <input type="file" id="insurance_file" name="insurance_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="selfie_reference">Selfie Reference</label>
                <input type="text" id="selfie_reference" name="selfie_reference" value="<?php echo htmlspecialchars($documentsByType['selfie']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="selfie_file">Upload selfie</label>
                <input type="file" id="selfie_file" name="selfie_file" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="vehicle_image_reference">Vehicle Image Reference</label>
                <input type="text" id="vehicle_image_reference" name="vehicle_image_reference" value="<?php echo htmlspecialchars($documentsByType['vehicle_image']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="vehicle_image_file">Upload vehicle image</label>
                <input type="file" id="vehicle_image_file" name="vehicle_image_file" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="other_document_reference">Other Verification Document</label>
                <input type="text" id="other_document_reference" name="other_document_reference" value="<?php echo htmlspecialchars($documentsByType['other']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="other_file">Upload other verification document</label>
                <input type="file" id="other_file" name="other_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="verification_details">Verification Details</label>
                <textarea id="verification_details" name="verification_details"><?php echo htmlspecialchars($profile['verification_details'] ?? ''); ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;">Save Profile</button>
        </form>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/driver_footer.php'; ?>
