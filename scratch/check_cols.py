import pymysql

conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db')
cur = conn.cursor()
cur.execute("SHOW COLUMNS FROM cashier_shifts LIKE '%review%'")
print(cur.fetchall())
