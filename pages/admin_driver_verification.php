<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/driver_account_helper.php';
require_once __DIR__ . '/../includes/driver_document_helper.php';
require_once __DIR__ . '/../includes/verification_helper.php';

ridesync_require_admin_login();

if (!ridesync_admin_schema_ready($conn)) {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
    $_SESSION['admin_error'] = 'Admin database tables are missing.';
    header('Location: /ridesync/pages/admin_login.php');
    exit();
}

$admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
if (!$admin || ($admin['status'] ?? '') !== 'active') {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
    $_SESSION['admin_error'] = 'This admin account cannot access driver verification right now.';
    header('Location: /ridesync/pages/admin_login.php');
    exit();
}
ridesync_admin_sync_session($admin);

function ridesync_admin_get_int_param($key) {
    return (int) ($_GET[$key] ?? 0);
}

function ridesync_admin_document_url($documentId, $reference) {
    return ridesync_driver_document_signed_url($documentId, $reference);
}

function ridesync_admin_render_analysis_fields($fields) {
    if (!is_array($fields) || count($fields) === 0) {
        echo '<p class="admin-message">No OCR fields extracted yet.</p>';
        return;
    }

    echo '<dl class="admin-detail-list compact">';
    foreach ($fields as $key => $value) {
        if (is_array($value) || $value === null || $value === '') {
            continue;
        }
        echo '<div><dt>' . htmlspecialchars(ucwords(str_replace('_', ' ', (string) $key))) . '</dt><dd>' . htmlspecialchars((string) $value) . '</dd></div>';
    }
    echo '</dl>';
}

function ridesync_admin_group_rows_by_key($rows, $key) {
    $grouped = [];
    foreach ($rows as $row) {
        $groupKey = (int) ($row[$key] ?? 0);
        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = [];
        }
        $grouped[$groupKey][] = $row;
    }
    return $grouped;
}

$driverId = ridesync_admin_get_int_param('driver_id');

if ($driverId <= 0) {
    $_SESSION['admin_error'] = 'Invalid driver id.';
    header('Location: /ridesync/pages/admin_dashboard.php');
    exit();
}

// Fetch driver + profile (pending/available)
$stmt = mysqli_prepare(
    $conn,
    "SELECT
        d.id AS driver_id,
        d.name,
        d.email,
        d.phone,
        d.status AS driver_status,
        p.id AS profile_id,
        p.verification_status AS profile_verification_status,
        p.license_number,
        p.verification_details,
        v.vehicle_type,
        v.vehicle_number,
        v.seating_capacity
     FROM driver_accounts d
     LEFT JOIN driver_account_profiles p ON p.driver_id = d.id
     LEFT JOIN driver_account_vehicles v ON v.driver_id = d.id
     WHERE d.id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $driverId);
mysqli_stmt_execute($stmt);
$driver = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$driver) {
    $_SESSION['admin_error'] = 'Driver not found.';
    header('Location: /ridesync/pages/admin_dashboard.php');
    exit();
}

// Fetch all submitted documents for this driver
$documents = [];
$documentsTable = mysqli_query($conn, "SHOW TABLES LIKE 'driver_account_documents'");
if ($documentsTable && mysqli_num_rows($documentsTable) > 0) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, document_type, document_reference, created_at, verification_status
         FROM driver_account_documents
         WHERE driver_id = ?
         ORDER BY created_at DESC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $driverId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $documents[] = $row;
    }
}

// Group documents by type for organized UI
$docsByType = [];
foreach ($documents as $doc) {
    $type = (string) ($doc['document_type'] ?? 'document');
    if (!isset($docsByType[$type])) {
        $docsByType[$type] = [];
    }
    $docsByType[$type][] = $doc;
}

$requiredDocumentTypes = ridesync_driver_document_types();
$requiredDocumentKeys = array_keys(ridesync_driver_required_document_types());

$latestDocumentsByType = [];
foreach ($docsByType as $type => $typeDocuments) {
    $latestDocumentsByType[$type] = $typeDocuments[0] ?? [];
}
$requiredDocumentSummary = ridesync_driver_required_document_summary($latestDocumentsByType);
$submittedRequiredDocuments = $requiredDocumentSummary['submitted'];
$verifiedRequiredDocuments = $requiredDocumentSummary['verified'];
$driverPanelReady = ($driver['profile_verification_status'] ?? '') === 'verified' && $requiredDocumentSummary['ready'];
$canApproveReadyDriver = !empty($driver['profile_id']) && $requiredDocumentSummary['complete'] && !$driverPanelReady;

$extraDocumentTypes = array_diff(array_keys($docsByType), array_keys($requiredDocumentTypes));
foreach ($extraDocumentTypes as $extraType) {
    $requiredDocumentTypes[$extraType] = ridesync_admin_status_label($extraType);
}

$verificationReady = ridesync_verification_schema_ready($conn);
$latestSession = $verificationReady ? ridesync_verification_latest_session($conn, $driverId) : null;
$verificationBundle = $latestSession ? ridesync_verification_fetch_session_bundle($conn, (int) $latestSession['id']) : null;
$verificationSession = $verificationBundle['session'] ?? $latestSession;
$analysisRows = $verificationBundle['documents'] ?? [];
$fraudFlags = $verificationBundle['fraud_flags'] ?? [];
$apiChecks = $verificationBundle['api_checks'] ?? [];
$faceMatches = $verificationBundle['face_matches'] ?? [];
$verificationAudit = $verificationBundle['audit_logs'] ?? [];
$analysisByDocument = [];
foreach ($analysisRows as $analysisRow) {
    $analysisByDocument[(int) ($analysisRow['document_id'] ?? 0)] = $analysisRow;
}
$flagsByDocument = ridesync_admin_group_rows_by_key($fraudFlags, 'document_id');
$apiChecksByDocument = ridesync_admin_group_rows_by_key($apiChecks, 'document_id');
$verificationReasons = ridesync_verification_decode($verificationSession['reasons_json'] ?? '[]', []);
$score = (int) round((float) ($verificationSession['confidence_score'] ?? 0));
$aiStatus = $verificationSession['ai_decision'] ?? $verificationSession['status'] ?? 'queued';
$riskLevel = $verificationSession['risk_level'] ?? 'medium';

require_once __DIR__ . '/../includes/admin_header.php';
?>

<div class="driver-page-header">
    <div>
        <span class="driver-kicker">Driver Verification</span>
        <h1><?php echo htmlspecialchars($driver['name']); ?></h1>
        <p>
            <?php echo htmlspecialchars($driver['email']); ?>
            &middot; <?php echo htmlspecialchars($driver['phone']); ?>
        </p>
    </div>
    <span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_badge_class($driver['profile_verification_status'] ?? 'pending')); ?>">
        <?php echo htmlspecialchars(ridesync_admin_status_label($driver['profile_verification_status'] ?? 'pending')); ?>
    </span>
</div>

<?php ridesync_flash('admin_success', 'alert-success'); ?>
<?php ridesync_flash('admin_error', 'alert-error'); ?>

<section class="admin-kyc-hero" data-driver-verification="<?php echo (int) $driverId; ?>" data-verification-session="<?php echo (int) ($verificationSession['id'] ?? 0); ?>">
    <div class="admin-kyc-score">
        <span>AI Trust Score</span>
        <strong data-verification-score><?php echo $score; ?></strong>
        <small>/100</small>
    </div>
    <div class="admin-kyc-summary">
        <span class="driver-kicker">Verification Intelligence</span>
        <h2 data-verification-title><?php echo htmlspecialchars(ridesync_verification_status_label($aiStatus)); ?></h2>
        <div class="admin-kyc-badges">
            <span class="badge badge-<?php echo htmlspecialchars(ridesync_verification_badge_class($aiStatus)); ?>" data-verification-status>
                <?php echo htmlspecialchars(ridesync_verification_status_label($aiStatus)); ?>
            </span>
            <span class="badge badge-<?php echo htmlspecialchars(ridesync_verification_badge_class($riskLevel)); ?>" data-verification-risk>
                <?php echo htmlspecialchars(ucfirst($riskLevel)); ?> Risk
            </span>
            <span class="badge badge-pending" data-verification-stage>
                <?php echo htmlspecialchars(ridesync_admin_status_label($verificationSession['progress_stage'] ?? 'not_started')); ?>
            </span>
        </div>
        <p data-verification-reason>
            <?php echo htmlspecialchars($verificationReasons[0] ?? 'Run AI verification to extract OCR data, validate government checks, inspect fraud signals, and produce a compliance decision.'); ?>
        </p>
    </div>
    <div class="admin-hero-actions">
        <?php if ($verificationReady): ?>
            <form action="/ridesync/actions/admin_action.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action_type" value="driver_ai_verification_start">
                <input type="hidden" name="driver_id" value="<?php echo (int) $driverId; ?>">
                <input type="hidden" name="return_to" value="/ridesync/pages/admin_driver_verification.php?driver_id=<?php echo (int) $driverId; ?>">
                <button type="submit" class="btn btn-primary btn-sm"><?php echo $verificationSession ? 'Re-run AI Analysis' : 'Run AI Analysis'; ?></button>
            </form>
        <?php else: ?>
            <span class="badge badge-rejected">AI schema missing</span>
        <?php endif; ?>
        <a class="btn btn-secondary btn-sm" href="/ridesync/pages/admin_dashboard.php?section=drivers">Verification Queue</a>
    </div>
</section>

<?php if ($verificationSession): ?>
    <section class="admin-kyc-grid">
        <article class="admin-review-card">
            <div class="admin-review-top">
                <div>
                    <span class="driver-kicker">Weighted Decision Engine</span>
                    <h3>Score Breakdown</h3>
                    <p>OCR 25%, API validation 30%, optional face match 20%, fraud analysis 25%.</p>
                </div>
            </div>
            <div class="admin-score-grid">
                <?php foreach ([
                    'OCR Consistency' => $verificationSession['ocr_score'] ?? 0,
                    'API Validation' => $verificationSession['api_score'] ?? 0,
                    'Face Match' => $verificationSession['face_score'] ?? 0,
                    'Fraud Resistance' => $verificationSession['fraud_score'] ?? 0,
                ] as $label => $value): ?>
                    <div class="admin-score-row">
                        <span><?php echo htmlspecialchars($label); ?></span>
                        <strong><?php echo (int) round((float) $value); ?>%</strong>
                        <div class="admin-progress"><span style="width: <?php echo max(0, min(100, (int) round((float) $value))); ?>%;"></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="admin-review-card">
            <div class="admin-review-top">
                <div>
                    <span class="driver-kicker">Decision Reasons</span>
                    <h3>Compliance Findings</h3>
                    <p>Only masked identity values are shown.</p>
                </div>
            </div>
            <?php if (count($verificationReasons) === 0): ?>
                <p class="admin-message">No reasons generated yet.</p>
            <?php else: ?>
                <ul class="admin-finding-list">
                    <?php foreach ($verificationReasons as $reason): ?>
                        <li><?php echo htmlspecialchars((string) $reason); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>

        <article class="admin-review-card">
            <div class="admin-review-top">
                <div>
                    <span class="driver-kicker">Face Matching</span>
                    <h3>Optional Selfie to ID Comparison</h3>
                    <p>Threshold: 82% similarity.</p>
                </div>
            </div>
            <?php $face = $faceMatches[0] ?? null; ?>
            <?php if ($face): ?>
                <div class="admin-face-result">
                    <strong><?php echo number_format((float) $face['similarity_percent'], 1); ?>%</strong>
                    <span class="badge badge-<?php echo htmlspecialchars(ridesync_verification_badge_class($face['status'])); ?>">
                        <?php echo htmlspecialchars(ridesync_admin_status_label($face['status'])); ?>
                    </span>
                </div>
                <p class="admin-message"><?php echo htmlspecialchars(ridesync_verification_decode($face['details_json'] ?? '{}', [])['reason'] ?? 'Face comparison completed.'); ?></p>
            <?php else: ?>
                <p class="admin-message">No face match result yet.</p>
            <?php endif; ?>
        </article>
    </section>

    <section class="admin-section">
        <div class="admin-section-header">
            <div>
                <span class="driver-kicker">Live Processing</span>
                <h2>Confidence Timeline</h2>
            </div>
        </div>
        <div class="admin-timeline">
            <?php foreach ($verificationAudit as $audit): ?>
                <div class="admin-timeline-item">
                    <span class="admin-status-dot"></span>
                    <div>
                        <strong><?php echo htmlspecialchars(ridesync_admin_status_label($audit['event_type'])); ?></strong>
                        <p><?php echo htmlspecialchars($audit['message']); ?></p>
                        <small><?php echo htmlspecialchars(date('M j, g:i A', strtotime((string) $audit['created_at']))); ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($verificationSession): ?>
    <section class="admin-section">
        <div class="admin-section-header">
            <div>
                <span class="driver-kicker">Admin Decision</span>
                <h2>Approve, Reject, or Escalate</h2>
            </div>
            <?php if (!empty($verificationSession['admin_decision'])): ?>
                <span class="badge badge-<?php echo htmlspecialchars(ridesync_verification_badge_class($verificationSession['admin_decision'])); ?>">
                    <?php echo htmlspecialchars(ridesync_admin_status_label($verificationSession['admin_decision'])); ?>
                </span>
            <?php endif; ?>
        </div>
        <form action="/ridesync/actions/admin_action.php" method="POST" class="admin-decision-panel">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action_type" value="driver_ai_verification_decision">
            <input type="hidden" name="session_id" value="<?php echo (int) $verificationSession['id']; ?>">
            <input type="hidden" name="return_to" value="/ridesync/pages/admin_driver_verification.php?driver_id=<?php echo (int) $driverId; ?>">
            <label for="admin_note">Admin notes</label>
            <textarea id="admin_note" name="admin_note" maxlength="1000" placeholder="Internal compliance note"><?php echo htmlspecialchars($verificationSession['admin_note'] ?? ''); ?></textarea>
            <div class="admin-actions">
                <button type="submit" class="btn btn-primary btn-sm" name="decision" value="approved">Approve</button>
                <button type="submit" class="btn btn-danger btn-sm" name="decision" value="rejected">Reject</button>
                <button type="submit" class="btn btn-secondary btn-sm" name="decision" value="escalated">Escalate</button>
            </div>
        </form>
    </section>
<?php endif; ?>

<div class="admin-section">
    <div class="admin-section-header">
        <div>
            <span class="driver-kicker">Profile</span>
            <h2>Driver Account Details</h2>
        </div>
    </div>

    <div class="admin-review-card">
        <div class="admin-review-top">
            <div>
                <span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_badge_class($driver['profile_verification_status'] ?? 'pending')); ?>">
                    <?php echo htmlspecialchars(ridesync_admin_status_label($driver['profile_verification_status'] ?? 'pending')); ?>
                </span>
                <h3>Profile Information</h3>
                <p>Review the driver readiness details.</p>
            </div>
            <strong><?php echo htmlspecialchars($driver['vehicle_number'] ?: 'Vehicle not provided'); ?></strong>
        </div>

        <dl class="admin-detail-list">
            <div><dt>License #</dt><dd><?php echo htmlspecialchars($driver['license_number'] ?: 'Not provided'); ?></dd></div>
            <div><dt>Vehicle #</dt><dd><?php echo htmlspecialchars($driver['vehicle_number'] ?: 'Not provided'); ?></dd></div>
            <div><dt>Vehicle</dt><dd><?php echo htmlspecialchars(($driver['vehicle_type'] ?: 'Vehicle') . ' - ' . ($driver['seating_capacity'] ?: 0) . ' seats'); ?></dd></div>
            <div><dt>Driver Panel Ready</dt><dd><?php echo $driverPanelReady ? 'Yes' : 'No'; ?></dd></div>
            <div>
                <dt>Required Checks</dt>
                <dd>
                    <?php echo (int) $verifiedRequiredDocuments; ?>/4 verified, <?php echo (int) $submittedRequiredDocuments; ?>/4 submitted
                    <small>License, Aadhaar, PAN, and Vehicle RC are required.</small>
                </dd>
            </div>
            <div><dt>Notes</dt><dd><?php echo htmlspecialchars($driver['verification_details'] ?: 'No notes submitted'); ?></dd></div>
        </dl>

        <div class="admin-actions">
            <?php if (!empty($driver['profile_id'])): ?>
                <?php if ($canApproveReadyDriver): ?>
                    <form action="/ridesync/actions/admin_action.php" method="POST" data-confirm-message="Approve this driver profile and all submitted required documents?">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action_type" value="driver_full_approval">
                        <input type="hidden" name="driver_id" value="<?php echo (int) $driverId; ?>">
                        <input type="hidden" name="return_to" value="/ridesync/pages/admin_driver_verification.php?driver_id=<?php echo (int) $driverId; ?>">
                        <button type="submit" class="btn btn-primary btn-sm">Approve Ready Driver</button>
                    </form>
                <?php endif; ?>
                <form action="/ridesync/actions/admin_action.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action_type" value="driver_profile_decision">
                    <input type="hidden" name="profile_id" value="<?php echo (int) $driver['profile_id']; ?>">
                    <input type="hidden" name="decision" value="verified">
                    <input type="hidden" name="return_to" value="/ridesync/pages/admin_driver_verification.php?driver_id=<?php echo (int) $driverId; ?>">
                    <button type="submit" class="btn btn-secondary btn-sm">Approve Profile Only</button>
                </form>
                <form action="/ridesync/actions/admin_action.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action_type" value="driver_profile_decision">
                    <input type="hidden" name="profile_id" value="<?php echo (int) $driver['profile_id']; ?>">
                    <input type="hidden" name="decision" value="rejected">
                    <input type="hidden" name="return_to" value="/ridesync/pages/admin_driver_verification.php?driver_id=<?php echo (int) $driverId; ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Reject Driver</button>
                </form>
            <?php else: ?>
                <div class="driver-empty-card">No profile record found for this driver.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="admin-section">
    <div class="admin-section-header">
        <div>
            <span class="driver-kicker">Documents</span>
            <h2>Submitted Verification Documents</h2>
        </div>
    </div>

    <div class="admin-document-grid">
        <?php foreach ($requiredDocumentTypes as $type => $label): ?>
            <?php $typeDocs = $docsByType[$type] ?? []; ?>
            <?php
            $isRequiredDocument = in_array((string) $type, $requiredDocumentKeys, true);
            $typePending = false;
            foreach ($typeDocs as $typeDoc) {
                if (($typeDoc['verification_status'] ?? '') === 'pending') {
                    $typePending = true;
                    break;
                }
            }
            ?>
            <article class="admin-review-card admin-document-card">
                <div class="admin-review-top">
                    <div>
                        <span class="badge badge-<?php echo count($typeDocs) > 0 ? ($typePending ? 'pending' : 'accepted') : 'closed'; ?>">
                            <?php echo count($typeDocs) > 0 ? ($typePending ? 'Needs Review' : 'Reviewed') : ($isRequiredDocument ? 'Missing' : 'Optional'); ?>
                        </span>
                        <h3><?php echo htmlspecialchars($label); ?></h3>
                        <p>
                            <?php echo count($typeDocs) > 0
                                ? 'Review submitted references and approve or reject each item.'
                                : ($isRequiredDocument ? 'No reference submitted for this required document type.' : 'Optional document not submitted.'); ?>
                        </p>
                    </div>
                </div>

                <?php if (count($typeDocs) === 0): ?>
                    <p class="admin-message"><?php echo $isRequiredDocument ? 'No submitted document reference.' : 'Driver can be approved without this optional document.'; ?></p>
                <?php else: ?>
                    <?php foreach ($typeDocs as $doc): ?>
                        <div class="admin-document-item">
                            <div>
                                <span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_badge_class($doc['verification_status'])); ?>">
                                    <?php echo htmlspecialchars(ridesync_admin_status_label($doc['verification_status'])); ?>
                                </span>
                                <?php $documentUrl = ridesync_admin_document_url((int) $doc['id'], $doc['document_reference'] ?? ''); ?>
                                <p class="admin-message"><?php echo htmlspecialchars($doc['document_reference'] ?: 'No reference provided'); ?></p>
                                <?php if ($documentUrl): ?>
                                    <a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars($documentUrl); ?>" target="_blank" rel="noopener">Open Document</a>
                                <?php endif; ?>
                                <small>Submitted: <?php echo htmlspecialchars(date('M j, g:i A', strtotime((string) ($doc['created_at'] ?? 'now')))); ?></small>
                                <?php $documentStream = $documentUrl ? ridesync_driver_document_read($doc['document_reference'] ?? '') : null; ?>
                                <?php if ($documentUrl && $documentStream && str_starts_with((string) $documentStream['mime'], 'image/')): ?>
                                    <div class="admin-document-preview">
                                        <img src="<?php echo htmlspecialchars($documentUrl); ?>" alt="<?php echo htmlspecialchars($label); ?> preview">
                                    </div>
                                <?php endif; ?>
                                <?php $analysis = $analysisByDocument[(int) $doc['id']] ?? null; ?>
                                <?php if ($analysis): ?>
                                    <div class="admin-analysis-panel">
                                        <div class="admin-analysis-head">
                                            <span class="badge badge-<?php echo htmlspecialchars(ridesync_verification_badge_class($analysis['analysis_status'])); ?>">
                                                <?php echo htmlspecialchars(ridesync_admin_status_label($analysis['analysis_status'])); ?>
                                            </span>
                                            <strong><?php echo (int) round((float) ($analysis['document_score'] ?? 0)); ?>% document score</strong>
                                        </div>
                                        <details open>
                                            <summary>OCR extracted data</summary>
                                            <?php ridesync_admin_render_analysis_fields(ridesync_verification_decode($analysis['extracted_json'] ?? '{}', [])); ?>
                                        </details>
                                        <?php $mismatches = ridesync_verification_decode($analysis['mismatch_json'] ?? '[]', []); ?>
                                        <?php if (count($mismatches) > 0): ?>
                                            <details open>
                                                <summary>Highlighted mismatches</summary>
                                                <ul class="admin-finding-list">
                                                    <?php foreach ($mismatches as $mismatch): ?>
                                                        <li><?php echo htmlspecialchars($mismatch['message'] ?? $mismatch['label'] ?? 'Mismatch detected.'); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </details>
                                        <?php endif; ?>
                                        <?php $docFlags = $flagsByDocument[(int) $doc['id']] ?? []; ?>
                                        <?php if (count($docFlags) > 0): ?>
                                            <details open>
                                                <summary>Fraud indicators</summary>
                                                <div class="admin-chip-list">
                                                    <?php foreach ($docFlags as $flag): ?>
                                                        <span class="badge badge-<?php echo htmlspecialchars(ridesync_verification_badge_class($flag['severity'])); ?>">
                                                            <?php echo htmlspecialchars($flag['flag_label']); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                        <?php $docChecks = $apiChecksByDocument[(int) $doc['id']] ?? []; ?>
                                        <?php if (count($docChecks) > 0): ?>
                                            <details>
                                                <summary>Provider validation</summary>
                                                <div class="admin-check-grid">
                                                    <?php foreach ($docChecks as $check): ?>
                                                        <div>
                                                            <span><?php echo htmlspecialchars(ridesync_admin_status_label($check['check_type'])); ?></span>
                                                            <strong class="badge badge-<?php echo htmlspecialchars(ridesync_verification_badge_class($check['status'])); ?>">
                                                                <?php echo htmlspecialchars(ridesync_admin_status_label($check['status'])); ?>
                                                            </strong>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="admin-actions">
                                <form action="/ridesync/actions/admin_action.php" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action_type" value="driver_document_decision">
                                    <input type="hidden" name="document_id" value="<?php echo (int) $doc['id']; ?>">
                                    <input type="hidden" name="decision" value="verified">
                                    <input type="hidden" name="return_to" value="/ridesync/pages/admin_driver_verification.php?driver_id=<?php echo (int) $driverId; ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                                </form>
                                <form action="/ridesync/actions/admin_action.php" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action_type" value="driver_document_decision">
                                    <input type="hidden" name="document_id" value="<?php echo (int) $doc['id']; ?>">
                                    <input type="hidden" name="decision" value="rejected">
                                    <input type="hidden" name="return_to" value="/ridesync/pages/admin_driver_verification.php?driver_id=<?php echo (int) $driverId; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</div>

<div style="text-align:center; margin-top: 16px;">
    <a class="btn btn-secondary" href="/ridesync/pages/admin_dashboard.php?section=drivers">Back to Drivers</a>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>

