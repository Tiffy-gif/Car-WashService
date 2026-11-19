import json
import urllib.request

req = urllib.request.urlopen("http://localhost/Car-WashService/api/pos/index.php?action=get_day_status")
print(req.read().decode('utf-8'))
