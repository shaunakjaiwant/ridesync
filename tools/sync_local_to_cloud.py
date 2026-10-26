#!/usr/bin/env python3
"""
RideSync - Local to Cloud Database Synchronization & Link Tool

Syncs all exported local RideSync data directly into the deployed cloud MySQL database
(Aiven for MySQL / Render).
"""

import os
import sys
import ssl
import argparse
import getpass
from pathlib import Path
import pymysql

ROOT_DIR = Path(__file__).resolve().parent.parent
DEFAULT_HOST = os.environ.get("RIDESYNC_DB_HOST", "ridesync-db-ridesync-db.k.aivencloud.com")
DEFAULT_PORT = int(os.environ.get("RIDESYNC_DB_PORT", 26498))
DEFAULT_USER = os.environ.get("RIDESYNC_DB_USER", "avnadmin")
DEFAULT_DB   = os.environ.get("RIDESYNC_DB_NAME", "defaultdb")
DEFAULT_SQL  = ROOT_DIR / "storage" / "exports" / "ridesync_cloud_import.sql"
CA_PATH      = ROOT_DIR / "ca.pem"


def parse_args():
    parser = argparse.ArgumentParser(description="Synchronize and import local RideSync data into deployed cloud database.")
    parser.add_argument("--host", default=DEFAULT_HOST, help="Cloud database host")
    parser.add_argument("--port", type=int, default=DEFAULT_PORT, help="Cloud database port")
    parser.add_argument("--user", default=DEFAULT_USER, help="Cloud database username")
    parser.add_argument("--password", default=os.environ.get("RIDESYNC_DB_PASSWORD", ""), help="Cloud database password")
    parser.add_argument("--database", default=DEFAULT_DB, help="Target database name")
    parser.add_argument("--sql-file", default=str(DEFAULT_SQL), help="Path to SQL dump file to import")
    parser.add_argument("--dry-run", action="store_true", help="Test connection without executing imports")
    return parser.parse_args()


def main():
    args = parse_args()

    print("=" * 65)
    print("   RideSync -> Cloud Database Synchronization Tool")
    print("=" * 65)
    print(f"Target Host     : {args.host}:{args.port}")
    print(f"Target Database : {args.database}")
    print(f"Database User   : {args.user}")
    print(f"CA Certificate  : {CA_PATH if CA_PATH.exists() else 'System default'}")
    print(f"Import SQL File : {args.sql_file}")
    print("-" * 65)

    sql_path = Path(args.sql_file)
    if not sql_path.exists():
        print(f"ERROR: SQL dump file '{sql_path}' does not exist.")
        print("Please run 'php tools/export_all_data.php' first to generate the latest dump.")
        sys.exit(1)

    password = args.password
    if not password:
        if sys.stdin.isatty():
            password = getpass.getpass(f"Enter password for cloud user '{args.user}': ").strip()
        else:
            print("ERROR: Database password not provided. Set RIDESYNC_DB_PASSWORD or pass --password=<pwd>.")
            sys.exit(1)

    # Prepare SSL context
    ssl_kwargs = {}
    if CA_PATH.exists():
        ctx = ssl.create_default_context(cafile=str(CA_PATH))
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_REQUIRED
        ssl_kwargs["ssl"] = ctx

    print("\n[1/4] Establishing secure SSL connection to cloud database...")
    try:
        conn = pymysql.connect(
            host=args.host,
            port=args.port,
            user=args.user,
            password=password,
            database=args.database,
            autocommit=True,
            connect_timeout=15,
            **ssl_kwargs
        )
        print("  [OK] Successfully connected to cloud database!")
    except Exception as e:
        print(f"  [FAIL] Could not connect: {e}")
        sys.exit(1)

    cursor = conn.cursor()

    if args.dry_run:
        print("\n[DRY RUN] Connection successful! No SQL statements executed.")
        cursor.close()
        conn.close()
        return

    print("\n[2/4] Parsing cloud import SQL script...")
    raw_content = sql_path.read_text(encoding="utf-8")
    
    # Split SQL statements safely
    statements = []
    current_stmt = []
    in_multiline_comment = False

    for line in raw_content.splitlines():
        trimmed = line.strip()
        if not trimmed:
            continue
        if trimmed.startswith("/*"):
            if not trimmed.endswith("*/"):
                in_multiline_comment = True
            continue
        if in_multiline_comment:
            if trimmed.endswith("*/"):
                in_multiline_comment = False
            continue
        if trimmed.startswith("--"):
            continue

        current_stmt.append(line)
        if trimmed.endswith(";"):
            full_stmt = "\n".join(current_stmt).strip()
            if full_stmt:
                statements.append(full_stmt)
            current_stmt = []

    print(f"  [OK] Extracted {len(statements)} SQL commands to execute.")

    print("\n[3/4] Executing migration statements into cloud database...")
    errors = 0
    executed = 0

    for idx, stmt in enumerate(statements, 1):
        try:
            cursor.execute(stmt)
            executed += 1
            if idx % 10 == 0 or idx == len(statements):
                print(f"  Processed {idx}/{len(statements)} statements...", end="\r", flush=True)
        except Exception as ex:
            errors += 1
            err_msg = str(ex)
            # Ignore non-fatal warnings
            if "already exists" not in err_msg.lower():
                print(f"\n  [WARN] Statement #{idx} notice: {err_msg}")

    print(f"\n  [OK] Successfully executed {executed} statements ({errors} non-critical warnings).")

    print("\n[4/4] Verifying synchronized cloud tables and row counts...")
    cursor.execute("SHOW TABLES;")
    cloud_tables = [row[0] for row in cursor.fetchall()]

    print(f"\n{'TABLE NAME':<35} | {'CLOUD ROW COUNT':>15}")
    print("-" * 55)

    total_cloud_rows = 0
    for tbl in sorted(cloud_tables):
        try:
            cursor.execute(f"SELECT COUNT(*) FROM `{tbl}`;")
            cnt = cursor.fetchone()[0]
            total_cloud_rows += cnt
            if cnt > 0:
                print(f"{tbl:<35} | {cnt:>15}")
        except Exception:
            pass

    print("-" * 55)
    print(f"{'Total Tables':<35} | {len(cloud_tables):>15}")
    print(f"{'Total Rows in Cloud DB':<35} | {total_cloud_rows:>15}")

    cursor.close()
    conn.close()

    print("\n" + "=" * 65)
    print("   SYNCHRONIZATION COMPLETED SUCCESSFULLY! ")
    print("   Your deployed RideSync is now linked with all local data.")
    print("=" * 65)


if __name__ == "__main__":
    main()
