<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/driver_account_helper.php';
require_once __DIR__ . '/../includes/emergency_contact_helper.php';

ridesync_require_driver_login();

$driverId = (int) $_SESSION['driver_id'];
$state = ridesync_fetch_driver_state($conn, $driverId);
$account = $state['account'];
$profile = $state['profile'];
$vehicle = $state['vehicle'];
$requiredDocumentSummary = ridesync_driver_required_document_summary($state['documents'] ?? []);
$driverProfileOld = $_SESSION['driver_profile_old'] ?? [];
unset($_SESSION['driver_profile_old']);

function ridesync_driver_profile_old_value(array $oldInput, string $key, string $default = ''): string
{
    return htmlspecialchars((string) ($oldInput[$key] ?? $default));
}

$documentsByType = $state['documents'] ?? [];
$driverEmergencyContacts = ridesync_get_user_emergency_contacts($conn, 'driver', $driverId);

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
                <strong><?php echo (int) $requiredDocumentSummary['verified']; ?>/4 required checks</strong>
            </div>
        </div>

        <div style="margin-top: 1.75rem; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 1.25rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem;">
                <h3 style="font-size: 1rem; font-weight: 700; margin: 0; color: #f8fafc;">Emergency Contacts 🛡️</h3>
                <span class="status-badge" style="background: rgba(56,189,248,0.15); color: #38bdf8; font-weight: 600; font-size: 0.78rem;">
                    <?php echo count($driverEmergencyContacts); ?>/3 Saved
                </span>
            </div>
            <p style="color: #94a3b8; font-size: 0.82rem; margin-bottom: 1rem;">Notified during active SOS triggers.</p>

            <?php if (!empty($driverEmergencyContacts)): ?>
                <div style="display: flex; flex-direction: column; gap: 0.65rem; margin-bottom: 1.25rem;">
                    <?php foreach ($driverEmergencyContacts as $contact): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(15,23,42,0.6); border: 1px solid rgba(255,255,255,0.08); padding: 0.75rem 0.9rem; border-radius: 8px;">
                            <div>
                                <strong style="color: #f8fafc; font-size: 0.88rem;"><?php echo htmlspecialchars($contact['name']); ?></strong>
                                <small style="color: #94a3b8; font-size: 0.8rem; margin-left: 0.3rem;">(<?php echo htmlspecialchars($contact['relationship']); ?>)</small>
                                <div style="color: #38bdf8; font-size: 0.82rem; margin-top: 0.1rem; font-weight: 500;">
                                    <?php echo htmlspecialchars($contact['phone_number']); ?>
                                </div>
                            </div>
                            <form action="/ridesync/actions/emergency_contact_action.php" method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action_type" value="delete">
                                <input type="hidden" name="contact_id" value="<?php echo (int) $contact['id']; ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" style="color: #f87171; border-color: rgba(248,113,113,0.3); font-size: 0.75rem; padding: 0.2rem 0.5rem;" data-confirm-message="Delete emergency contact <?php echo htmlspecialchars($contact['name']); ?>?">Remove</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (count($driverEmergencyContacts) < 3): ?>
                <form method="POST" action="/ridesync/actions/emergency_contact_action.php" style="background: rgba(15,23,42,0.4); border: 1px dashed rgba(255,255,255,0.12); padding: 1rem; border-radius: 8px;">
                    <input type="hidden" name="action_type" value="add">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <div class="form-group" style="margin-bottom: 0.6rem;">
                        <label for="d_contact_name" style="font-size: 0.82rem;">Full Name</label>
                        <input type="text" id="d_contact_name" name="name" placeholder="Contact Name" required style="padding: 0.4rem 0.6rem; font-size: 0.85rem;">
                    </div>
                    <div class="form-group" style="margin-bottom: 0.6rem;">
                        <label for="d_contact_relation" style="font-size: 0.82rem;">Relationship</label>
                        <select id="d_contact_relation" name="relationship" required style="padding: 0.4rem 0.6rem; font-size: 0.85rem;">
                            <option value="Parent">Parent</option>
                            <option value="Spouse">Spouse</option>
                            <option value="Sibling">Sibling</option>
                            <option value="Friend">Friend / Partner</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0.8rem;">
                        <label for="d_contact_phone" style="font-size: 0.82rem;">Phone Number</label>
                        <input type="tel" id="d_contact_phone" name="phone_number" placeholder="+91 9876543210" required style="padding: 0.4rem 0.6rem; font-size: 0.85rem;">
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%;">Add Contact</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <section class="form-container driver-profile-form">
        <h2>Vehicle & Documents</h2>
        <form action="/ridesync/actions/driver_account_action.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action_type" value="update_profile">

            <div class="form-group">
                <label for="name">Full Name</label>
                <input type="text" id="name" name="name" required value="<?php echo ridesync_driver_profile_old_value($driverProfileOld, 'name', $account['name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" required value="<?php echo ridesync_driver_profile_old_value($driverProfileOld, 'phone', $account['phone'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="license_number">License Number</label>
                <input type="text" id="license_number" name="license_number" required value="<?php echo ridesync_driver_profile_old_value($driverProfileOld, 'license_number', $profile['license_number'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="vehicle_type">Vehicle Type</label>
                <select id="vehicle_type" name="vehicle_type" required>
                    <?php
                    $selectedType = (string) ($driverProfileOld['vehicle_type'] ?? ($vehicle['vehicle_type'] ?? 'Car'));
                    if (!in_array($selectedType, ['Bike', 'Car', 'Auto', 'Van', 'Other'], true)) {
                        $selectedType = 'Car';
                    }
                    foreach (['Bike', 'Car', 'Auto', 'Van', 'Other'] as $type):
                    ?>
                        <option value="<?php echo $type; ?>" <?php echo $selectedType === $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="vehicle_number">Vehicle Number</label>
                <input type="text" id="vehicle_number" name="vehicle_number" required value="<?php echo ridesync_driver_profile_old_value($driverProfileOld, 'vehicle_number', $vehicle['vehicle_number'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="seating_capacity">Passenger Seats</label>
                <select id="seating_capacity" name="seating_capacity" required>
                    <?php
                    $selectedSeats = (int) ($driverProfileOld['seating_capacity'] ?? ($vehicle['seating_capacity'] ?? 4));
                    if ($selectedSeats < 1 || $selectedSeats > 8) {
                        $selectedSeats = 4;
                    }
                    for ($i = 1; $i <= 8; $i++):
                    ?>
                        <option value="<?php echo $i; ?>" <?php echo $selectedSeats === $i ? 'selected' : ''; ?>><?php echo $i; ?> seat<?php echo $i === 1 ? '' : 's'; ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="document_reference">License Document Reference (required)</label>
                <input type="text" id="document_reference" name="document_reference" value="<?php echo ridesync_driver_profile_old_value($driverProfileOld, 'document_reference', $documentsByType['license']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="license_file">Upload license document</label>
                <input type="file" id="license_file" name="license_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="aadhaar_reference">Aadhaar Card Reference (required)</label>
                <input type="text" id="aadhaar_reference" name="aadhaar_reference" value="<?php echo ridesync_driver_profile_old_value($driverProfileOld, 'aadhaar_reference', $documentsByType['aadhaar']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="aadhaar_file">Upload Aadhaar card</label>
                <input type="file" id="aadhaar_file" name="aadhaar_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="pan_reference">PAN Card Reference (required)</label>
                <input type="text" id="pan_reference" name="pan_reference" value="<?php echo ridesync_driver_profile_old_value($driverProfileOld, 'pan_reference', $documentsByType['pan']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="pan_file">Upload PAN card</label>
                <input type="file" id="pan_file" name="pan_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="id_proof_reference">ID Proof Reference (optional)</label>
                <input type="text" id="id_proof_reference" name="id_proof_reference" value="<?php echo ridesync_driver_profile_old_value($driverProfileOld, 'id_proof_reference', $documentsByType['id_proof']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="id_proof_file">Upload ID proof document</label>
                <input type="file" id="id_proof_file" name="id_proof_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="vehicle_rc_reference">Vehicle RC Reference (required)</label>
                <input type="text" id="vehicle_rc_reference" name="vehicle_rc_reference" value="<?php echo ridesync_driver_profile_old_value($driverProfileOld, 'vehicle_rc_reference', $documentsByType['vehicle_rc']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="vehicle_rc_file">Upload vehicle RC document</label>
                <input type="file" id="vehicle_rc_file" name="vehicle_rc_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="insurance_reference">Insurance Reference (optional)</label>
                <input type="text" id="insurance_reference" name="insurance_reference" value="<?php echo ridesync_driver_profile_old_value($driverProfileOld, 'insurance_reference', $documentsByType['insurance']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="insurance_file">Upload insurance document</label>
                <input type="file" id="insurance_file" name="insurance_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="selfie_reference">Selfie Reference (optional)</label>
                <input type="text" id="selfie_reference" name="selfie_reference" value="<?php echo ridesync_driver_profile_old_value($driverProfileOld, 'selfie_reference', $documentsByType['selfie']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="selfie_file">Upload selfie</label>
                <input type="file" id="selfie_file" name="selfie_file" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="vehicle_image_reference">Vehicle Image Reference (optional)</label>
                <input type="text" id="vehicle_image_reference" name="vehicle_image_reference" value="<?php echo ridesync_driver_profile_old_value($driverProfileOld, 'vehicle_image_reference', $documentsByType['vehicle_image']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="vehicle_image_file">Upload vehicle image</label>
                <input type="file" id="vehicle_image_file" name="vehicle_image_file" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="other_document_reference">Other Verification Document (optional)</label>
                <input type="text" id="other_document_reference" name="other_document_reference" value="<?php echo ridesync_driver_profile_old_value($driverProfileOld, 'other_document_reference', $documentsByType['other']['document_reference'] ?? ''); ?>">
                <label class="sr-only" for="other_file">Upload other verification document</label>
                <input type="file" id="other_file" name="other_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            </div>

            <div class="form-group">
                <label for="verification_details">Verification Details (optional)</label>
                <textarea id="verification_details" name="verification_details"><?php echo ridesync_driver_profile_old_value($driverProfileOld, 'verification_details', $profile['verification_details'] ?? ''); ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;">Save Profile</button>
        </form>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/driver_footer.php'; ?>
