import pymysql

conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db')
with conn.cursor() as cur:
    cur.execute("SELECT id, rule_uid, name, trigger_service_id, trigger_vehicle_type, is_active FROM upsell_rules")
    for r in cur.fetchall():
        print(r)
