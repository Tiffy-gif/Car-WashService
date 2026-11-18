import os

for root, dirs, files in os.walk('assets'):
    for f in files:
        if f.endswith('.css'):
            print(os.path.join(root, f))

# Also check root or includes or views for css
for root, dirs, files in os.walk('.'):
    if 'node_modules' in root:
        continue
    for f in files:
        if f.endswith('.css'):
            print(os.path.join(root, f))
