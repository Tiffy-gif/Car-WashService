import re

with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

for m in re.finditer(r'static\s+(renderWasher[a-zA-Z0-9_]+|assignToBay|finishWasher[a-zA-Z0-9_]+|startWash[a-zA-Z0-9_]+)', text):
    start = m.start()
    print(m.group(0))
    print(text[start:start+1200])
    print("="*60)
