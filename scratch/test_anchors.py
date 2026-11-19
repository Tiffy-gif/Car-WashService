with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Let's locate the key anchors
header_anchor = "require_once dirname(__DIR__) . '/layouts/header.php';\n?>"
print("Header anchor found:", header_anchor in content)

tab_shop_start = '<div id="tab_shop" class="tab-pane"'
print("tab_shop found:", tab_shop_start in content)

tab_receipt_start = '<div id="tab_receipt" class="tab-pane"'
print("tab_receipt found:", tab_receipt_start in content)

script_anchor = "<script>\n// Tab Switching"
print("script anchor found:", script_anchor in content)
