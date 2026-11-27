    <?php if ($reportKey === 'waiting_washing' || $reportKey === 'operations_overview'): ?>
    <!-- Vehicle Waiting & Washing Time Report -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-stopwatch" style="color: var(--primary);"></i>
                    Vehicle Waiting &amp; Washing Durations
                </h3>
                <p class="text-xs text-muted" style="margin-top: 2px;">
                    Tracks arrival-to-start waiting time, wash/service duration in bay, and total turnaround time per vehicle.
                </p>
            </div>
            <div style="display: flex; gap: 10px;">
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Avg Wait:</span> <strong><?= $avgWaitMins ?> mins</strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Avg Service:</span> <strong><?= $avgWashMins ?> mins</strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Avg Total:</span> <strong style="color: var(--primary);"><?= $avgTotalMins ?> mins</strong>
                </div>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="table-clean js-paginated-table">
                <thead>
                    <tr>
                        <th style="min-width: 140px;">Vehicle Plate</th>
                        <th style="min-width: 120px;">Customer</th>
                        <th style="min-width: 160px;">Service</th>
                        <th style="min-width: 120px;">Bay Used</th>
                        <th style="min-width: 110px;">Arrived</th>
                        <th style="min-width: 110px;">Started</th>
                        <th style="min-width: 110px;">Finished</th>
                        <th style="min-width: 110px;" class="table-num">Waiting Time</th>
                        <th style="min-width: 110px;" class="table-num">Washing Time</th>
                        <th style="min-width: 110px;" class="table-num">Total Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($serviceTimes)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                No vehicle service duration records found for this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($serviceTimes as $st): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <span style="font-family: var(--font-mono); font-weight: 800; background: var(--bg-subtle); padding: 2px 8px; border-radius: 4px; border: 1px solid var(--border);">
                                            <?= htmlspecialchars($st['plate_number']) ?>
                                        </span>
                                        <?= vehicleTypeBadge($st['vehicle_type']) ?>
                                    </div>
                                    <div class="text-xs text-muted" style="margin-top: 2px;">#<?= htmlspecialchars($st['order_number']) ?></div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($st['customer_name'] ?? 'Guest') ?></strong>
                                </td>
                                <td style="font-size: 13px;">
                                    <?= htmlspecialchars($st['service_names'] ?: 'Car Wash') ?>
                                </td>
                                <td>
                                    <?php if (!empty($st['bay_name'])): ?>
                                        <span class="badge badge-primary"><i class="fa-solid fa-warehouse mr-1"></i> <?= htmlspecialchars($st['bay_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-xs text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-xs text-muted" style="font-family: var(--font-mono);">
                                    <?= !empty($st['arrived_at']) ? date('h:i A', strtotime($st['arrived_at'])) : date('h:i A', strtotime($st['created_at'])) ?>
                                </td>
                                <td class="text-xs text-muted" style="font-family: var(--font-mono);">
                                    <?= !empty($st['started_at']) ? date('h:i A', strtotime($st['started_at'])) : '-' ?>
                                </td>
                                <td class="text-xs text-muted" style="font-family: var(--font-mono);">
                                    <?= !empty($st['finished_at']) ? date('h:i A', strtotime($st['finished_at'])) : '-' ?>
                                </td>
                                <td class="table-num">
                                    <?php if ($st['wait_minutes'] !== null): ?>
                                        <strong style="color: var(--warning);"><?= $st['wait_minutes'] ?>m</strong>
                                    <?php else: ?>
                                        <span class="text-xs text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="table-num">
                                    <?php if ($st['wash_minutes'] !== null): ?>
                                        <strong style="color: var(--info);"><?= $st['wash_minutes'] ?>m</strong>
                                    <?php else: ?>
                                        <span class="text-xs text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="table-num">
                                    <?php if ($st['total_minutes'] !== null): ?>
                                        <strong style="color: var(--success);"><?= $st['total_minutes'] ?>m</strong>
                                    <?php else: ?>
                                        <span class="text-xs text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
