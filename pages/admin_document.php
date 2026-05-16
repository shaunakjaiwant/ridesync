<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/driver_document_helper.php';

ridesync_require_admin_login();

$admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
if (!$admin || $admin['status'] !== 'active') {
    http_response_code(403);
    exit('Not allowed');
}
ridesync_admin_sync_session($admin);

$documentId = (int) ($_GET['document_id'] ?? 0);
if ($documentId <= 0) {
    http_response_code(400);
    exit('Invalid document');
}

$expiresAt = (int) ($_GET['expires'] ?? 0);
$signature = (string) ($_GET['signature'] ?? '');
if (!ridesync_driver_document_validate_signature($documentId, $expiresAt, $signature)) {
    http_response_code(403);
    exit('Document link expired');
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT document_reference
     FROM driver_account_documents
     WHERE id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "i", $documentId);
mysqli_stmt_execute($stmt);
$document = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$document) {
    http_response_code(404);
    exit('Document not found');
}

$documentStream = ridesync_driver_document_read((string) ($document['document_reference'] ?? ''));
if (!$documentStream) {
    http_response_code(404);
    exit('Document file not available');
}

session_write_close();
header('Content-Type: ' . $documentStream['mime']);
header('Content-Length: ' . strlen($documentStream['bytes']));
header('Content-Disposition: inline; filename="' . basename($documentStream['filename']) . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
echo $documentStream['bytes'];
exit();

?>
