with open('public/css/style.css', 'r', encoding='utf-8') as f:
    text = f.read()

print("Length of style.css:", len(text))
# Check root CSS variables and main component classes
lines = text.splitlines()
for l in lines[:70]:
    print(l)
