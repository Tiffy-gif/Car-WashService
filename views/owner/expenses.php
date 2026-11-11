<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';

Auth::requirePermission('expenses.list');
$pageTitle = __('nav_expenses');
$pdo = Database::getInstance();

$message = null;
$messageType = 'success';

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

// Handle Add / Edit / Delete Expense
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($csrfError)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_expense') {
        $expId = !empty($_POST['expense_id']) ? (int)$_POST['expense_id'] : null;
        if ($expId && !Auth::can('expenses.edit')) {
            $message = 'Permission denied: Cannot edit expense.';
            $messageType = 'danger';
        } elseif (!$expId && !Auth::can('expenses.add')) {
            $message = 'Permission denied: Cannot add expense.';
            $messageType = 'danger';
        } else {
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $category = trim($_POST['category'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $usd = max(0.00, (float)($_POST['amount_usd'] ?? 0.00));
        $khr = (int)round($usd * get_exchange_rate());
        $date = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $recordedBy = Auth::getUserId();

        // Resolve category_id and category slug
        if ($categoryId) {
            $catLookup = $pdo->prepare("SELECT id, code, name_en FROM categories WHERE id = ?");
            $catLookup->execute([$categoryId]);
            if ($cRow = $catLookup->fetch()) {
                $category = $cRow['code'];
            }
        } elseif ($category !== '') {
            $catLookup = $pdo->prepare("SELECT id, code, name_en FROM categories WHERE type = 'expense' AND (code = ? OR name_en = ?)");
            $catLookup->execute([$category, $category]);
            if ($cRow = $catLookup->fetch()) {
                $categoryId = (int)$cRow['id'];
                $category = $cRow['code'];
            }
        }

        if (!$categoryId && empty($category)) {
            $catLookup = $pdo->query("SELECT id, code FROM categories WHERE type = 'expense' ORDER BY sort_order ASC LIMIT 1");
            if ($cRow = $catLookup->fetch()) {
                $categoryId = (int)$cRow['id'];
                $category = $cRow['code'];
            } else {
                $category = 'Water';
            }
        }

        if ($desc === '' || $usd <= 0) {
            $message = 'Description and a valid amount greater than $0 are required.';
            $messageType = 'danger';
        } else {
            if ($expId) {
                $stmt = $pdo->prepare("UPDATE expenses SET category = ?, category_id = ?, description = ?, amount_usd = ?, amount_khr = ?, expense_date = ? WHERE id = ?");
                $stmt->execute([$category, $categoryId, $desc, $usd, $khr, $date, $expId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO expenses (category, category_id, description, amount_usd, amount_khr, expense_date, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$category, $categoryId, $desc, $usd, $khr, $date, $recordedBy]);
            }
            $message = 'Expense saved successfully!';
        }
        }
    } elseif ($action === 'delete_expense') {
        if (!Auth::can('expenses.delete')) {
            $message = 'Permission denied: Cannot delete expense.';
            $messageType = 'danger';
        } else {
            $expId = (int)($_POST['expense_id'] ?? 0);
            if ($expId > 0) {
                // SOFT DELETE per requirement
                $pdo->prepare("UPDATE expenses SET is_active = 0 WHERE id = ?")->execute([$expId]);
                audit_log('EXPENSE_CANCEL', "Cancelled expense #{$expId} (soft delete)", 'expense', (string)$expId);
                $message = 'Expense cancelled successfully (soft deleted)!';
            }
        }
    }
}

// Fetch All Expense Categories
$expenseCategories = $pdo->query("SELECT * FROM categories WHERE type = 'expense' AND is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
$expCatMap = [];
foreach ($expenseCategories as $ec) {
    $expCatMap['id_' . $ec['id']] = $ec;
    $expCatMap['code_' . $ec['code']] = $ec;
}

// Filter month/year
$selectedMonth = $_GET['month'] ?? date('Y-m');
$expStmt = $pdo->prepare("
    SELECT e.*, u.full_name as recorder_name 
    FROM expenses e 
    LEFT JOIN users u ON e.recorded_by = u.id 
    WHERE DATE_FORMAT(e.expense_date, '%Y-%m') = ?
      AND (e.is_active IS NULL OR e.is_active = 1)
    ORDER BY e.expense_date DESC, e.id DESC
");
$expStmt->execute([$selectedMonth]);
$expenses = $expStmt->fetchAll();

// Month summary stats
$totalMonthUsd = 0.00;
$catBreakdown = [];
foreach ($expenses as $e) {
    $totalMonthUsd += (float)$e['amount_usd'];
    $catKey = !empty($e['category_id']) ? ('id_' . $e['category_id']) : ('code_' . $e['category']);
    $catBreakdown[$catKey] = ($catBreakdown[$catKey] ?? 0.00) + (float)$e['amount_usd'];
}

// Today expenses
$todayStmt = $pdo->query("SELECT COALESCE(SUM(amount_usd), 0) FROM expenses WHERE expense_date = CURDATE() AND (is_active IS NULL OR is_active = 1)");
$todayUsd = (float)$todayStmt->fetchColumn();

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <span class="badge badge-primary" style="padding: 6px 12px; font-size: 13px;">
            <i class="fa-solid fa-receipt mr-1"></i> <?= count($expenses) ?> Expense Records
        </span>
        <span style="font-size: 13px; color: var(--text-muted);">
            Monthly Operating Expenses &amp; Outflow Ledger
        </span>
    </div>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <input type="month" class="form-input" style="min-height: 40px; padding: 6px 14px; width: 170px;" value="<?= htmlspecialchars($selectedMonth) ?>" onchange="location.href='?view=expenses&month=' + this.value">
        <button type="button" class="btn btn-primary" onclick="openNewExpenseModal()">
            <i class="fa-solid fa-plus mr-1"></i> Add New Expense
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<!-- Metric KPI Cards -->
<div class="grid grid-cols-3" style="margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-icon amber">
            <i class="fa-solid fa-receipt"></i>
        </div>
        <div>
            <div class="stat-value"><?= formatUSD($totalMonthUsd) ?></div>
            <div class="stat-label">Total Expenses This Month <span class="text-xs text-muted">(<?= formatKHR((int)round($totalMonthUsd * get_exchange_rate())) ?>)</span></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="fa-solid fa-calendar-day"></i>
        </div>
        <div>
            <div class="stat-value"><?= formatUSD($todayUsd) ?></div>
            <div class="stat-label">Today's Expenses <span class="text-xs text-muted">(<?= formatKHR((int)round($todayUsd * get_exchange_rate())) ?>)</span></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fa-solid fa-chart-pie"></i>
        </div>
        <div>
            <div class="stat-value"><?= count($expenses) ?> records</div>
            <div class="stat-label">Recorded entries in <?= date('F Y', strtotime($selectedMonth . '-01')) ?></div>
        </div>
    </div>
</div>

<!-- Category Distribution Pills -->
<?php if (!empty($catBreakdown)): ?>
<div class="card" style="margin-bottom: 24px; padding: 18px 24px;">
    <div class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700; margin-bottom: 12px;">Expenditure by Category (<?= htmlspecialchars($selectedMonth) ?>)</div>
    <div style="display: flex; gap: 14px; flex-wrap: wrap;">
        <?php foreach ($catBreakdown as $cKey => $cAmt): 
            $pct = ($totalMonthUsd > 0) ? round(($cAmt / $totalMonthUsd) * 100) : 0;
            $cObj = $expCatMap[$cKey] ?? null;
            $cName = $cObj ? ((Language::current() === 'km') ? $cObj['name_km'] : $cObj['name_en']) : str_replace(['id_', 'code_'], '', $cKey);
            $cColor = $cObj['color'] ?? 'var(--primary)';
            $cIcon = $cObj['icon'] ?? 'fa-receipt';
        ?>
            <div style="background: var(--bg-subtle); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 10px 16px; flex: 1; min-width: 140px; border-left: 4px solid <?= htmlspecialchars($cColor) ?>;">
                <div class="text-xs text-muted" style="display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid <?= htmlspecialchars($cIcon) ?>" style="color: <?= htmlspecialchars($cColor) ?>;"></i>
                    <span><?= htmlspecialchars($cName) ?></span>
                </div>
                <div style="font-size: 17px; font-weight: 700; color: var(--text-main); margin-top: 2px;"><?= formatUSD($cAmt) ?></div>
                <div class="text-xs" style="color: var(--primary); font-weight: 600;"><?= $pct ?>% of total</div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Expenses List Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table searchable-table">
            <thead>
                <tr>
                    <th style="width: 130px;">Date</th>
                    <th style="width: 140px;">Category</th>
                    <th>Description</th>
                    <th style="width: 140px;">Amount ($)</th>
                    <th style="width: 140px;">Amount (៛)</th>
                    <th style="width: 150px;">Recorded By</th>
                    <th style="text-align: right; width: 120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($expenses)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="fa-solid fa-receipt" style="font-size: 32px; opacity: 0.3; margin-bottom: 8px;"></i>
                            <p>No expenses logged for this month. Click "+ Add New Expense" to record one.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expenses as $ex): 
                        $cObj = null;
                        if (!empty($ex['category_id']) && isset($expCatMap['id_' . $ex['category_id']])) {
                            $cObj = $expCatMap['id_' . $ex['category_id']];
                        } elseif (!empty($ex['category']) && isset($expCatMap['code_' . $ex['category']])) {
                            $cObj = $expCatMap['code_' . $ex['category']];
                        }
                        $cColor = $cObj['color'] ?? '#0284c7';
                        $cIcon = $cObj['icon'] ?? 'fa-tag';
                        $cName = $cObj ? ((Language::current() === 'km') ? $cObj['name_km'] : $cObj['name_en']) : $ex['category'];
                    ?>
                    <tr>
                        <td>
                            <strong style="color: var(--text-main);"><?= htmlspecialchars($ex['expense_date']) ?></strong>
                        </td>
                        <td>
                            <span class="badge" style="background: <?= htmlspecialchars($cColor) ?>18; color: <?= htmlspecialchars($cColor) ?>; border: 1px solid <?= htmlspecialchars($cColor) ?>35;">
                                <i class="fa-solid <?= htmlspecialchars($cIcon) ?> mr-1"></i> <?= htmlspecialchars($cName) ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($ex['description']) ?></div>
                        </td>
                        <td>
                            <strong style="font-size: 15px; color: var(--danger);"><?= formatUSD((float)$ex['amount_usd']) ?></strong>
                        </td>
                        <td>
                            <span class="text-muted"><?= formatKHR((int)$ex['amount_khr']) ?></span>
                        </td>
                        <td>
                            <span class="text-xs text-muted"><?= htmlspecialchars($ex['recorder_name'] ?? 'Admin') ?></span>
                        </td>
                        <td style="text-align: right;">
                            <button type="button" class="btn btn-secondary btn-sm" data-expense="<?= htmlspecialchars(json_encode($ex, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" onclick="editExpense(this)">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <form action="" method="POST" style="display: inline;" onsubmit="return confirm('<?= htmlspecialchars(__('confirm_delete')) ?>');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_expense">
                                <input type="hidden" name="expense_id" value="<?= $ex['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add / Edit Expense -->
<div class="modal-backdrop" id="expenseModal">
    <div class="modal" style="max-width: 520px;">
        <div class="modal-header">
            <h3 class="modal-title" id="expModalTitle">Record Operating Expense</h3>
            <button type="button" class="modal-close" onclick="closeModal('expenseModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_expense">
            <input type="hidden" name="expense_id" id="exp_id" value="">

            <div class="modal-body">
                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Category *</label>
                        <input type="hidden" name="category_id" id="exp_category_id" value="">
                        <div style="position: relative; display: flex; align-items: center;">
                            <span style="position: absolute; left: 12px; pointer-events: none; color: var(--primary); font-size: 14px; z-index: 2;">
                                <i id="exp_category_icon" class="fa-solid fa-water"></i>
                            </span>
                            <select name="category" id="exp_category" class="form-select" style="padding-left: 36px; width: 100%;" onchange="updateExpCatChoice(this)">
                                <?php foreach ($expenseCategories as $ec): 
                                    $ecName = (Language::current() === 'km') ? $ec['name_km'] : $ec['name_en'];
                                    $ecIcon = !empty($ec['icon']) ? $ec['icon'] : 'fa-tag';
                                    $ecColor = !empty($ec['color']) ? $ec['color'] : '#0284c7';
                                ?>
                                    <option value="<?= htmlspecialchars($ec['code']) ?>" data-id="<?= $ec['id'] ?>" data-icon="<?= htmlspecialchars($ecIcon) ?>" data-color="<?= htmlspecialchars($ecColor) ?>">
                                        <?= htmlspecialchars($ecName) ?> (<?= htmlspecialchars($ec['name_en']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Expense Date *</label>
                        <input type="date" name="expense_date" id="exp_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Amount ($ USD) *</label>
                    <input type="number" step="0.25" min="0.25" name="amount_usd" id="exp_amount" class="form-input" style="font-size: 18px; font-weight: 700;" placeholder="0.00" required oninput="updateKhrPreview()">
                    <div class="text-xs text-muted" style="margin-top: 4px;">Equivalent in KHR: <strong id="expKhrPreview">0 ៛</strong> ($1 = 4,100 ៛)</div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Description / Reason *</label>
                    <input type="text" name="description" id="exp_desc" class="form-input" placeholder="e.g. PPWSA clean water monthly invoice" required>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('expenseModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk mr-1"></i> <?= htmlspecialchars(__('save')) ?></button>
            </div>
        </form>
    </div>
</div>

<script>
const expenseCategoriesList = <?= json_encode($expenseCategories) ?>;

function updateExpCatChoice(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt) {
        document.getElementById('exp_category_id').value = opt.dataset.id || '';
        const iconName = opt.dataset.icon || 'fa-tag';
        const color = opt.dataset.color || 'var(--primary)';
        const el = document.getElementById('exp_category_icon');
        if (el) {
            el.className = 'fa-solid ' + iconName;
            el.style.color = color;
        }
    }
}

function openNewExpenseModal() {
    document.getElementById('expModalTitle').innerText = 'Record Operating Expense';
    document.getElementById('exp_id').value = '';
    const defaultCat = expenseCategoriesList[0] || { id: '', code: 'Water', icon: 'fa-water', color: '#0284c7' };
    document.getElementById('exp_category').value = defaultCat.code;
    document.getElementById('exp_category_id').value = defaultCat.id;
    const el = document.getElementById('exp_category_icon');
    if (el) {
        el.className = 'fa-solid ' + (defaultCat.icon || 'fa-water');
        el.style.color = defaultCat.color || 'var(--primary)';
    }
    document.getElementById('exp_date').value = '<?= date('Y-m-d') ?>';
    document.getElementById('exp_amount').value = '';
    document.getElementById('exp_desc').value = '';
    document.getElementById('expKhrPreview').innerText = '0 ៛';
    openModal('expenseModal');
}

function editExpense(btnOrEx) {
    let ex = btnOrEx;
    if (btnOrEx && btnOrEx.getAttribute && btnOrEx.getAttribute('data-expense')) {
        try {
            ex = JSON.parse(btnOrEx.getAttribute('data-expense'));
        } catch (e) {
            console.error('Error parsing expense data:', e);
            return;
        }
    }
    if (!ex) return;

    document.getElementById('expModalTitle').innerText = 'Edit Expense #' + (ex.id || '');
    document.getElementById('exp_id').value = ex.id || '';
    
    let matchedCat = (Array.isArray(expenseCategoriesList)) 
        ? expenseCategoriesList.find(c => (ex.category_id && c.id == ex.category_id) || (ex.category && (c.code === ex.category || c.name_en === ex.category)))
        : null;
    if (matchedCat) {
        document.getElementById('exp_category').value = matchedCat.code;
        document.getElementById('exp_category_id').value = matchedCat.id;
        const el = document.getElementById('exp_category_icon');
        if (el) {
            el.className = 'fa-solid ' + (matchedCat.icon || 'fa-tag');
            el.style.color = matchedCat.color || 'var(--primary)';
        }
    } else {
        document.getElementById('exp_category').value = ex.category;
        document.getElementById('exp_category_id').value = ex.category_id || '';
    }
    
    document.getElementById('exp_date').value = ex.expense_date;
    document.getElementById('exp_amount').value = ex.amount_usd;
    document.getElementById('exp_desc').value = ex.description;
    updateKhrPreview();
    openModal('expenseModal');
}

function updateKhrPreview() {
    const usd = parseFloat(document.getElementById('exp_amount').value) || 0;
    const khr = Math.round(usd * 4100);
    document.getElementById('expKhrPreview').innerText = khr.toLocaleString() + ' ៛';
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
