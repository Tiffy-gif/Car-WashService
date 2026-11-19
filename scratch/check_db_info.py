import pymysql

conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db')
with conn.cursor() as cur:
    cur.execute("SELECT id, device_name, api_key, status FROM pos_devices")
    print("Devices:", cur.fetchall())

    cur.execute("SELECT id, username, full_name, role, local_pin_hash, password_hash FROM users")
    users = cur.fetchall()
    print("Users:")
    for u in users:
        print(u[0], u[1], u[2], u[3])
