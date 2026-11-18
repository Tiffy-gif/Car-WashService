with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

old_thead = """                <thead>
                    <tr>
                        <th style="min-width: 220px;">Campaign &amp; Rule</th>
                        <th style="min-width: 160px;">When Ordered (Trigger)</th>
                        <th style="min-width: 140px;">Offer Action Type</th>
                        <th style="min-width: 180px;">Offer Service &amp; Promo Price</th>
                        <th style="min-width: 90px;">Status</th>
                        <th style="text-align: right; min-width: 90px;">Actions</th>
                    </tr>
                </thead>"""

new_thead = """                <thead>
                    <tr>
                        <th style="width: 26%;">Campaign &amp; Rule</th>
                        <th style="width: 18%;">When Ordered (Trigger)</th>
                        <th style="width: 15%;">Offer Action Type</th>
                        <th style="width: 20%;">Offer Service &amp; Promo Price</th>
                        <th style="width: 10%; text-align: center;">Status</th>
                        <th style="width: 11%; text-align: right;">Actions</th>
                    </tr>
                </thead>"""

# Also center the status column td:
old_status_td = """                                <td>
                                    <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" style="margin: 0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_upsell_rule">
                                        <input type="hidden" name="tab" value="upsell">
                                        <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                                        <button type="submit" class="badge <?= $isActive ? 'badge-success' : 'badge-slate' ?>" style="cursor: pointer; border: none; padding: 4px 10px; font-family: inherit;" title="Click to toggle status">
                                            <i class="fa-solid <?= $isActive ? 'fa-circle-check' : 'fa-pause' ?> mr-1"></i>
                                            <?= $isActive ? 'Active' : 'Paused' ?>
                                        </button>
                                    </form>
                                </td>"""

new_status_td = """                                <td style="text-align: center;">
                                    <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" style="margin: 0; display: inline-block;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_upsell_rule">
                                        <input type="hidden" name="tab" value="upsell">
                                        <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                                        <button type="submit" class="badge <?= $isActive ? 'badge-success' : 'badge-slate' ?>" style="cursor: pointer; border: none; padding: 4px 10px; font-family: inherit;" title="Click to toggle status">
                                            <i class="fa-solid <?= $isActive ? 'fa-circle-check' : 'fa-pause' ?> mr-1"></i>
                                            <?= $isActive ? 'Active' : 'Paused' ?>
                                        </button>
                                    </form>
                                </td>"""

if old_thead in text:
    text = text.replace(old_thead, new_thead)
    print("Replaced thead widths!")
else:
    print("Warning: old_thead not found")

if old_status_td in text:
    text = text.replace(old_status_td, new_status_td)
    print("Replaced status td alignment!")
else:
    print("Warning: old_status_td not found")

with open('views/owner/settings.php', 'w', encoding='utf-8') as f:
    f.write(text)

print("Updated table columns!")
