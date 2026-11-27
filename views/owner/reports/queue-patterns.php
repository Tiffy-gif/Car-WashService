    <?php if ($reportKey === 'queue_patterns' || $reportKey === 'operations_overview'): ?>
    <!-- 3. BOTTLENECK & IDLE TIME PATTERN REPORT -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-chart-simple" style="color: #f59e0b;"></i>
                    Bottleneck &amp; Idle Time Pattern Report
                </h3>
                <p class="text-xs text-muted" style="margin-top: 2px;">
                    Analyzes vehicle volume and wait times by hour of the day and day of the week to reveal bottlenecks and idle bays.
                </p>
            </div>
            <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=bottlenecks" class="btn btn-secondary btn-sm" title="Download spreadsheet">
                <i class="fa-solid fa-file-csv mr-1"></i> CSV
            </a>
        </div>

        <!-- Pattern Insights Alert Cards -->
        <?php if (!empty($patternReport['insights'])): ?>
            <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px;">
                <?php foreach ($patternReport['insights'] as $ins): ?>
                    <div style="display: flex; align-items: flex-start; gap: 12px; padding: 12px 16px; border-radius: var(--radius-md); background: var(--bg-subtle); border-left: 4px solid <?= $ins['color'] ?>;">
                        <i class="fa-solid <?= $ins['icon'] ?>" style="color: <?= $ins['color'] ?>; font-size: 16px; margin-top: 2px;"></i>
                        <div>
                            <strong style="color: var(--text-main); font-size: 13.5px;"><?= htmlspecialchars($ins['title']) ?></strong>
                            <div class="text-xs text-muted" style="margin-top: 2px; line-height: 1.5;"><?= htmlspecialchars($ins['text']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Hourly & Day Matrix Table -->
        <div style="overflow-x: auto;">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 140px;">Day of Week</th>
                        <th style="min-width: 140px;">Time Window</th>
                        <th style="min-width: 100px;" class="table-num">Vehicle Volume</th>
                        <th style="min-width: 120px;" class="table-num">Avg Customer Wait</th>
                        <th style="min-width: 120px;" class="table-num">Avg Service Duration</th>
                        <th style="min-width: 120px;">Queue Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($patternReport['matrix'])): ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">No hourly pattern data found for this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($patternReport['matrix'] as $pm): 
                            $wait = (float)$pm['avg_wait_mins'];
                            $statusBadge = $wait > 20 ? 'badge-danger' : ($wait > 10 ? 'badge-warning' : 'badge-success');
                            $statusLabel = $wait > 20 ? 'Bottleneck' : ($wait > 10 ? 'Moderate' : 'Smooth Flow');
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($pm['day_name']) ?></strong></td>
                                <td style="font-family: var(--font-mono); font-size: 12.5px;">
                                    <?= sprintf('%02d:00 - %02d:00', $pm['hour_of_day'], $pm['hour_of_day'] + 1) ?>
                                </td>
                                <td class="table-num" style="font-weight: 700;"><?= $pm['volume'] ?> cars</td>
                                <td class="table-num" style="font-weight: 700; color: <?= $wait > 15 ? 'var(--danger)' : 'var(--text-main)' ?>;">
                                    <?= $wait ?>m
                                </td>
                                <td class="table-num text-muted"><?= $pm['avg_service_mins'] ?>m</td>
                                <td>
                                    <span class="badge <?= $statusBadge ?>" style="font-size: 10px;">
                                        <?= $statusLabel ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
