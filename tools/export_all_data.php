<?php
/**
 * RideSync - Comprehensive Local Data Exporter
 * 
 * Exports all database schema, tables, and rows into:
 * 1. Full SQL dump (for local/backup restoration)
 * 2. Cloud-compatible SQL dump (for Aiven/Render defaultdb import)
 * 3. Individual JSON files for each table
 * 4. Human-readable CSV files for core operational tables
 * 5. Master data manifest (counts, metadata, timestamp)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../config/bootstrap.php';

$outputDir = __DIR__ . '/../storage/exports';
$jsonDir   = $outputDir . '/json';
$csvDir    = $outputDir . '/csv';
$backupDir = __DIR__ . '/../database/backups';

foreach ([$outputDir, $jsonDir, $csvDir, $backupDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

$conn = mysqli_connect('127.0.0.1', 'root', '', 'ridesync_db', 3306);
if (!$conn) {
    fwrite(STDERR, "ERROR: Unable to connect to local ridesync_db: " . mysqli_connect_error() . PHP_EOL);
    exit(1);
}

mysqli_set_charset($conn, 'utf8mb4');

echo "========================================================\n";
echo "   RideSync Comprehensive Data Export & Migration Prep  \n";
echo "========================================================\n\n";

// 1. Fetch list of tables
$tableRes = mysqli_query($conn, "SHOW TABLES");
$tables = [];
while ($row = mysqli_fetch_row($tableRes)) {
    $tables[] = $row[0];
}

echo "[1/5] Discovered " . count($tables) . " tables in local ridesync_db.\n";

$timestamp = date('Y-m-d_H-i-s');
$isoDate   = date('c');

$manifest = [
    'application' => 'RideSync',
    'exported_at' => $isoDate,
    'source_db'   => 'ridesync_db',
    'source_host' => '127.0.0.1:3306',
    'total_tables'=> count($tables),
    'tables'      => [],
    'files'       => [],
];

// 2. Export each table to JSON and CSV (for selected tables)
echo "[2/5] Exporting table records to JSON and CSV...\n";
$csvEligible = [
    'users', 'driver_accounts', 'driver_account_profiles', 'driver_account_vehicles',
    'rides', 'matches', 'wallet_accounts', 'admin_users', 'audit_logs', 'user_verifications'
];

$totalRecords = 0;

foreach ($tables as $table) {
    $dataRes = mysqli_query($conn, "SELECT * FROM `{$table}`");
    $rows = [];
    while ($r = mysqli_fetch_assoc($dataRes)) {
        $rows[] = $r;
    }

    $rowCount = count($rows);
    $totalRecords += $rowCount;
    $manifest['tables'][$table] = [
        'row_count' => $rowCount,
        'has_data'  => $rowCount > 0,
    ];

    // Write JSON file
    $jsonPath = "{$jsonDir}/{$table}.json";
    file_put_contents(
        $jsonPath,
        json_encode([
            'table' => $table,
            'exported_at' => $isoDate,
            'count' => $rowCount,
            'data' => $rows
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    // Write CSV if eligible and has data
    if (in_array($table, $csvEligible, true) && $rowCount > 0) {
        $csvPath = "{$csvDir}/{$table}.csv";
        $fp = fopen($csvPath, 'w');
        if ($fp) {
            // Header
            fputcsv($fp, array_keys($rows[0]));
            // Rows
            foreach ($rows as $row) {
                fputcsv($fp, array_values($row));
            }
            fclose($fp);
        }
    }

    if ($rowCount > 0) {
        echo sprintf("  - %-32s : %4d rows exported\n", $table, $rowCount);
    }
}

echo "Total records across all tables: {$totalRecords}\n\n";

// 3. Generate Full SQL Dump using mysqldump if available
echo "[3/5] Generating native MySQL SQL dumps...\n";
$mysqldumpBin = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
$fullSqlPath = "{$outputDir}/ridesync_local_full_data.sql";
$backupSqlPath = "{$backupDir}/ridesync_db_backup_{$timestamp}.sql";
$backupLatestPath = "{$backupDir}/ridesync_db_latest.sql";

if (file_exists($mysqldumpBin)) {
    $dumpCmd = sprintf(
        '"%s" -u root --hex-blob --routines --triggers --single-transaction ridesync_db > "%s"',
        $mysqldumpBin,
        $fullSqlPath
    );
    exec($dumpCmd, $output, $retCode);
    if ($retCode === 0 && file_exists($fullSqlPath)) {
        copy($fullSqlPath, $backupSqlPath);
        copy($fullSqlPath, $backupLatestPath);
        echo "  [OK] Full database dump created at:\n";
        echo "       - {$fullSqlPath} (" . round(filesize($fullSqlPath) / 1024, 2) . " KB)\n";
        echo "       - {$backupLatestPath}\n";
    } else {
        echo "  [WARN] mysqldump returned code {$retCode}. Falling back to PHP SQL generator.\n";
    }
}

// 4. Generate Cloud-Compatible SQL Dump for Aiven / Render (defaultdb)
echo "[4/5] Generating cloud-ready SQL dump (for Aiven MySQL / Render)...\n";
$cloudSqlPath = "{$outputDir}/ridesync_cloud_import.sql";

$cloudSql = "-- RideSync Cloud Import Dump\n";
$cloudSql .= "-- Target: Aiven MySQL (defaultdb) or Render Managed MySQL\n";
$cloudSql .= "-- Generated: {$isoDate}\n\n";
$cloudSql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
$cloudSql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
$cloudSql .= "SET NAMES utf8mb4;\n\n";

// Table creation and data inserts in dependency order
foreach ($tables as $table) {
    // Schema
    $createRes = mysqli_query($conn, "SHOW CREATE TABLE `{$table}`");
    $createRow = mysqli_fetch_row($createRes);
    if ($createRow) {
        $createSql = $createRow[1];
        $cloudSql .= "-- Table structure for `{$table}`\n";
        $cloudSql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $cloudSql .= $createSql . ";\n\n";
    }

    // Data
    $dataRes = mysqli_query($conn, "SELECT * FROM `{$table}`");
    $rows = [];
    while ($r = mysqli_fetch_assoc($dataRes)) {
        $rows[] = $r;
    }

    if (count($rows) > 0) {
        $cloudSql .= "-- Dumping data for table `{$table}` (" . count($rows) . " rows)\n";
        $columns = array_map(function($c) { return "`" . addslashes($c) . "`"; }, array_keys($rows[0]));
        $columnList = implode(', ', $columns);

        $cloudSql .= "INSERT INTO `{$table}` ({$columnList}) VALUES\n";
        $valChunks = [];
        foreach ($rows as $row) {
            $escapedVals = [];
            foreach ($row as $val) {
                if ($val === null) {
                    $escapedVals[] = "NULL";
                } elseif (is_numeric($val) && !preg_match('/^0[0-9]+/', (string)$val)) {
                    $escapedVals[] = $val;
                } else {
                    $escapedVals[] = "'" . mysqli_real_escape_string($conn, $val) . "'";
                }
            }
            $valChunks[] = "(" . implode(', ', $escapedVals) . ")";
        }
        $cloudSql .= implode(",\n", $valChunks) . ";\n\n";
    }
}

$cloudSql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
$cloudSql .= "-- End of RideSync Cloud Import Dump\n";

file_put_contents($cloudSqlPath, $cloudSql);
echo "  [OK] Cloud-ready SQL created at:\n";
echo "       - {$cloudSqlPath} (" . round(filesize($cloudSqlPath) / 1024, 2) . " KB)\n\n";

// 5. Write Manifest and Summary
echo "[5/5] Generating export manifest...\n";
$manifest['files'] = [
    'full_sql_dump'  => 'storage/exports/ridesync_local_full_data.sql',
    'cloud_sql_dump' => 'storage/exports/ridesync_cloud_import.sql',
    'backup_latest'  => 'database/backups/ridesync_db_latest.sql',
    'json_directory' => 'storage/exports/json/',
    'csv_directory'  => 'storage/exports/csv/',
];

$manifestPath = "{$outputDir}/ridesync_export_manifest.json";
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "  [OK] Manifest saved at {$manifestPath}\n\n";

echo "========================================================\n";
echo "   EXPORT COMPLETED SUCCESSFULLY!                       \n";
echo "========================================================\n";
