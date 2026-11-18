import pymysql

conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db')
with conn.cursor(pymysql.cursors.DictCursor) as cur:
    cur.execute("SELECT * FROM upsell_rules WHERE is_active = 1 ORDER BY id ASC")
    rules = cur.fetchall()

cart = [{'id': 1, 'service_id': 1, 'type': 'service', 'price_usd': 8.5}]
serviceIds = [1]
vehicleType = 'small_car'

for r in rules:
    print(f"\nChecking rule {r['id']} ({r['name']}):")
    if r['trigger_vehicle_type'] and r['trigger_vehicle_type'] != vehicleType:
        print(f"  -> Skipped vehicle_type: {r['trigger_vehicle_type']} != {vehicleType}")
        continue
    
    if r['trigger_service_id']:
        trigId = int(r['trigger_service_id'])
        if trigId not in serviceIds:
            print(f"  -> Skipped trigger_service_id: {trigId} not in {serviceIds}")
            continue
    elif r['trigger_category']:
        print(f"  -> Skipped trigger_category: {r['trigger_category']}")
        continue

    if r['offer_service_id']:
        offerSvcId = int(r['offer_service_id'])
        if offerSvcId in serviceIds:
            print(f"  -> Skipped offer_service_id already in cart: {offerSvcId}")
            continue

    print(f"  -> MATCHED! target: {r['offer_service_id']}")
