import pymysql

conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db')
with conn.cursor() as cur:
    cur.execute("DESCRIBE upsell_rules")
    cols = [r[0] for r in cur.fetchall()]
    print("Existing cols:", cols)
    
    if 'action_type' not in cols:
        cur.execute("ALTER TABLE upsell_rules ADD COLUMN action_type VARCHAR(30) NOT NULL DEFAULT 'add_service' AFTER offer_product_id")
        print("Added action_type column")
        
    if 'replace_service_id' not in cols:
        cur.execute("ALTER TABLE upsell_rules ADD COLUMN replace_service_id INT(11) DEFAULT NULL AFTER action_type")
        print("Added replace_service_id column")
        
    conn.commit()

with conn.cursor() as cur:
    cur.execute("DESCRIBE upsell_rules")
    print("Updated cols:", [r[0] for r in cur.fetchall()])
