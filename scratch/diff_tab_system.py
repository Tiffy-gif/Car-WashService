import subprocess

cmd = ['git', 'show', '49620b7:views/owner/settings.php']
old_text = subprocess.check_output(cmd, encoding='utf-8')

with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    new_text = f.read()

def get_tab(text, tab_id, next_tab_id):
    p1 = text.find(f'id="{tab_id}"')
    p2 = text.find(f'id="{next_tab_id}"')
    return text[p1:p2]

old_system = get_tab(old_text, 'tab_system', 'tab_history')
new_system = get_tab(new_text, 'tab_system', 'tab_history')

print("Old system tab length:", len(old_system))
print("New system tab length:", len(new_system))
print("Are they identical?", old_system == new_system)
if old_system != new_system:
    import difflib
    diff = list(difflib.unified_diff(old_system.splitlines(), new_system.splitlines()))
    print("Diff in tab_system:")
    for l in diff[:30]:
        print(l)
