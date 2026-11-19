import subprocess

p = subprocess.run(['git', 'show', 'HEAD~2:views/owner/settings.php'], capture_output=True, text=True, encoding='utf-8')
for line in p.stdout.splitlines():
    if 'shop_address' in line or 'shop_phone' in line or 'St. 271' in line:
        print(line)
