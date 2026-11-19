import pymysql

conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db', cursorclass=pymysql.cursors.DictCursor)
with conn.cursor() as cur:
    cur.execute('SELECT * FROM order_tasks WHERE order_id = 96')
    for t in cur.fetchall():
        print(t)
    cur.execute('SELECT * FROM bay_history WHERE order_id = 96')
    for b in cur.fetchall():
        print(b)
