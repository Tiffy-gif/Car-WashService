import glob

for f in glob.glob('database/**/*.sql', recursive=True) + glob.glob('database/**/*.php', recursive=True) + glob.glob('config/**/*.php', recursive=True):
    try:
        with open(f, 'r', encoding='utf-8') as file:
            c = file.read()
            if 'shop_address' in c or 'shop_phone' in c:
                print(f)
                for line in c.splitlines():
                    if 'shop_address' in line or 'shop_phone' in line or 'shop_name' in line:
                        print('  ', line[:100])
    except:
        pass
