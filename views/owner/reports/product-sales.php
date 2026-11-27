    <?php if ($reportKey === 'product_sales' || $reportKey === 'operations_overview'): ?>
    <!-- 9. Table: Spare Parts & Car Care Products Sales Breakdown -->
    <div class="report-table-card">
        <div class="report-table-header">
            <div>
                <h2 class="report-table-title">
                    <i class="fa-solid fa-box-open" style="color: #9333ea;"></i>
                    Spare Parts &amp; Car Care Product Sales
                </h2>
                <span class="text-xs text-muted">Stock turnover, acquisition cost (COGS), gross revenue, and gross profit</span>
            </div>
            <div style="display: flex; gap: 16px; font-size: 13px; flex-wrap: wrap;">
                <span>Revenue: <strong style="color: #9333ea;"><?= formatUSD($productRevenue) ?></strong></span>
                <?php if ($canViewProfit): ?>
                <span>COGS: <strong><?= formatUSD($productCogs) ?></strong></span>
                <span>Gross Profit: <strong style="color: #16a34a;"><?= formatUSD($productProfit) ?></strong></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-responsive-clean">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 220px;">Product / Part</th>
                        <th style="min-width: 160px;">Category</th>
                        <th style="min-width: 120px;">Brand</th>
                        <th class="table-num" style="min-width: 100px;">Units Sold</th>
                        <th class="table-num" style="min-width: 140px;">Sales Revenue</th>
                        <?php if ($canViewProfit): ?>
                        <th class="table-num" style="min-width: 120px;">COGS Cost</th>
                        <th class="table-num" style="min-width: 140px;">Gross Profit</th>
                        <th style="min-width: 130px;">Margin</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($topProducts)): ?>
                        <tr>
                            <td colspan="<?= $canViewProfit ? 8 : 5 ?>">
                                <div class="empty-state-box">
                                    <div class="empty-state-icon"><i class="fa-solid fa-oil-can"></i></div>
                                    <div class="empty-state-text">No engine oil or car care product sales recorded in this period.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($topProducts as $tp): 
                            $sales = (float)$tp['total_sales'];
                            $cost = (float)$tp['total_cost'];
                            $profit = (float)$tp['gross_profit'];
                            $marginP = ($sales > 0) ? round(($profit / $sales) * 100) : 0;
                            $catLabel = ($currentLang === 'km') ? $tp['category_name_km'] : $tp['category_name_en'];
                            $catColor = !empty($tp['category_color']) ? $tp['category_color'] : '#64748b';
                            $catIcon = !empty($tp['category_icon']) ? $tp['category_icon'] : 'fa-box-open';
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($tp['name']) ?></strong></td>
                            <td>
                                <span class="badge" style="background: <?= htmlspecialchars($catColor) ?>18; color: <?= htmlspecialchars($catColor) ?>; border: 1px solid <?= htmlspecialchars($catColor) ?>35; font-size: 11px;">
                                    <i class="fa-solid <?= htmlspecialchars($catIcon) ?> mr-1"></i>
                                    <?= htmlspecialchars($catLabel) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($tp['brand']) ?></td>
                            <td class="table-num"><strong><?= (int)$tp['total_qty'] ?></strong></td>
                            <td class="table-num"><strong><?= formatUSD($sales) ?></strong></td>
                            <?php if ($canViewProfit): ?>
                            <td class="table-num text-muted"><?= formatUSD($cost) ?></td>
                            <td class="table-num" style="color: #16a34a; font-weight: 700;"><?= formatUSD($profit) ?></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="flex: 1; height: 6px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden; min-width: 40px;">
                                        <div style="height: 100%; width: <?= max(0, min(100, $marginP)) ?>%; background: #0284c7;"></div>
                                    </div>
                                    <span class="text-xs text-muted" style="width: 32px; text-align: right;"><?= $marginP ?>%</span>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
