    <?php if ($reportKey === 'staff_timecards' || $reportKey === 'operations_overview'): ?>
    <!-- 4. STAFF TIMECARDS & LABOR HOURS LOG -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-clock" style="color: #10b981;"></i>
                    Staff Timecards &amp; Labor Hours Log
                </h3>
                <p class="text-xs text-muted" style="margin-top: 2px;">
                    Records staff clock-in and clock-out hours. Managers can correct mistakes with a mandatory audit reason.
                </p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Total Labor Hours:</span> <strong style="color: #10b981; font-size: 14px;"><?= number_format($cphReport['labor_hours'], 2) ?>h</strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Cars / Labor Hr:</span> <strong style="font-size: 14px;"><?= number_format($cphReport['cars_per_labor_hour'], 2) ?></strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Target:</span> <strong><?= number_format($cphReport['target'], 1) ?>/hr</strong>
                </div>
                <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=timecards" class="btn btn-secondary btn-sm" title="Download spreadsheet">
                    <i class="fa-solid fa-file-csv mr-1"></i> CSV
                </a>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 120px;">Timecard UID</th>
                        <th style="min-width: 140px;">Staff Member</th>
                        <th style="min-width: 110px;">Role</th>
                        <th style="min-width: 140px;">Clock In</th>
                        <th style="min-width: 140px;">Clock Out</th>
                        <th style="min-width: 100px;" class="table-num">Hours Worked</th>
                        <th style="min-width: 100px;">Status</th>
                        <th style="min-width: 140px;">Notes / Correction</th>
                        <th style="min-width: 90px; text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($timecardList)): ?>
                        <tr><td colspan="9" style="text-align: center; color: var(--text-muted); padding: 24px;">No staff timecards recorded for this date range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($timecardList as $tc): 
                            $statusBadge = ($tc['status'] === 'active') ? 'badge-primary' : (($tc['status'] === 'corrected') ? 'badge-warning' : 'badge-success');
                        ?>
                            <tr>
                                <td>
                                    <span style="font-family: var(--font-mono); font-weight: 700; font-size: 12px;"><?= htmlspecialchars($tc['timecard_uid']) ?></span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($tc['staff_name']) ?></strong>
                                </td>
                                <td>
                                    <span class="badge badge-slate" style="font-size: 10px; text-transform: capitalize;"><?= htmlspecialchars($tc['staff_role']) ?></span>
                                </td>
                                <td style="font-family: var(--font-mono); font-size: 12px;" class="text-muted">
                                    <?= date('d M, h:i A', strtotime($tc['clock_in'])) ?>
                                </td>
                                <td style="font-family: var(--font-mono); font-size: 12px;" class="text-muted">
                                    <?= $tc['clock_out'] ? date('d M, h:i A', strtotime($tc['clock_out'])) : '<span class="badge badge-info" style="font-size: 10px;">Active</span>' ?>
                                </td>
                                <td class="table-num" style="font-family: var(--font-mono); font-weight: 800; font-size: 14px; color: #10b981;">
                                    <?= $tc['total_hours'] !== null ? number_format((float)$tc['total_hours'], 2) . 'h' : '-' ?>
                                </td>
                                <td>
                                    <span class="badge <?= $statusBadge ?>" style="font-size: 10px; text-transform: capitalize;">
                                        <?= htmlspecialchars($tc['status']) ?>
                                    </span>
                                </td>
                                <td style="font-size: 12px;">
                                    <?php if (!empty($tc['latest_correction_reason'])): ?>
                                        <div style="color: #b45309; font-weight: 600;">
                                            <i class="fa-solid fa-pen-to-square mr-1"></i> Corrected: <?= htmlspecialchars($tc['latest_correction_reason']) ?>
                                        </div>
                                        <div class="text-xs text-muted">By <?= htmlspecialchars($tc['latest_corrected_by'] ?? 'Manager') ?></div>
                                    <?php elseif (!empty($tc['notes'])): ?>
                                        <span class="text-muted"><?= htmlspecialchars($tc['notes']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <button type="button" class="btn btn-secondary btn-sm" style="font-size: 11px; padding: 3px 8px;" onclick="openTimecardCorrectionModal(<?= (int)$tc['id'] ?>, '<?= htmlspecialchars($tc['timecard_uid'], ENT_QUOTES) ?>', '<?= htmlspecialchars($tc['staff_name'], ENT_QUOTES) ?>', '<?= date('Y-m-d\TH:i', strtotime($tc['clock_in'])) ?>', '<?= $tc['clock_out'] ? date('Y-m-d\TH:i', strtotime($tc['clock_out'])) : date('Y-m-d\TH:i') ?>')" title="Correct Timecard (Manager)">
                                        <i class="fa-solid fa-pen-to-square mr-1"></i> Correct
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
