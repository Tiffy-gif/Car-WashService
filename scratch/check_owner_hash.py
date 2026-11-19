import pymysql

conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db')
with conn.cursor() as cur:
    cur.execute("SELECT username, password_hash, local_pin_hash FROM users WHERE username = 'owner'")
    row = cur.fetchone()
    print("Owner hashes:", row)
