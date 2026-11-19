with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    lines = f.readlines()

print('Line 634:', lines[633])
print('Line 635-645:', ''.join(lines[634:645]))
print('Line 735-745:', ''.join(lines[734:745]))
print('Line 810-825:', ''.join(lines[809:825]))
