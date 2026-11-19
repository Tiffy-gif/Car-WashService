import pymysql

conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db')
cursor = conn.cursor(pymysql.cursors.DictCursor)
cursor.execute("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'receipt_%'")
for r in cursor.fetchall():
    print(r['setting_key'], '=>', r['setting_value'])
