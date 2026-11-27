    <?php if ($reportKey === 'bay_utilization' || $reportKey === 'operations_overview'): ?>
    <!-- 1. BAY UTILIZATION REPORT -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-warehouse" style="color: var(--primary);"></i>
                    Bay Utilization Report
                </h3>
                <p class="text-xs text-muted" style="margin-top: 2px;">
                    Measures busy minutes vs available capacity across shop bays (<?= $bayReport['daily_open_hours'] ?> operating hrs/day).
                </p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Shop Utilization:</span> <strong style="color: var(--primary); font-size: 14px;"><?= $bayReport['overall_utilization_percent'] ?>%</strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Total Busy:</span> <strong><?= $bayReport['total_busy_minutes'] ?> mins</strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Total Capacity:</span> <strong><?= $bayReport['total_available_minutes'] ?> mins</strong>
                </div>
                <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=bay_utilization" class="btn btn-secondary btn-sm" title="Download spreadsheet">
                    <i class="fa-solid fa-file-csv mr-1"></i> CSV
                </a>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 80px;">Bay #</th>
                        <th style="min-width: 150px;">Bay Name</th>
                        <th style="min-width: 120px;">Skill Tag</th>
                        <th style="min-width: 100px;" class="table-num">Cars Handled</th>
                        <th style="min-width: 110px;" class="table-num">Busy Time</th>
                        <th style="min-width: 110px;" class="table-num">Available Time</th>
                        <th style="min-width: 140px;">Utilization %</th>
                        <th style="min-width: 100px;" class="table-num">Idle Time</th>
                        <th style="min-width: 100px;" class="table-num">Idle %</th>
                        <th style="min-width: 120px;" class="table-num">Revenue (USD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bayReport['bays'])): ?>
                        <tr><td colspan="10" style="text-align: center; color: var(--text-muted); padding: 24px;">No bays configured.</td></tr>
                    <?php else: ?>
                        <?php foreach ($bayReport['bays'] as $b): ?>
                            <tr>
                                <td><span class="badge badge-slate" style="font-family: var(--font-mono); font-weight: 700;">Bay <?= $b['bay_number'] ?></span></td>
                                <td>
                                    <strong><?= htmlspecialchars($b['name_en']) ?></strong>
                                    <div class="text-xs text-muted"><?= htmlspecialchars($b['name_km']) ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-info" style="font-size: 10px; text-transform: capitalize;">
                                        <?= htmlspecialchars(str_replace('_', ' ', $b['skill_tag'])) ?>
                                    </span>
                                </td>
                                <td class="table-num" style="font-weight: 700;"><?= $b['orders_count'] ?></td>
                                <td class="table-num"><?= $b['busy_minutes'] ?>m</td>
                                <td class="table-num text-muted"><?= $b['available_minutes'] ?>m</td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="flex: 1; height: 8px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden; min-width: 60px;">
                                            <div style="height: 100%; width: <?= min(100, $b['utilization_percent']) ?>%; background: <?= $b['utilization_percent'] > 75 ? '#10b981' : ($b['utilization_percent'] < 30 ? '#f59e0b' : 'var(--primary)') ?>;"></div>
                                        </div>
                                        <span style="font-size: 12px; font-weight: 700; font-family: var(--font-mono); width: 44px; text-align: right;">
                                            <?= $b['utilization_percent'] ?>%
                                        </span>
                                    </div>
                                </td>
                                <td class="table-num text-muted"><?= $b['idle_minutes'] ?>m</td>
                                <td class="table-num" style="color: #94a3b8;"><?= $b['idle_percent'] ?>%</td>
                                <td class="table-num" style="font-weight: 700; color: #16a34a;">
                                    <?= formatUSD((float)$b['total_usd']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
