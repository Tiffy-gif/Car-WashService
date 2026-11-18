import pymysql
conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db')
with conn.cursor() as cur:
    cur.execute("SELECT setting_key, setting_value FROM settings WHERE setting_key = 'enable_upsell_offers'")
    print("enable_upsell_offers:", cur.fetchall())
