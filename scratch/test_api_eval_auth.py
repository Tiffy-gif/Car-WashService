import urllib.request, json

# 1. Staff login
login_data = json.dumps({'username': 'owner', 'pin': '123456'}).encode()
req = urllib.request.Request(
    'http://localhost/Car-WashService/api/pos/index.php?action=staff_login',
    data=login_data,
    headers={
        'Content-Type': 'application/json',
        'X-Device-UID': 'POS-CAASIER',
        'X-Device-Key': 'my_test_device_key_unique9'
    }
)
with urllib.request.urlopen(req) as resp:
    res = json.loads(resp.read().decode())
    token = res.get('session_token')
    print("Login success, token:", token)

# 2. Evaluate cart upsell with X-Staff-Token
eval_data = json.dumps({
    'cart': [{'id': 1, 'service_id': 1, 'type': 'service', 'price_usd': 8.5}],
    'customer_id': 0,
    'plate_number': '',
    'vehicle_type': 'small_car'
}).encode()

req2 = urllib.request.Request(
    'http://localhost/Car-WashService/api/pos/index.php?action=evaluate_cart_upsell',
    data=eval_data,
    headers={
        'Content-Type': 'application/json',
        'X-Device-UID': 'POS-CAASIER',
        'X-Device-Key': 'my_test_device_key_unique9',
        'X-Staff-Token': token
    }
)
with urllib.request.urlopen(req2) as resp2:
    print("Evaluate response:")
    print(json.dumps(json.loads(resp2.read().decode()), indent=2))
