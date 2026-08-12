import os
import ssl
from pathlib import Path
import pymysql

HOST = os.environ.get("RIDESYNC_DB_HOST", "ridesync-db-ridesync-db.k.aivencloud.com")
PORT = int(os.environ.get("RIDESYNC_DB_PORT", 26498))
USER = os.environ.get("RIDESYNC_DB_USER", "avnadmin")
PASS = os.environ.get("RIDESYNC_DB_PASSWORD", "")
DB = os.environ.get("RIDESYNC_DB_NAME", "defaultdb")

def main():
    root = Path(__file__).resolve().parent.parent
    ca_path = root / "ca.pem"
    
    print(f"Connecting to Aiven MySQL at {HOST}:{PORT} using CA cert {ca_path}...")
    
    ctx = ssl.create_default_context(cafile=str(ca_path))
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_REQUIRED

    conn = pymysql.connect(
        host=HOST,
        port=PORT,
        user=USER,
        password=PASS,
        database=DB,
        ssl=ctx,
        autocommit=True
    )
    
    print("Successfully connected to Aiven MySQL!")
    cursor = conn.cursor()

    schema_path = root / "database" / "ridesync_db.sql"
    print(f"Reading schema from {schema_path}...")
    raw_schema = schema_path.read_text(encoding="utf-8")

    # Remove CREATE DATABASE / USE / CHARACTER SET / COLLATE header statements
    cleaned_lines = []
    for line in raw_schema.splitlines():
        upper = line.strip().upper()
        if upper.startswith("CREATE DATABASE") or upper.startswith("USE ") or upper.startswith("CHARACTER SET") or upper.startswith("COLLATE"):
            continue
        cleaned_lines.append(line)
    
    cleaned_schema = "\n".join(cleaned_lines)
    statements = [stmt.strip() for stmt in cleaned_schema.split(";") if stmt.strip()]

    print(f"Executing {len(statements)} schema statements...")
    for idx, stmt in enumerate(statements, 1):
        try:
            cursor.execute(stmt)
        except Exception as e:
            if "already exists" not in str(e).lower():
                print(f"Statement {idx} warning: {e}")

    print("Schema import complete!")

    seed_path = root / "database" / "seed.sql"
    if seed_path.exists():
        print(f"Reading seed data from {seed_path}...")
        raw_seed = seed_path.read_text(encoding="utf-8")
        seed_statements = [stmt.strip() for stmt in raw_seed.split(";") if stmt.strip()]
        for stmt in seed_statements:
            try:
                cursor.execute(stmt)
            except Exception as e:
                print(f"Seed statement warning: {e}")
        print("Seed import complete!")

    cursor.execute("SHOW TABLES;")
    tables = [row[0] for row in cursor.fetchall()]

    print("\n" + "=" * 50)
    print(f"SUCCESS: Aiven MySQL Database '{DB}' setup complete!")
    print(f"Total tables created: {len(tables)}")
    for t in sorted(tables):
        print(f"  - {t}")
    print("=" * 50)

    cursor.close()
    conn.close()

if __name__ == "__main__":
    main()
