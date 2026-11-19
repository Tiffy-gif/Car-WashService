import urllib.request
import json
import mysql.connector

BASE_URL = 'http://localhost/Car-WashService/api/pos/index.php'
DEVICE_KEY = 'my_test_device_key_unique9'

def api_call(action, payload=None, token=None):
    url = f"{BASE_URL}?action={action}"
    headers = {
        'Content-Type': 'application/json',
        'X-Device-Key': DEVICE_KEY
    }
    if token:
        headers['X-Staff-Token'] = token

    data_bytes = json.dumps(payload or {}).encode('utf-8')
    req = urllib.request.Request(url, data=data_bytes, headers=headers, method='POST')
    try:
        with urllib.request.urlopen(req) as resp:
            return resp.status, json.loads(resp.read().decode('utf-8'))
    except urllib.error.HTTPError as e:
        body = e.read().decode('utf-8')
        try:
            return e.code, json.loads(body)
        except:
            return e.code, {'raw': body}

# 1. Login as Cashier Visal
status, login_res = api_call('staff_login', {'username': 'visal', 'pin': '123456'})
print(f"Login Visal status: {status}, token: {login_res.get('session_token')[:8] if login_res.get('session_token') else 'None'}")
assert login_res.get('success') == True
token = login_res['session_token']

# 2. Check if there are open shifts today. All shifts must be closed to close the day.
# Close any open shifts for testing close day
conn = mysql.connector.connect(host='localhost', user='root', password='', database='carwash_db')
cursor = conn.cursor(dictionary=True)
cursor.execute("UPDATE cashier_shifts SET status = 'ended', ended_at = NOW() WHERE status = 'open'")
# Ensure today is not closed yet so we can test closing it
cursor.execute("DELETE FROM pos_daily_closes WHERE close_date = CURDATE()")
conn.commit()
print("Prepared database: closed active shifts and cleared today's close record.")

# 3. Test verify_manager_pin with purpose='close_day' for Cashier Visal
status, v_res = api_call('verify_manager_pin', {
    'manager_username': 'visal',
    'manager_pin': '123456',
    'purpose': 'close_day'
}, token)
print(f"verify_manager_pin purpose='close_day' for visal: status={status}, res={v_res}")
assert v_res.get('success') == True, f"Failed: {v_res}"
assert v_res.get('manager', {}).get('role') == 'cashier'

# 4. Test close_day_zreport by Cashier Visal!
status, close_res = api_call('close_day_zreport', {
    'manager_username': 'visal',
    'manager_pin': '123456',
    'counted_cash_usd': 55.00,
    'counted_cash_khr': 0,
    'notes': 'Closed by cashier Visal test'
}, token)
print(f"close_day_zreport by Cashier Visal: status={status}, res={close_res}")
assert close_res.get('success') == True, f"Failed: {close_res}"
assert close_res.get('closed_by_name') == 'visal'
assert close_res.get('role') == 'cashier'

# 5. Check pos_daily_closes table in database
cursor.execute("SELECT id, close_date, closed_by_name, role, z_report_number, status FROM pos_daily_closes WHERE close_date = CURDATE() ORDER BY id DESC LIMIT 1")
row = cursor.fetchone()
print(f"pos_daily_closes record: {row}")
assert row is not None
assert row['closed_by_name'] == 'visal'
assert row['role'] == 'cashier'
assert row['status'] == 'closed'

# 6. Check audit_logs table for DAY_CLOSED
cursor.execute("SELECT id, log_uid, action, username, role, details, created_at FROM audit_logs WHERE action = 'DAY_CLOSED' ORDER BY id DESC LIMIT 1")
audit_row = cursor.fetchone()
print(f"Audit log record: {audit_row}")
assert audit_row is not None
assert audit_row['username'] == 'visal'
assert audit_row['role'] == 'cashier'
assert 'visal' in audit_row['details']

print("\n--- ALL CASHIER CLOSE DAY BACKEND AND AUDIT TESTS PASSED! ---")
conn.close()
