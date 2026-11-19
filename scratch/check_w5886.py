import pymysql

conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db')
cursor = conn.cursor(pymysql.cursors.DictCursor)

cursor.execute("SELECT id, order_number, plate_number, status, repair_status, bay_id, created_at FROM orders WHERE plate_number = 'W-5886'")
orders = cursor.fetchall()
print('Orders for W-5886:', orders)

for o in orders:
    cursor.execute("SELECT * FROM order_tasks WHERE order_id = %s", (o['id'],))
    tasks = cursor.fetchall()
    print(f'Tasks for order {o["id"]}:', tasks)

    cursor.execute("SELECT * FROM order_washers WHERE order_id = %s", (o['id'],))
    washers = cursor.fetchall()
    print(f'Washers for order {o["id"]}:', washers)

    cursor.execute("SELECT * FROM order_mechanics WHERE order_id = %s", (o['id'],))
    mechs = cursor.fetchall()
    print(f'Mechanics for order {o["id"]}:', mechs)
