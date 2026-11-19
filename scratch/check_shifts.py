import pymysql

conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db')
cur = conn.cursor()
cur.execute("SELECT id, shift_uid, cashier_id, cashier_name, started_at, status FROM cashier_shifts WHERE status='open'")
for r in cur.fetchall():
    print(r)
