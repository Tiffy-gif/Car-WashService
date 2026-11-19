with open('scratch/component_from_03db.html', 'r', encoding='utf-8') as f:
    lines = f.readlines()

print("Total lines:", len(lines))
print("\nFirst 40 lines:")
for l in lines[:40]:
    print(l, end='')

print("\nLast 40 lines:")
for l in lines[-40:]:
    print(l, end='')
