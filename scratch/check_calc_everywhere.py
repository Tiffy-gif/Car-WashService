import os, re

for root, dirs, files in os.walk('.'):
    for f in files:
        if f.endswith('.php'):
            p = os.path.join(root, f)
            t = open(p, encoding='utf-8', errors='ignore').read()
            if 'calculateShiftExpectedCash' in t:
                print("Found in:", p)
