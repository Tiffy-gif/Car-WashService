import requests
import json
import pymysql

BASE_URL = "http://localhost/Car-WashService/api/pos/index.php"
DEVICE_KEY = "my_test_device_key_unique9"
HEADERS = {
    "X-Device-Key": DEVICE_KEY,
    "Content-Type": "application/json"
}

def call_api(action, payload):
    url = f"{BASE_URL}?action={action}"
    res = requests.post(url, json=payload, headers=HEADERS)
    try:
        return res.status_code, res.json()
    except:
        return res.status_code, res.text

# 1. Reset / prepare test environment
conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db')
with conn.cursor() as cur:
    cur.execute("UPDATE pos_devices SET status = 'active' WHERE api_key = %s", (DEVICE_KEY,))
    today = '2026-11-19'
    cur.execute("DELETE FROM pos_daily_closes WHERE close_date = %s", (today,))
    cur.execute("DELETE FROM pos_closings WHERE close_date = %s", (today,))
    cur.execute("UPDATE cashier_shifts SET status = 'closed', ended_at = NOW() WHERE cashier_id = 2 AND status = 'open'")
    conn.commit()

# Login Visal as Cashier
st, data = call_api("staff_login", {
    "username": "visal",
    "pin": "123456"
})
print("Login visal status:", st, data.get("success"), data.get("user", {}).get("username"))
assert st == 200 and data.get("success"), "Visal login failed"
session_token = data.get("session_token")
HEADERS["X-Staff-Token"] = session_token

print("\n--- TEST 1: NO ACTIVE SHIFT GUARDS ---")
# Cash In without shift
st, data = call_api("record_cash_movement", {
    "type": "cash_in",
    "amount_usd": 10.0,
    "reason": "Test without shift",
    "cashier_id": 2
})
print("Cash In without shift status:", st, data)
assert st >= 400 and "shift" in str(data).lower(), "Should reject Cash In when no shift is open"

# Open Drawer without shift
st, data = call_api("open_drawer_no_sale", {
    "reason": "Test without shift",
    "manager_username": "owner",
    "manager_pin": "123456",
    "cashier_id": 2
})
print("Open Drawer without shift status:", st, data)
assert st >= 400 and "shift" in str(data).lower(), "Should reject Open Drawer when no shift is open"

print("PASSED: Server strictly rejects actions when no shift is open!")

print("\n--- TEST 2: OPEN SHIFT FOR CASHIER VISAL ---")
st, data = call_api("shift_start", {
    "cashier_id": 2,
    "opening_cash_usd": 50.00,
    "opening_cash_khr": 0,
    "pin": "123456"
})
print("Shift start response:", st, data)
assert st == 200 and data.get("success"), "Should successfully open shift"
shift_id = data.get("shift_id") or data.get("shift", {}).get("id")
print("Active Shift ID:", shift_id)

print("\n--- TEST 3: CASH IN ---")
st, data = call_api("record_cash_movement", {
    "type": "cash_in",
    "amount_usd": 15.00,
    "amount_khr": 0,
    "reason": "Morning change float",
    "cashier_id": 2
})
print("Cash In response:", st, data)
assert st == 200 and data.get("success"), "Cash In should succeed"
assert data.get("shift_id") == shift_id, "Should record shift_id"
assert data.get("type") == "cash_in"

print("\n--- TEST 4: CASH OUT <= $10 (NO MANAGER PIN REQUIRED) ---")
st, data = call_api("record_cash_movement", {
    "type": "cash_out",
    "amount_usd": 5.00,
    "amount_khr": 0,
    "reason": "Drinking water purchase",
    "cashier_id": 2
})
print("Cash Out <= $10 response:", st, data)
assert st == 200 and data.get("success"), "Cash Out <= $10 should succeed without manager PIN"

print("\n--- TEST 5: CASH OUT > $10 (MANAGER PIN REQUIRED) ---")
# 5A: without manager credentials -> should fail
st, data = call_api("record_cash_movement", {
    "type": "cash_out",
    "amount_usd": 25.00,
    "amount_khr": 0,
    "reason": "Workshop spare supplies",
    "cashier_id": 2
})
print("Cash Out > $10 without PIN response:", st, data)
assert st >= 400, "Should reject Cash Out > $10 without manager PIN"

# 5B: with manager credentials -> should succeed
st, data = call_api("record_cash_movement", {
    "type": "cash_out",
    "amount_usd": 25.00,
    "amount_khr": 0,
    "reason": "Workshop spare supplies",
    "cashier_id": 2,
    "manager_username": "owner",
    "manager_pin": "123456"
})
print("Cash Out > $10 with PIN response:", st, data)
assert st == 200 and data.get("success"), "Cash Out > $10 with valid manager PIN should succeed"
assert data.get("manager_name") == "Shop Owner"

print("\n--- TEST 6: OPEN DRAWER (NO SALE) ---")
st, data = call_api("open_drawer_no_sale", {
    "reason": "Inspection of coin hopper",
    "manager_username": "owner",
    "manager_pin": "123456",
    "cashier_id": 2
})
print("Open drawer response:", st, data)
assert st == 200 and data.get("success"), "Open drawer should succeed"
assert data.get("shift_id") == shift_id, "Should record shift_id"

print("\n--- TEST 7: EDIT OPENING FLOAT ---")
st, data = call_api("edit_opening_float", {
    "opening_cash_usd": 70.00,
    "opening_cash_khr": 0,
    "reason": "Cashier miscounted initial float envelope",
    "manager_username": "owner",
    "manager_pin": "123456",
    "cashier_id": 2
})
print("Edit Opening Float response:", st, data)
assert st == 200 and data.get("success"), "Edit Opening Float should succeed"
assert float(data.get("new_usd")) == 70.00

print("\n--- TEST 8: CHECK EXPECTED CASH CALCULATION ---")
# Expected cash calculation:
# Opening Float: 70.00
# Cash In: +15.00
# Cash Out: -5.00 - 25.00 = -30.00
# Expected = 70 + 15 - 30 = 55.00 USD
st, data = call_api("get_shift_status", {"cashier_id": 2})
print("Shift status response:", st, data)
assert st == 200
calc = data.get("calculated", {})
print("Calculated expected cash:", calc.get("expected_cash_usd"), "Cash In:", calc.get("cash_in_usd"), "Cash Out:", calc.get("cash_out_usd"))
assert float(calc.get("cash_in_usd")) == 15.00, "Cash in should be 15.00"
assert float(calc.get("cash_out_usd")) == 30.00, "Cash out should be 30.00"
assert float(calc.get("expected_cash_usd")) == 55.00, "Expected cash should be exactly 55.00"

print("\n--- TEST 9: REPRINT RECEIPT LOGGING ---")
with conn.cursor() as cur:
    cur.execute("SELECT id, order_number FROM orders WHERE (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled' LIMIT 1")
    paid_order = cur.fetchone()

if paid_order:
    p_id, p_num = paid_order
    st, data = call_api("reprint_receipt", {
        "order_id": p_id,
        "cashier_id": 2
    })
    print("Reprint receipt response:", st, data)
    assert st == 200 and data.get("success"), "Reprint receipt should succeed"
    assert data.get("shift_id") == shift_id

    # Verify audit log
    with conn.cursor() as cur:
        cur.execute("SELECT action, details, created_at FROM audit_logs WHERE action = 'REPRINT_RECEIPT' ORDER BY id DESC LIMIT 1")
        log = cur.fetchone()
        print("Audit log entry for reprint:", log)
        assert log and "REPRINT_RECEIPT" in log[0]

print("\n--- ALL BACKEND CHECKS AND CALCULATIONS PASSED! ---")
conn.close()
