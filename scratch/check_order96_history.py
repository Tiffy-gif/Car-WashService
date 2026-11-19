import pymysql

conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db', cursorclass=pymysql.cursors.DictCursor)
with conn.cursor() as cur:
    cur.execute('SELECT * FROM bay_history WHERE order_id = 96 ORDER BY id ASC')
    for h in cur.fetchall():
        print(str(h['created_at']) + ' | Bay: ' + str(h['bay_name']) + ' | Action: ' + str(h['action']) + ' | By: ' + str(h['performed_by_name']) + ' (' + str(h['performed_by_role']) + ') | Details: ' + str(h['details']))
