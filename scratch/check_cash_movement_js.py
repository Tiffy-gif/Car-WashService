import os, re

for fname in os.listdir('pos/js'):
    if fname.endswith('.js'):
        path = os.path.join('pos/js', fname)
        text = open(path, encoding='utf-8').read()
        for m in re.finditer(r'recordCashMovement|record_cash_movement', text):
            print(f"Found in {fname} at {m.start()}")
            print(text[max(0, m.start()-50):min(len(text), m.start()+200)])
