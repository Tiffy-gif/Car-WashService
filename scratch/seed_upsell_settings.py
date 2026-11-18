import pymysql

conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db')
with conn.cursor() as cur:
    cur.execute("SELECT setting_key, setting_value FROM settings WHERE setting_key = 'enable_upsell_offers'")
    row = cur.fetchone()
    if not row:
        cur.execute("INSERT INTO settings (setting_key, setting_value) VALUES ('enable_upsell_offers', '1')")
        print("Inserted enable_upsell_offers = 1")
    else:
        print("enable_upsell_offers exists:", row[1])
    
    # Check if UPSELL-EXTERIOR-WAX exists
    cur.execute("SELECT id, name FROM upsell_rules WHERE rule_uid = 'UPSELL-EXTERIOR-WAX'")
    rule = cur.fetchone()
    if not rule:
        cur.execute("""
            INSERT INTO upsell_rules (
                rule_uid, name, trigger_service_id, trigger_category, trigger_vehicle_type,
                offer_service_id, action_type, offer_price_usd,
                offer_headline_en, offer_headline_km, offer_description_en, offer_description_km,
                is_active, created_at
            ) VALUES (
                'UPSELL-EXTERIOR-WAX', 'Exterior Wash: Add Hydrophobic Wax & Polish', 1, 'wash', NULL,
                5, 'add_service', 5.00,
                'Add Hydrophobic Wax & Polish for +$5.00?', 'បន្ថែមបាញ់ក្រមួន Wax & ប៉ូលាពន្លឺ ត្រឹមតែ +$5.00 ដែរឬទេ?',
                'Add water-repellent protective shine to your exterior wash.', 'បន្ថែមស្រទាប់ក្រមួនការពារទឹក និងបង្កើនពន្លឺលើការលាងក្រៅ។',
                1, NOW()
            )
        """)
        print("Inserted UPSELL-EXTERIOR-WAX rule")
    else:
        print("UPSELL-EXTERIOR-WAX already exists:", rule)
    conn.commit()
