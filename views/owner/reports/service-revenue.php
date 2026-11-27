    <?php if ($reportKey === 'service_revenue' || $reportKey === 'operations_overview'): ?>
    <!-- 7. Tables Dual Grid: Service Packages & Vehicle Sizes (Side-by-Side on Desktop, Stack on Tablet/Mobile) -->
    <div class="tables-dual-grid">
        <!-- 7a. Wash Service Packages -->
        <div class="report-table-card">
            <div class="report-table-header">
                <div>
                    <h2 class="report-table-title" style="font-size: 15px;">
                        <i class="fa-solid fa-shower" style="color: var(--primary);"></i> Revenue by Wash Service
                    </h2>
                </div>
            </div>
            <div class="table-responsive-clean">
                <table class="table-clean">
                    <thead>
                        <tr>
                            <th>Service Package</th>
                            <th class="table-num">Jobs</th>
                            <th class="table-num">Revenue</th>
                            <th style="min-width: 120px;">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($servicesBreakdown)): ?>
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state-box">
                                        <div class="empty-state-icon"><i class="fa-solid fa-shower"></i></div>
                                        <div class="empty-state-text">No wash services recorded.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($servicesBreakdown as $sb): 
                                $share = ($grossRev > 0) ? round(((float)$sb['service_revenue'] / $grossRev) * 100) : 0;
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($sb['service_name']) ?></strong></td>
                                <td class="table-num"><?= $sb['qty'] ?></td>
                                <td class="table-num">
                                    <strong><?= formatUSD((float)$sb['service_revenue']) ?></strong>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="flex: 1; height: 6px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden;">
                                            <div style="height: 100%; width: <?= min(100, $share) ?>%; background: var(--primary);"></div>
                                        </div>
                                        <span class="text-xs text-muted" style="width: 32px; text-align: right;"><?= $share ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 7b. Income by Vehicle Size -->
        <div class="report-table-card">
            <div class="report-table-header">
                <div>
                    <h2 class="report-table-title" style="font-size: 15px;">
                        <i class="fa-solid fa-car-side" style="color: var(--primary);"></i> Revenue by Vehicle Size
                    </h2>
                </div>
            </div>
            <div class="table-responsive-clean">
                <table class="table-clean">
                    <thead>
                        <tr>
                            <th>Vehicle Size</th>
                            <th class="table-num">Vehicles</th>
                            <th class="table-num">Revenue</th>
                            <th style="min-width: 120px;">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vehicleBreakdown)): ?>
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state-box">
                                        <div class="empty-state-icon"><i class="fa-solid fa-car"></i></div>
                                        <div class="empty-state-text">No vehicle records available.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($vehicleBreakdown as $vb): 
                                $share = ($grossRev > 0) ? round(((float)$vb['v_revenue'] / $grossRev) * 100) : 0;
                            ?>
                            <tr>
                                <td><?= vehicleTypeBadge($vb['vehicle_type']) ?></td>
                                <td class="table-num"><?= $vb['count'] ?></td>
                                <td class="table-num">
                                    <strong><?= formatUSD((float)$vb['v_revenue']) ?></strong>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="flex: 1; height: 6px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden;">
                                            <div style="height: 100%; width: <?= min(100, $share) ?>%; background: #16a34a;"></div>
                                        </div>
                                        <span class="text-xs text-muted" style="width: 32px; text-align: right;"><?= $share ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 8. Table: Basic Maintenance & Minor Repair Services -->
    <div class="report-table-card">
        <div class="report-table-header">
            <div>
                <h2 class="report-table-title">
                    <i class="fa-solid fa-screwdriver-wrench" style="color: #ea580c;"></i>
                    Basic Maintenance &amp; Minor Repair Labor
                </h2>
                <span class="text-xs text-muted">Mechanical inspection, tire changes, oil servicing labor, and repairs</span>
            </div>
            <div class="text-xs">
                Total Repair Labor: <strong style="color: #ea580c; font-size: 14px;"><?= formatUSD($repairRevenue) ?></strong>
            </div>
        </div>
        <div class="table-responsive-clean">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 260px;">Repair Service / Labor</th>
                        <th class="table-num" style="min-width: 140px;">Jobs Performed</th>
                        <th class="table-num" style="min-width: 160px;">Labor Revenue</th>
                        <th style="min-width: 180px;">Share of Repairs</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($repairBreakdown)): ?>
                        <tr>
                            <td colspan="4">
                                <div class="empty-state-box">
                                    <div class="empty-state-icon"><i class="fa-solid fa-wrench"></i></div>
                                    <div class="empty-state-text">No minor repair jobs completed in this period.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($repairBreakdown as $rb): 
                            $rShare = ($repairRevenue > 0) ? round(((float)$rb['service_revenue'] / $repairRevenue) * 100) : 0;
                        ?>
                        <tr>
                            <td>
                                <strong><i class="fa-solid fa-wrench mr-1" style="color: #ea580c;"></i> <?= htmlspecialchars($rb['service_name']) ?></strong>
                            </td>
                            <td class="table-num">
                                <strong><?= $rb['qty'] ?></strong> <?= ($rb['qty'] === 1) ? 'job' : 'jobs' ?>
                            </td>
                            <td class="table-num">
                                <strong style="color: var(--text-main);"><?= formatUSD((float)$rb['service_revenue']) ?></strong>
                                <div class="text-xs text-muted"><?= formatKHR((int)round((float)$rb['service_revenue'] * $curRate)) ?></div>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="flex: 1; height: 6px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden; min-width: 60px;">
                                        <div style="height: 100%; width: <?= min(100, $rShare) ?>%; background: #ea580c;"></div>
                                    </div>
                                    <span class="text-xs text-muted" style="width: 34px; text-align: right;"><?= $rShare ?>%</span>
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
