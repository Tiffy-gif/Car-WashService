import os

for f in ['services.php', 'discounts.php', 'devices.php']:
    p = os.path.join('views', 'owner', f)
    if os.path.exists(p):
        print(f"\n================= {f} =================")
        with open(p, 'r', encoding='utf-8') as file:
            c = file.read()
            print(c[:1500])
