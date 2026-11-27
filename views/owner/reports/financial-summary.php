<?php if ($reportKey === 'financial_summary'): ?>
    <?php if ($canViewProfit && $hasMissingCogs): ?>
        <div style="background: rgba(245, 158, 11, 0.12); border: 1px solid #f59e0b; border-radius: var(--radius-lg); padding: 14px 18px; margin-bottom: 20px; display: flex; align-items: center; gap: 14px; color: #b45309;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size: 22px; flex-shrink: 0; color: #d97706;"></i>
            <div style="flex: 1;">
                <strong style="font-size: 14px; display: block; margin-bottom: 2px;">Cost of Goods Sold (COGS) is incomplete</strong>
                <span style="font-size: 13px;">
                    <?= (int)$missingCogsCount ?> sold product item(s), representing <?= formatUSD((float)$missingCogsSales) ?> in sales, have missing or zero cost data. Net operating profit may be overstated.
                </span>
            </div>
            <a href="<?= APP_URL ?>/index.php?view=product_catalog" class="btn btn-sm btn-secondary" style="border-color: #d97706; color: #b45309; white-space: nowrap;">
                <i class="fa-solid fa-pen-to-square mr-1"></i> Update Product Costs
            </a>
        </div>
    <?php endif; ?>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-card-top">
                <div>
                    <div class="kpi-card-title">Net Settled Revenue</div>
                    <div class="kpi-card-value" style="color: #16a34a;"><?= formatUSD((float)$netSettledRevenue) ?></div>
                    <div class="kpi-card-khr">(<?= formatKHR((int)$netSettledRevenueKhr) ?>)</div>
                </div>
                <div class="kpi-icon-box" style="background: rgba(22, 163, 74, 0.12); color: #16a34a;"><i class="fa-solid fa-sack-dollar"></i></div>
            </div>
            <div class="kpi-card-footer">
                <span>Gross sales: <strong><?= formatUSD((float)$grossRev) ?></strong></span>
                <?php if ((float)$settledRefunds > 0): ?>
                    <span style="color: #dc2626;">Refunds: <strong>-<?= formatUSD((float)$settledRefunds) ?></strong></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canViewProfit): ?>
            <div class="kpi-card">
                <div class="kpi-card-top">
                    <div>
                        <div class="kpi-card-title">Cost of Goods Sold</div>
                        <div class="kpi-card-value" style="color: #0284c7;"><?= formatUSD((float)$productCogs) ?></div>
                        <div class="kpi-card-khr">(<?= formatKHR((int)round((float)$productCogs * $curRate)) ?>)</div>
                    </div>
                    <div class="kpi-icon-box" style="background: rgba(2, 132, 199, 0.12); color: #0284c7;"><i class="fa-solid fa-boxes-stacked"></i></div>
                </div>
                <div class="kpi-card-footer"><span>Direct cost of products sold</span></div>
            </div>

            <div class="kpi-card">
                <div class="kpi-card-top">
                    <div>
                        <div class="kpi-card-title">Operating Expenses</div>
                        <div class="kpi-card-value" style="color: #d97706;"><?= formatUSD((float)$totalExp) ?></div>
                        <div class="kpi-card-khr">(<?= formatKHR((int)round((float)$totalExp * $curRate)) ?>)</div>
                    </div>
                    <div class="kpi-icon-box" style="background: rgba(217, 119, 6, 0.12); color: #d97706;"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                </div>
                <div class="kpi-card-footer"><span>Recorded expenses for this period</span></div>
            </div>

            <div class="kpi-card <?= ((float)$netOperatingProfit >= 0) ? 'profit-positive' : 'profit-negative' ?>">
                <div class="kpi-card-top">
                    <div>
                        <div class="kpi-card-title">Net Operating Profit</div>
                        <div class="kpi-card-value" style="color: <?= ((float)$netOperatingProfit >= 0) ? '#16a34a' : '#dc2626' ?>;"><?= formatUSD((float)$netOperatingProfit) ?></div>
                        <div class="kpi-card-khr">(<?= formatKHR((int)$netOperatingProfitKhr) ?>)</div>
                    </div>
                    <div class="kpi-icon-box" style="background: <?= ((float)$netOperatingProfit >= 0) ? 'rgba(22, 163, 74, 0.12)' : 'rgba(220, 38, 38, 0.12)' ?>; color: <?= ((float)$netOperatingProfit >= 0) ? '#16a34a' : '#dc2626' ?>;"><i class="fa-solid <?= ((float)$netOperatingProfit >= 0) ? 'fa-chart-line' : 'fa-triangle-exclamation' ?>"></i></div>
                </div>
                <div class="kpi-card-footer"><span>Net revenue minus COGS and expenses</span></div>
            </div>
        <?php else: ?>
            <div class="kpi-card">
                <div class="kpi-card-top">
                    <div>
                        <div class="kpi-card-title">Orders</div>
                        <div class="kpi-card-value"><?= number_format((int)$totalVehicles) ?></div>
                    </div>
                    <div class="kpi-icon-box" style="background: rgba(2, 132, 199, 0.12); color: #0284c7;"><i class="fa-solid fa-receipt"></i></div>
                </div>
                <div class="kpi-card-footer"><span>Orders with recorded sales</span></div>
            </div>

            <div class="kpi-card">
                <div class="kpi-card-top">
                    <div>
                        <div class="kpi-card-title">Average Order</div>
                        <div class="kpi-card-value"><?= formatUSD((float)$avgTicket) ?></div>
                    </div>
                    <div class="kpi-icon-box" style="background: rgba(124, 58, 237, 0.12); color: #7c3aed;"><i class="fa-solid fa-calculator"></i></div>
                </div>
                <div class="kpi-card-footer"><span>Gross sales divided by order count</span></div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($canViewProfit): ?>
        <?php
            $maxBalanceBar = max(0.01, (float)$netSettledRevenue, (float)$productCogs, (float)$totalExp);
            $revenueBarWidth = min(100, round(((float)$netSettledRevenue / $maxBalanceBar) * 100));
            $cogsBarWidth = min(100, round(((float)$productCogs / $maxBalanceBar) * 100));
            $expenseBarWidth = min(100, round(((float)$totalExp / $maxBalanceBar) * 100));
        ?>
        <div class="chart-card" style="margin-top: 20px;">
            <div class="chart-header">
                <div>
                    <h2 class="chart-title"><i class="fa-solid fa-scale-balanced" style="color: var(--primary);"></i> Revenue and Costs</h2>
                    <div class="chart-subtitle">A high-level comparison for the selected period</div>
                </div>
                <span class="badge badge-slate" style="font-size: 11px;">USD</span>
            </div>
            <div class="chart-bars-wrap">
                <div class="chart-bar-item">
                    <div class="chart-bar-meta"><span class="chart-bar-label">Net Settled Revenue</span><strong><?= formatUSD((float)$netSettledRevenue) ?></strong></div>
                    <div class="chart-bar-track"><div class="chart-bar-fill" style="width: <?= $revenueBarWidth ?>%; background: #16a34a;"></div></div>
                </div>
                <div class="chart-bar-item">
                    <div class="chart-bar-meta"><span class="chart-bar-label">Cost of Goods Sold</span><strong><?= formatUSD((float)$productCogs) ?></strong></div>
                    <div class="chart-bar-track"><div class="chart-bar-fill" style="width: <?= $cogsBarWidth ?>%; background: #0284c7;"></div></div>
                </div>
                <div class="chart-bar-item">
                    <div class="chart-bar-meta"><span class="chart-bar-label">Operating Expenses</span><strong><?= formatUSD((float)$totalExp) ?></strong></div>
                    <div class="chart-bar-track"><div class="chart-bar-fill" style="width: <?= $expenseBarWidth ?>%; background: #d97706;"></div></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px;">
        <a class="btn btn-secondary" href="<?= APP_URL ?>/index.php?view=reports&amp;report=service_revenue"><i class="fa-solid fa-soap mr-1"></i> View Service Revenue</a>
        <a class="btn btn-secondary" href="<?= APP_URL ?>/index.php?view=reports&amp;report=product_sales"><i class="fa-solid fa-box-open mr-1"></i> View Product Sales</a>
    </div>
<?php endif; ?>
