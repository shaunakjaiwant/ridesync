import ssl
from pathlib import Path
import pymysql

def main():
    root = Path(__file__).resolve().parent.parent
    ca_path = root / "ca.pem"

    ctx = ssl.create_default_context(cafile=str(ca_path))
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_REQUIRED

    import os
    host = os.environ.get("RIDESYNC_DB_HOST", "ridesync-db-ridesync-db.k.aivencloud.com")
    port = int(os.environ.get("RIDESYNC_DB_PORT", 26498))
    user = os.environ.get("RIDESYNC_DB_USER", "avnadmin")
    password = os.environ.get("RIDESYNC_DB_PASSWORD", "")
    database = os.environ.get("RIDESYNC_DB_NAME", "defaultdb")

    conn = pymysql.connect(
        host=host,
        port=port,
        user=user,
        password=password,
        database=database,
        ssl=ctx,
        autocommit=True
    )
    cursor = conn.cursor()

    pass_hash = "$2y$10$P.nWg3WlFhS9W0Kwa/tgEugi97khxnYSfcyNFXE598fhaEJvAzpVS"

    print("Seeding demo accounts into Aiven MySQL...")

    # 1. Rider Account
    cursor.execute("""
        INSERT INTO users (name, email, password, college, gender, status)
        VALUES ('Campus Rider', 'rider@ridesync.com', %s, 'SDM Institute of Technology', 'Male', 'active')
        ON DUPLICATE KEY UPDATE password = VALUES(password), status = 'active';
    """, (pass_hash,))
    print("[OK] Rider Account: rider@ridesync.com / Password123!")

    # 2. Driver Account
    cursor.execute("""
        INSERT INTO driver_accounts (name, email, password, phone, status, onboarding_status)
        VALUES ('Campus Driver', 'driver@ridesync.com', %s, '9876543210', 'active', 'complete')
        ON DUPLICATE KEY UPDATE password = VALUES(password), status = 'active', onboarding_status = 'complete';
    """, (pass_hash,))
    
    cursor.execute("SELECT id FROM driver_accounts WHERE email = 'driver@ridesync.com';")
    driver_id = cursor.fetchone()[0]

    cursor.execute("""
        INSERT INTO driver_account_profiles (driver_id, license_number, verification_status)
        VALUES (%s, 'KA19DL123456', 'verified')
        ON DUPLICATE KEY UPDATE verification_status = 'verified';
    """, (driver_id,))

    cursor.execute("""
        INSERT INTO driver_account_vehicles (driver_id, vehicle_type, vehicle_number, seating_capacity)
        VALUES (%s, 'Car', 'KA 19 MD 4321', 4)
        ON DUPLICATE KEY UPDATE seating_capacity = 4;
    """, (driver_id,))

    cursor.execute("""
        INSERT INTO driver_account_availability (driver_id, status)
        VALUES (%s, 'online')
        ON DUPLICATE KEY UPDATE status = 'online';
    """, (driver_id,))
    print("[OK] Driver Account: driver@ridesync.com / Password123!")

    # 3. Admin Account
    # First check columns of admin_users
    cursor.execute("DESCRIBE admin_users;")
    cols = [row[0] for row in cursor.fetchall()]
    
    if "username" in cols:
        cursor.execute("""
            INSERT INTO admin_users (username, email, password, role, status)
            VALUES ('admin', 'admin@ridesync.com', %s, 'super_admin', 'active')
            ON DUPLICATE KEY UPDATE password = VALUES(password), status = 'active';
        """, (pass_hash,))
    else:
        cursor.execute("""
            INSERT INTO admin_users (name, email, password, role, status)
            VALUES ('Admin Operator', 'admin@ridesync.com', %s, 'super_admin', 'active')
            ON DUPLICATE KEY UPDATE password = VALUES(password), status = 'active';
        """, (pass_hash,))
        
    print("[OK] Admin Account: admin@ridesync.com (or admin) / Password123!")

    cursor.close()
    conn.close()
    print("Demo accounts seeding complete!")

if __name__ == "__main__":
    main()
