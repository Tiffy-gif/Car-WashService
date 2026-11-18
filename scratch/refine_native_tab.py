with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

# 1. Update table header widths so Actions column is never cut off
old_thead = """                <thead>
                    <tr>
                        <th style="min-width: 240px;">Campaign &amp; Rule</th>
                        <th style="min-width: 190px;">When Ordered (Trigger)</th>
                        <th style="min-width: 160px;">Offer Action Type</th>
                        <th style="min-width: 200px;">Offer Service &amp; Promo Price</th>
                        <th style="min-width: 110px;">Status</th>
                        <th style="text-align: right; min-width: 120px;">Actions</th>
                    </tr>
                </thead>"""

new_thead = """                <thead>
                    <tr>
                        <th style="min-width: 220px;">Campaign &amp; Rule</th>
                        <th style="min-width: 160px;">When Ordered (Trigger)</th>
                        <th style="min-width: 140px;">Offer Action Type</th>
                        <th style="min-width: 180px;">Offer Service &amp; Promo Price</th>
                        <th style="min-width: 90px;">Status</th>
                        <th style="text-align: right; min-width: 90px;">Actions</th>
                    </tr>
                </thead>"""

if old_thead in text:
    text = text.replace(old_thead, new_thead)
    print("Updated table header widths!")
else:
    print("Warning: old_thead not found")

# 2. Update Modal so modal-header and modal-footer are fixed while modal-body scrolls cleanly
old_modal = """<div class="modal-backdrop" id="modalUpsellRule">
    <div class="modal" style="max-width: 680px;">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fa-solid fa-bullseye mr-2" style="color: var(--primary);"></i>
                <span id="upsellModalTitle">Add Promotion Rule</span>
            </h3>
            <button type="button" class="modal-close" onclick="closeModal('modalUpsellRule')">&times;</button>
        </div>

        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" id="formUpsellRule">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_upsell_rule">
            <input type="hidden" name="tab" value="upsell">
            <input type="hidden" id="upsell_rule_id" name="rule_id" value="0">

            <div class="modal-body">"""

new_modal = """<div class="modal-backdrop" id="modalUpsellRule">
    <div class="modal" style="max-width: 680px; display: flex; flex-direction: column; max-height: 90vh;">
        <div class="modal-header" style="flex-shrink: 0;">
            <h3 class="modal-title">
                <i class="fa-solid fa-bullseye mr-2" style="color: var(--primary);"></i>
                <span id="upsellModalTitle">Add Promotion Rule</span>
            </h3>
            <button type="button" class="modal-close" onclick="closeModal('modalUpsellRule')">&times;</button>
        </div>

        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" id="formUpsellRule" style="display: flex; flex-direction: column; overflow: hidden; flex: 1; margin: 0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_upsell_rule">
            <input type="hidden" name="tab" value="upsell">
            <input type="hidden" id="upsell_rule_id" name="rule_id" value="0">

            <div class="modal-body" style="overflow-y: auto; flex: 1; padding: 20px 24px;">"""

if old_modal in text:
    text = text.replace(old_modal, new_modal)
    print("Updated modal scrolling structure!")
else:
    print("Warning: old_modal not found")

# 3. Ensure modal-footer has flex-shrink: 0
old_footer = """            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalUpsellRule')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check mr-1"></i> Save Promotion Rule
                </button>
            </div>"""

new_footer = """            <div class="modal-footer" style="flex-shrink: 0;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalUpsellRule')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check mr-1"></i> Save Promotion Rule
                </button>
            </div>"""

if old_footer in text:
    text = text.replace(old_footer, new_footer)
    print("Updated modal-footer style!")
else:
    print("Warning: old_footer not found")

with open('views/owner/settings.php', 'w', encoding='utf-8') as f:
    f.write(text)

print("Saved refined settings.php successfully!")
