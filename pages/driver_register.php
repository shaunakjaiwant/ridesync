<?php
require_once __DIR__ . '/../config/db.php';

$_SESSION['selected_role'] = 'driver';
$driverRegisterOld = $_SESSION['driver_register_old'] ?? [];
unset($_SESSION['driver_register_old']);

function ridesync_driver_register_old_value(array $oldInput, string $key, string $default = ''): string
{
    return htmlspecialchars((string) ($oldInput[$key] ?? $default));
}

$oldVehicleType = (string) ($driverRegisterOld['vehicle_type'] ?? 'Car');
if (!in_array($oldVehicleType, ['Bike', 'Car', 'Auto', 'Van', 'Other'], true)) {
    $oldVehicleType = 'Car';
}

$oldSeats = (int) ($driverRegisterOld['seating_capacity'] ?? 4);
if ($oldSeats < 1 || $oldSeats > 8) {
    $oldSeats = 4;
}

require_once __DIR__ . '/../includes/public_header.php';
?>

<div class="form-container auth-register-form">
    <h2>Register as a Driver</h2>

    <?php ridesync_flash('driver_register_error', 'alert-error'); ?>

    <form action="/ridesync/actions/driver_auth_action.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action_type" value="register">

        <div class="form-group">
            <label for="name">Full Name</label>
            <input type="text" id="name" name="name" required placeholder="Driver name" value="<?php echo ridesync_driver_register_old_value($driverRegisterOld, 'name'); ?>">
        </div>

        <div class="form-group">
            <label for="email">Driver Email</label>
            <input type="email" id="email" name="email" required placeholder="driver@example.com" value="<?php echo ridesync_driver_register_old_value($driverRegisterOld, 'email'); ?>">
        </div>

        <div class="form-group">
            <label for="phone">Phone Number</label>
            <input type="tel" id="phone" name="phone" required placeholder="9876543210" value="<?php echo ridesync_driver_register_old_value($driverRegisterOld, 'phone'); ?>">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required minlength="8" placeholder="Min 8 characters">
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8" placeholder="Re-enter password">
        </div>

        <div class="form-group">
            <label for="license_number">License Number</label>
            <input type="text" id="license_number" name="license_number" required maxlength="80" placeholder="Driving license number" value="<?php echo ridesync_driver_register_old_value($driverRegisterOld, 'license_number'); ?>">
        </div>

        <div class="form-group">
            <label for="vehicle_type">Vehicle Type</label>
            <select id="vehicle_type" name="vehicle_type" required>
                <?php foreach (['Bike', 'Car', 'Auto', 'Van', 'Other'] as $type): ?>
                    <option value="<?php echo $type; ?>" <?php echo $oldVehicleType === $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="vehicle_number">Vehicle Number</label>
            <input type="text" id="vehicle_number" name="vehicle_number" required maxlength="40" placeholder="KA 19 AB 1234" value="<?php echo ridesync_driver_register_old_value($driverRegisterOld, 'vehicle_number'); ?>">
        </div>

        <div class="form-group">
            <label for="seating_capacity">Passenger Seats</label>
            <select id="seating_capacity" name="seating_capacity" required>
                <?php for ($i = 1; $i <= 8; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php echo $i === $oldSeats ? 'selected' : ''; ?>><?php echo $i; ?> seat<?php echo $i === 1 ? '' : 's'; ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="form-group form-group-wide">
            <label for="document_reference">License Document Reference</label>
            <input type="text" id="document_reference" name="document_reference" maxlength="255" placeholder="File name, document ID, or note" value="<?php echo ridesync_driver_register_old_value($driverRegisterOld, 'document_reference'); ?>">
            <label class="sr-only" for="license_file">Upload license document</label>
            <input type="file" id="license_file" name="license_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
        </div>

        <div class="form-group form-group-wide">
            <label for="aadhaar_reference">Aadhaar Card Reference</label>
            <input type="text" id="aadhaar_reference" name="aadhaar_reference" maxlength="255" placeholder="Masked Aadhaar note or uploaded file reference" value="<?php echo ridesync_driver_register_old_value($driverRegisterOld, 'aadhaar_reference'); ?>">
            <label class="sr-only" for="aadhaar_file">Upload Aadhaar card</label>
            <input type="file" id="aadhaar_file" name="aadhaar_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
        </div>

        <div class="form-group form-group-wide">
            <label for="pan_reference">PAN Card Reference</label>
            <input type="text" id="pan_reference" name="pan_reference" maxlength="255" placeholder="PAN note or uploaded file reference" value="<?php echo ridesync_driver_register_old_value($driverRegisterOld, 'pan_reference'); ?>">
            <label class="sr-only" for="pan_file">Upload PAN card</label>
            <input type="file" id="pan_file" name="pan_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
        </div>

        <div class="form-group form-group-wide">
            <label for="id_proof_reference">ID Proof Reference</label>
            <input type="text" id="id_proof_reference" name="id_proof_reference" maxlength="255" placeholder="Student/local ID, Aadhaar note, or uploaded file reference" value="<?php echo ridesync_driver_register_old_value($driverRegisterOld, 'id_proof_reference'); ?>">
            <label class="sr-only" for="id_proof_file">Upload ID proof document</label>
            <input type="file" id="id_proof_file" name="id_proof_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
        </div>

        <div class="form-group form-group-wide">
            <label for="vehicle_rc_reference">Vehicle RC Reference</label>
            <input type="text" id="vehicle_rc_reference" name="vehicle_rc_reference" maxlength="255" placeholder="RC document ID, file name, or note" value="<?php echo ridesync_driver_register_old_value($driverRegisterOld, 'vehicle_rc_reference'); ?>">
            <label class="sr-only" for="vehicle_rc_file">Upload vehicle RC document</label>
            <input type="file" id="vehicle_rc_file" name="vehicle_rc_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
        </div>

        <div class="form-group form-group-wide">
            <label for="insurance_reference">Insurance Reference</label>
            <input type="text" id="insurance_reference" name="insurance_reference" maxlength="255" placeholder="Insurance policy/file reference" value="<?php echo ridesync_driver_register_old_value($driverRegisterOld, 'insurance_reference'); ?>">
            <label class="sr-only" for="insurance_file">Upload insurance document</label>
            <input type="file" id="insurance_file" name="insurance_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
        </div>

        <div class="form-group form-group-wide">
            <label for="selfie_reference">Selfie Reference</label>
            <input type="text" id="selfie_reference" name="selfie_reference" maxlength="255" placeholder="Selfie file reference or note" value="<?php echo ridesync_driver_register_old_value($driverRegisterOld, 'selfie_reference'); ?>">
            <label class="sr-only" for="selfie_file">Upload selfie</label>
            <input type="file" id="selfie_file" name="selfie_file" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
        </div>

        <div class="form-group form-group-wide">
            <label for="vehicle_image_reference">Vehicle Image Reference</label>
            <input type="text" id="vehicle_image_reference" name="vehicle_image_reference" maxlength="255" placeholder="Vehicle image file reference or note" value="<?php echo ridesync_driver_register_old_value($driverRegisterOld, 'vehicle_image_reference'); ?>">
            <label class="sr-only" for="vehicle_image_file">Upload vehicle image</label>
            <input type="file" id="vehicle_image_file" name="vehicle_image_file" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
        </div>

        <div class="form-group form-group-wide">
            <label for="other_document_reference">Other Verification Document</label>
            <input type="text" id="other_document_reference" name="other_document_reference" maxlength="255" placeholder="Any extra verification file or note" value="<?php echo ridesync_driver_register_old_value($driverRegisterOld, 'other_document_reference'); ?>">
            <label class="sr-only" for="other_file">Upload other verification document</label>
            <input type="file" id="other_file" name="other_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
        </div>

        <div class="form-group form-group-wide">
            <label for="verification_details">Verification Details</label>
            <textarea id="verification_details" name="verification_details" placeholder="Add any detail needed for verification."><?php echo ridesync_driver_register_old_value($driverRegisterOld, 'verification_details'); ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">Create Driver Account</button>
    </form>

    <p style="text-align:center; margin-top:15px; color:#777;">
        Already registered? <a href="/ridesync/pages/driver_login.php" style="color:#4361ee;">Driver Login</a>
    </p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
