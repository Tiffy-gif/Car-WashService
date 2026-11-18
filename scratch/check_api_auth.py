with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find('staff_login')
idx2 = text.find('session_token', idx)
print(text[idx2-100:idx2+600])

idx_auth = text.find('function requireAuth')
if idx_auth == -1:
    idx_auth = text.find('requireStaffAuth')
if idx_auth == -1:
    idx_auth = text.find('verify_session')
print("Auth check:")
print(text[idx_auth:idx_auth+600])
