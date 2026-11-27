    <?php if ($reportKey === 'financial_summary' || $reportKey === 'operations_overview'): ?>
    <!-- 8. Table: Revenue Breakdown by Dynamic Category (Proper Names & Icons) -->
    <div class="report-table-card">
        <div class="report-table-header">
            <div>
                <h2 class="report-table-title">
                    <i class="fa-solid fa-tags" style="color: var(--primary);"></i>
                    Revenue Breakdown by Category
                </h2>
                <span class="text-xs text-muted">Aggregated across all service and product categories defined in Category Control</span>
            </div>
            <div class="text-xs text-muted">
                <?= count($categoryRevenueBreakdown) ?> Categories Active
            </div>
        </div>
        <div class="table-responsive-clean">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 240px;">Category</th>
                        <th style="min-width: 120px;">Type</th>
                        <th class="table-num" style="min-width: 140px;">Items Sold / Serviced</th>
                        <th class="table-num" style="min-width: 160px;">Total Revenue</th>
                        <th style="min-width: 180px;">Share of Gross Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categoryRevenueBreakdown)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state-box">
                                    <div class="empty-state-icon"><i class="fa-solid fa-tags"></i></div>
                                    <div class="empty-state-text">No category sales recorded in this period.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categoryRevenueBreakdown as $cr): 
                            $cShare = ($grossRev > 0) ? round(((float)$cr['total_revenue'] / $grossRev) * 100) : 0;
                            $cColor = !empty($cr['color']) ? $cr['color'] : '#0284c7';
                            $cIcon = !empty($cr['icon']) ? $cr['icon'] : 'fa-tag';
                            $cName = ($currentLang === 'km') ? $cr['name_km'] : $cr['name_en'];
                        ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 34px; height: 34px; border-radius: var(--radius-md); background: <?= htmlspecialchars($cColor) ?>18; color: <?= htmlspecialchars($cColor) ?>; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; border: 1px solid <?= htmlspecialchars($cColor) ?>35;">
                                        <i class="fa-solid <?= htmlspecialchars($cIcon) ?>"></i>
                                    </div>
                                    <div>
                                        <strong style="color: var(--text-main); font-size: 14px;"><?= htmlspecialchars($cName) ?></strong>
                                        <div class="text-xs text-muted"><?= htmlspecialchars($cr['name_en']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-slate" style="text-transform: uppercase; font-size: 11px;">
                                    <?= htmlspecialchars($cr['type']) ?>
                                </span>
                            </td>
                            <td class="table-num">
                                <strong><?= number_format((int)$cr['item_count']) ?></strong> <?= ($cr['item_count'] === 1) ? 'item' : 'items' ?>
                            </td>
                            <td class="table-num">
                                <strong style="color: var(--text-main); font-size: 14.5px;"><?= formatUSD((float)$cr['total_revenue']) ?></strong>
                                <div class="text-xs text-muted"><?= formatKHR((int)round((float)$cr['total_revenue'] * $curRate)) ?></div>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="flex: 1; height: 7px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden; min-width: 80px;">
                                        <div style="height: 100%; width: <?= min(100, $cShare) ?>%; background: <?= htmlspecialchars($cColor) ?>;"></div>
                                    </div>
                                    <span class="text-xs text-muted" style="width: 36px; text-align: right; font-weight: 600;"><?= $cShare ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
