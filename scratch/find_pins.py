import pymysql
import subprocess

conn = pymysql.connect(host='localhost', user='root', password='', database='carwash_db')
with conn.cursor() as cur:
    cur.execute("SELECT id, username, role, password_hash, local_pin_hash FROM users")
    for row in cur.fetchall():
        uid, uname, role, phash, lhash = row
        print(f"Testing user {uname} ({role})...")
        for pin in ['1234', '123456', 'password', 'admin', 'admin123', 'owner', 'owner123', '0000', '1111', '8888', '9999', 'visal']:
            res = subprocess.run(['php', '-r', f"echo password_verify('{pin}', '{phash}') ? 'P_MATCH' : ''; echo password_verify('{pin}', '{lhash}') ? 'L_MATCH' : '';"], capture_output=True, text=True)
            if 'MATCH' in res.stdout:
                print(f"  FOUND for {uname}: PIN/Pass = '{pin}' -> {res.stdout}")
