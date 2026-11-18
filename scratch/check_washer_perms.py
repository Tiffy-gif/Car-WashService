import pymysql

conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db')
with conn.cursor() as cur:
    cur.execute("""
        SELECT p.code, p.label_en, p.module
        FROM role_permissions rp
        JOIN permissions p ON rp.permission_id = p.id
        WHERE rp.role_id = 3
    """)
    rows = cur.fetchall()
    print("Permissions for Washer (role_id 3):")
    for r in rows:
        print(f"  [{r[2]}] {r[0]} - {r[1]}")
