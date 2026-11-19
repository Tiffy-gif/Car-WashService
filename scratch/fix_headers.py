with open('pos/index.html', 'r', encoding='utf-8') as f:
    text = f.read()

text = text.replace(
    '<div class="modal-header" id="cashMovementHeader" style="background: #f0fdf4; border-bottom: 1.5px solid #bbf7d0;">',
    '<div class="modal-header" id="cashMovementHeader" style="display: flex; justify-content: space-between; align-items: center; background: #f0fdf4; border-bottom: 1.5px solid #bbf7d0; padding: 1rem 1.25rem;">'
)

text = text.replace(
    '<div class="modal-header" style="background: #eef2ff; border-bottom: 1.5px solid #c7d2fe;">',
    '<div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; background: #eef2ff; border-bottom: 1.5px solid #c7d2fe; padding: 1rem 1.25rem;">'
)

text = text.replace(
    '<div class="modal-header" style="background: #fff1f2; border-bottom: 1.5px solid #fecdd3;">',
    '<div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; background: #fff1f2; border-bottom: 1.5px solid #fecdd3; padding: 1rem 1.25rem;">'
)

text = text.replace(
    '<div class="modal-header" style="background: #f0f9ff; border-bottom: 1.5px solid #bae6fd;">',
    '<div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; background: #f0f9ff; border-bottom: 1.5px solid #bae6fd; padding: 1rem 1.25rem;">'
)

text = text.replace(
    '<div class="modal-header" style="background: #f0fdfa; border-bottom: 1.5px solid #99f6e4;">',
    '<div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; background: #f0fdfa; border-bottom: 1.5px solid #99f6e4; padding: 1rem 1.25rem;">'
)

with open('pos/index.html', 'w', encoding='utf-8') as f:
    f.write(text)

print("Updated modal headers with flex layout.")
