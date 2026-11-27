    <?php if ($reportKey === 'cancelled_orders'): ?>
    <!-- 6. CANCELLED ORDERS & LOST REVENUE REPORT (Owner & Manager Only) -->
    <?php if ($canViewCancelReport): ?>
    <div class="card" id="cancellations_report" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-ban" style="color: #dc2626;"></i>
                    <?= htmlspecialchars(__('cancel_report_title', 'Cancelled Orders & Lost Revenue')) ?>
                </h3>
                <p class="text-xs text-muted" style="margin-top: 2px;">
                    Audit log of all voided orders, lost revenue breakdown by reason, and staff authorizations.
                </p>
            </div>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 6px 14px; border-radius: var(--radius-sm); font-size: 12px; color: #991b1b;">
                    <span>Total Cancellations:</span> <strong style="font-size: 14px; font-weight: 800;"><?= $totalCancelledCount ?></strong>
                </div>
                <div style="background: #fff7ed; border: 1px solid #fed7aa; padding: 6px 14px; border-radius: var(--radius-sm); font-size: 12px; color: #c2410c;">
                    <span>Lost Revenue:</span> <strong style="font-size: 14px; font-weight: 800;"><?= formatUSD($totalLostRevenueUsd) ?></strong>
                    <span class="text-xs" style="color: #ea580c;">(<?= formatKHR($totalLostRevenueKhr) ?>)</span>
                </div>
                <?php if (Auth::can('reports.export')): ?>
                <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=cancellations" class="btn btn-secondary btn-sm" title="Download cancellation spreadsheet (CSV)">
                    <i class="fa-solid fa-file-csv mr-1" style="color: #dc2626;"></i> <?= htmlspecialchars(__('export_cancellations_csv', 'CSV Export')) ?>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <div style="padding: 16px;">
            <!-- Breakdown by Reason Table & Visual Distribution -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 360px), 1fr)); gap: 16px; margin-bottom: 20px;">
                <!-- Table -->
                <div style="border: 1px solid var(--border); border-radius: var(--radius-md); overflow: hidden;">
                    <div style="padding: 10px 14px; background: var(--bg-subtle); font-weight: 700; font-size: 13px; border-bottom: 1px solid var(--border);">
                        <i class="fa-solid fa-chart-pie mr-1" style="color: #dc2626;"></i> Breakdown by Reason
                    </div>
                    <table class="table-clean" style="margin: 0;">
                        <thead>
                            <tr>
                                <th>Reason Category</th>
                                <th class="table-num">Count</th>
                                <th class="table-num">% of Voids</th>
                                <th class="table-num">Lost Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cancelledByReason)): ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--text-muted); padding: 16px;">No cancellations recorded in this period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($cancelledByReason as $rGrp): 
                                    $pct = ($totalCancelledCount > 0) ? round(($rGrp['count'] / $totalCancelledCount) * 100, 1) : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <?php if ($rGrp['key'] === 'other_old'): ?>
                                                <span class="badge badge-slate" style="font-size: 11px;"><?= htmlspecialchars($rGrp['label']) ?></span>
                                            <?php elseif ($rGrp['key'] === 'other'): ?>
                                                <span class="badge badge-amber" style="font-size: 11px;"><?= htmlspecialchars($rGrp['label']) ?></span>
                                            <?php else: ?>
                                                <span class="badge badge-danger" style="font-size: 11px;"><?= htmlspecialchars($rGrp['label']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="table-num" style="font-weight: 700;"><?= $rGrp['count'] ?></td>
                                        <td class="table-num"><?= $pct ?>%</td>
                                        <td class="table-num" style="font-weight: 700; color: #dc2626;">
                                            <?= formatUSD($rGrp['lost_usd']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Distribution Bars -->
                <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px; background: var(--bg-subtle); display: flex; flex-direction: column; justify-content: center; gap: 12px;">
                    <div style="font-weight: 700; font-size: 13px; color: var(--text-main); margin-bottom: 4px;">
                        <i class="fa-solid fa-chart-bar mr-1" style="color: #ea580c;"></i> Reason Distribution (% of Volume)
                    </div>
                    <?php if (empty($cancelledByReason)): ?>
                        <div class="text-xs text-muted" style="text-align: center; padding: 20px;">No cancellation records for distribution.</div>
                    <?php else: ?>
                        <?php foreach ($cancelledByReason as $rGrp): 
                            $pct = ($totalCancelledCount > 0) ? round(($rGrp['count'] / $totalCancelledCount) * 100, 1) : 0;
                            $barColor = ($rGrp['key'] === 'other_old') ? '#64748b' : (($rGrp['key'] === 'other') ? '#ea580c' : '#dc2626');
                        ?>
                            <div>
                                <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px;">
                                    <span style="font-weight: 600;"><?= htmlspecialchars($rGrp['label']) ?></span>
                                    <span><strong><?= $rGrp['count'] ?></strong> (<?= $pct ?>%)</span>
                                </div>
                                <div style="height: 8px; background: var(--border); border-radius: 99px; overflow: hidden;">
                                    <div style="height: 100%; width: <?= max(2, min(100, $pct)) ?>%; background: <?= $barColor ?>;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Detailed Cancelled Orders Table -->
            <div style="overflow-x: auto; border: 1px solid var(--border); border-radius: var(--radius-md);">
                <table class="table-clean js-paginated-table">
                    <thead>
                        <tr>
                            <th style="min-width: 140px;">Order #</th>
                            <th style="min-width: 130px;">Date &amp; Time</th>
                            <th style="min-width: 130px;">Vehicle Plate</th>
                            <th style="min-width: 130px;">Customer</th>
                            <th style="min-width: 120px;" class="table-num">Lost Revenue</th>
                            <th style="min-width: 160px;">Reason Category</th>
                            <th style="min-width: 200px;">Reason Details / Note</th>
                            <th style="min-width: 160px;">Cancelled By</th>
                            <th style="min-width: 140px;">POS Device</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allCancelledOrders)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 32px;">
                                    <i class="fa-solid fa-circle-check" style="color: #16a34a; font-size: 24px; margin-bottom: 8px; display: block;"></i>
                                    No orders were cancelled during this period.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allCancelledOrders as $cord): 
                                $cTime = $cord['cancelled_at'] ?: $cord['created_at'];
                                $canceller = $cord['canceller_name'] ? "{$cord['canceller_name']} (@{$cord['canceller_username']})" : 'Staff';
                                if (!empty($cord['approver_name']) && $cord['approver_name'] !== $cord['canceller_name']) {
                                    $canceller .= "<div class='text-xs text-muted'>Appr: {$cord['approver_name']}</div>";
                                }
                                $deviceText = $cord['device_name'] ? "{$cord['device_name']}" : 'Back Office Web';
                            ?>
                                <tr>
                                    <td>
                                        <strong style="font-family: var(--font-mono); color: var(--text-main);">#<?= htmlspecialchars($cord['order_number']) ?></strong>
                                    </td>
                                    <td class="text-xs text-muted" style="font-family: var(--font-mono);">
                                        <?= date('d M Y, h:i A', strtotime($cTime)) ?>
                                    </td>
                                    <td>
                                        <span style="font-family: var(--font-mono); font-weight: 700; background: var(--bg-subtle); padding: 2px 8px; border-radius: 4px; border: 1px solid var(--border);">
                                            <?= htmlspecialchars($cord['plate_number'] ?: 'N/A') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($cord['customer_name'] ?: 'Guest Customer') ?>
                                    </td>
                                    <td class="table-num">
                                        <strong style="color: #dc2626;"><?= formatUSD((float)($cord['total_usd'] ?? 0)) ?></strong>
                                        <div class="text-xs text-muted"><?= formatKHR((int)($cord['total_khr'] ?? 0)) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($cord['report_cat_key'] === 'other_old'): ?>
                                            <span class="badge badge-slate" style="font-size: 11px;"><?= htmlspecialchars($cord['report_cat_label']) ?></span>
                                        <?php elseif ($cord['report_cat_key'] === 'other'): ?>
                                            <span class="badge badge-amber" style="font-size: 11px;"><?= htmlspecialchars($cord['report_cat_label']) ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-danger" style="font-size: 11px;"><?= htmlspecialchars($cord['report_cat_label']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 12.5px; line-height: 1.4;">
                                        <?= htmlspecialchars($cord['cancel_reason'] ?: '-') ?>
                                    </td>
                                    <td style="font-size: 12.5px;">
                                        <?= $canceller ?>
                                    </td>
                                    <td style="font-size: 12px;" class="text-muted">
                                        <i class="fa-solid fa-tablet-screen-button mr-1"></i> <?= htmlspecialchars($deviceText) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>

