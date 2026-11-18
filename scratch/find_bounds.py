with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos1 = text.find('id="tab_upsell"')
# find the comment right above pos1
comment_pos = text.rfind('<!-- ======================================================== -->', 0, pos1)
line1 = text[:comment_pos].count('\n') + 1

pos2 = text.find('<!-- GROUP 6B: PERFORMANCE')
line2 = text[:pos2].count('\n') + 1

print(f"Comment start line: {line1}, GROUP 6B line: {line2}")
print("Text before group 6b:")
print(text[pos2-200:pos2])
