    <?php if ($reportKey === 'technician_performance' || $reportKey === 'operations_overview'): ?>
    <!-- 2. TECHNICIAN & WASHER EFFICIENCY REPORT -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-bolt" style="color: #9333ea;"></i>
                    Technician &amp; Washer Efficiency Report
                </h3>
                <p class="text-xs text-muted" style="margin-top: 2px;">
                    Completed assigned tasks from <?= htmlspecialchars($perfStartDate) ?> through <?= htmlspecialchars($perfEndDate) ?>. Standard time ÷ actual time; higher % means faster completion.
                </p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Overall Efficiency:</span> <strong style="color: #9333ea; font-size: 14px;"><?= $techReport['overall_efficiency_percent'] ?>%</strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Standard Time:</span> <strong><?= $techReport['total_standard_hours'] ?>h</strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Actual Time:</span> <strong><?= $techReport['total_actual_hours'] ?>h</strong>
                </div>
                <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=tech_efficiency" class="btn btn-secondary btn-sm" title="Download spreadsheet">
                    <i class="fa-solid fa-file-csv mr-1"></i> CSV
                </a>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="table-clean js-paginated-table">
                <thead>
                    <tr>
                        <th style="min-width: 160px;">Staff Member</th>
                        <th style="min-width: 120px;">Role</th>
                        <th style="min-width: 100px;" class="table-num">Completed Tasks</th>
                        <th style="min-width: 120px;" class="table-num">Standard Time</th>
                        <th style="min-width: 120px;" class="table-num">Actual Time</th>
                        <th style="min-width: 140px;">Efficiency %</th>
                        <th style="min-width: 110px;" class="table-num">Speed Factor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($techReport['staff'])): ?>
                        <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px;">No completed assigned staff tasks found for this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($techReport['staff'] as $st): 
                            $eff = $st['efficiency_percent'];
                            $badgeColor = $eff >= 105 ? 'badge-success' : ($eff < 90 ? 'badge-warning' : 'badge-info');
                        ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($st['staff_name']) ?></strong>
                                    <div class="text-xs text-muted">@<?= htmlspecialchars($st['username'] ?? '') ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-slate" style="font-size: 10px; text-transform: capitalize;"><?= htmlspecialchars($st['staff_role']) ?></span>
                                </td>
                                <td class="table-num" style="font-weight: 700;"><?= $st['completed_jobs'] ?></td>
                                <td class="table-num text-muted"><?= $st['standard_minutes'] ?>m</td>
                                <td class="table-num" style="font-weight: 600;"><?= $st['actual_minutes'] ?>m</td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="flex: 1; height: 8px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden; min-width: 60px;">
                                            <div style="height: 100%; width: <?= min(100, $eff) ?>%; background: #9333ea;"></div>
                                        </div>
                                        <span class="badge <?= $badgeColor ?>" style="font-family: var(--font-mono); font-size: 11px;">
                                            <?= $eff ?>%
                                        </span>
                                    </div>
                                </td>
                                <td class="table-num" style="font-family: var(--font-mono); font-weight: 700;">
                                    <?= $st['factor'] ?>x
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
