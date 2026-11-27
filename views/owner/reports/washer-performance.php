    <?php if ($reportKey === 'washer_performance' || $reportKey === 'operations_overview'): ?>
    <!-- 10. Table: Washer Production & Allocated Wash Revenue -->
    <div class="report-table-card">
        <div class="report-table-header">
            <div>
                <h2 class="report-table-title">
                    <i class="fa-solid fa-users-gear" style="color: var(--primary);"></i>
                    Washer Production
                </h2>
                <span class="text-xs text-muted">Completed wash jobs and each washer’s share of wash-service revenue</span>
            </div>
            <div class="text-xs text-muted">
                <?= count($washerBreakdown) ?> Washers on Record
            </div>
        </div>
        <div class="table-responsive-clean">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 220px;">Washer Name</th>
                        <th class="table-num" style="min-width: 140px;">Jobs Cleaned</th>
                        <th class="table-num" style="min-width: 160px;">Allocated Wash Revenue</th>
                        <th style="min-width: 160px;">Efficiency Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($washerBreakdown)): ?>
                        <tr>
                            <td colspan="4">
                                <div class="empty-state-box">
                                    <div class="empty-state-icon"><i class="fa-solid fa-user-group"></i></div>
                                    <div class="empty-state-text">No washer production records found for this period.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($washerBreakdown as $wb): 
                            $revH = (float)$wb['allocated_revenue'];
                            $eff = ($washRevenue > 0) ? round(($revH / $washRevenue) * 100) : 0;
                        ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div class="stat-icon purple" style="width: 32px; height: 32px; font-size: 14px; border-radius: 50%;">
                                        <i class="fa-solid fa-user"></i>
                                    </div>
                                    <strong><?= htmlspecialchars($wb['full_name']) ?></strong>
                                </div>
                            </td>
                            <td class="table-num">
                                <strong><?= $wb['jobs_count'] ?></strong> <?= ($wb['jobs_count'] === 1) ? 'vehicle' : 'vehicles' ?>
                            </td>
                            <td class="table-num">
                                <strong><?= formatUSD($revH) ?></strong>
                                <div class="text-xs text-muted"><?= formatKHR((int)round($revH * $curRate)) ?></div>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="flex: 1; height: 6px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden; min-width: 60px;">
                                        <div style="height: 100%; width: <?= min(100, $eff) ?>%; background: #0284c7;"></div>
                                    </div>
                                    <span class="text-xs text-muted" style="width: 34px; text-align: right;"><?= $eff ?>%</span>
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
