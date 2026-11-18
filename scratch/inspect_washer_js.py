import re

with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

def search_func(name):
    idx = text.find(name)
    if idx != -1:
        print(f"=== {name} ===")
        print(text[idx:idx+1500])

search_func('renderWasherBays')
search_func('renderWasherScreen')
search_func('assignWasherBay')
search_func('completeWasherJob')
