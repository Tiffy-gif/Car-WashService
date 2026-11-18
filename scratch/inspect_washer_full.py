with open('pos/index.html', 'r', encoding='utf-8') as f:
    text = f.read()

start = text.find('id="viewWasher"')
end = text.find('</section>', start)
print(text[start:start+4000])
print("\n--- SECOND CHUNK ---\n")
print(text[start+4000:end+10])
