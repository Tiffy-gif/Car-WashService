import re

with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

tabs = re.findall(r'id="(tab_[^"]+)"', text)
print("Tabs found:", tabs)

# Let's inspect tab_shop, tab_receipt, tab_bays, tab_loyalty, tab_cloud
for tab_name in ['tab_shop', 'tab_receipt', 'tab_bays', 'tab_loyalty', 'tab_perf', 'tab_cloud']:
    pos = text.find(f'id="{tab_name}"')
    if pos != -1:
        print(f"\n--- Snippet for {tab_name} ---")
        print(text[pos:pos+1200])
