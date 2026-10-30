<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLogger.php';

class TimecardManager {

    /**
     * Clock in staff member.
     */
    public static function clockIn(PDO $pdo, int $userId, ?string $notes = null): array {
        // Check for existing active timecard
        $checkStmt = $pdo->prepare("SELECT id, clock_in FROM staff_timecards WHERE user_id = ? AND status = 'active' AND clock_out IS NULL LIMIT 1");
        $checkStmt->execute([$userId]);
        $active = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($active) {
            return [
                'success' => false,
                'error' => 'already_clocked_in',
                'message' => 'Staff member is already clocked in.',
                'timecard_id' => (int)$active['id'],
                'clock_in' => $active['clock_in']
            ];
        }

        $uid = 'TC-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $now = date('Y-m-d H:i:s');

        $insStmt = $pdo->prepare("INSERT INTO staff_timecards (timecard_uid, user_id, clock_in, status, notes) VALUES (?, ?, ?, 'active', ?)");
        $insStmt->execute([$uid, $userId, $now, $notes]);
        $timecardId = (int)$pdo->lastInsertId();

        // Audit log
        AuditLogger::log('TIMECARD_CLOCK_IN', "Staff user #{$userId} clocked in at {$now} (UID: {$uid})", 'staff_timecards', (string)$timecardId);

        return [
            'success' => true,
            'timecard_id' => $timecardId,
            'timecard_uid' => $uid,
            'clock_in' => $now
        ];
    }

    /**
     * Clock out staff member.
     */
    public static function clockOut(PDO $pdo, int $userId, ?string $notes = null): array {
        $checkStmt = $pdo->prepare("SELECT id, timecard_uid, clock_in, notes FROM staff_timecards WHERE user_id = ? AND status = 'active' AND clock_out IS NULL ORDER BY id DESC LIMIT 1");
        $checkStmt->execute([$userId]);
        $active = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$active) {
            return [
                'success' => false,
                'error' => 'not_clocked_in',
                'message' => 'Staff member is not currently clocked in.'
            ];
        }

        $now = date('Y-m-d H:i:s');
        $clockInSec = strtotime($active['clock_in']);
        $clockOutSec = strtotime($now);
        $totalHours = round(max(0, ($clockOutSec - $clockInSec)) / 3600.0, 2);

        $mergedNotes = $active['notes'];
        if ($notes !== null && trim($notes) !== '') {
            $mergedNotes = ($mergedNotes ? $mergedNotes . " | " : "") . trim($notes);
        }

        $updStmt = $pdo->prepare("UPDATE staff_timecards SET clock_out = ?, total_hours = ?, status = 'completed', notes = ?, updated_at = ? WHERE id = ?");
        $updStmt->execute([$now, $totalHours, $mergedNotes, $now, (int)$active['id']]);

        // Audit log
        AuditLogger::log('TIMECARD_CLOCK_OUT', "Staff user #{$userId} clocked out at {$now}. Total hours: {$totalHours}h (UID: {$active['timecard_uid']})", 'staff_timecards', (string)$active['id']);

        return [
            'success' => true,
            'timecard_id' => (int)$active['id'],
            'timecard_uid' => $active['timecard_uid'],
            'clock_in' => $active['clock_in'],
            'clock_out' => $now,
            'total_hours' => $totalHours
        ];
    }

    /**
     * Get active timecard for a user, or null if clocked out.
     */
    public static function getActiveTimecard(PDO $pdo, int $userId): ?array {
        $stmt = $pdo->prepare("SELECT id, timecard_uid, user_id, clock_in, notes, status FROM staff_timecards WHERE user_id = ? AND status = 'active' AND clock_out IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $clockInSec = strtotime($row['clock_in']);
        $elapsedSec = max(0, time() - $clockInSec);
        $row['elapsed_seconds'] = $elapsedSec;
        $row['elapsed_hours'] = round($elapsedSec / 3600.0, 2);

        return $row;
    }

    /**
     * Correct a staff timecard (Manager or Owner only).
     * Reason is strictly required and permanently logged.
     */
    public static function correctTimecard(PDO $pdo, int $timecardId, string $correctedClockIn, string $correctedClockOut, string $reason, int $managerUserId, string $managerName): array {
        $reason = trim($reason);
        if ($reason === '') {
            return [
                'success' => false,
                'error' => 'reason_required',
                'message' => 'A detailed reason is strictly required to correct a staff timecard.'
            ];
        }

        // Validate time format and bounds
        $inSec = strtotime($correctedClockIn);
        $outSec = strtotime($correctedClockOut);

        if (!$inSec || !$outSec || $outSec <= $inSec) {
            return [
                'success' => false,
                'error' => 'invalid_times',
                'message' => 'Clock-out time must be after clock-in time.'
            ];
        }

        $tcStmt = $pdo->prepare("SELECT * FROM staff_timecards WHERE id = ?");
        $tcStmt->execute([$timecardId]);
        $original = $tcStmt->fetch(PDO::FETCH_ASSOC);

        if (!$original) {
            return [
                'success' => false,
                'error' => 'not_found',
                'message' => 'Timecard record not found.'
            ];
        }

        $correctedHours = round(($outSec - $inSec) / 3600.0, 2);
        $fmtIn = date('Y-m-d H:i:s', $inSec);
        $fmtOut = date('Y-m-d H:i:s', $outSec);

        // 1. Log to timecard_corrections
        $corStmt = $pdo->prepare("INSERT INTO timecard_corrections (timecard_id, user_id, original_clock_in, original_clock_out, corrected_clock_in, corrected_clock_out, reason, corrected_by, corrected_by_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $corStmt->execute([
            $timecardId,
            $original['user_id'],
            $original['clock_in'],
            $original['clock_out'],
            $fmtIn,
            $fmtOut,
            $reason,
            $managerUserId,
            $managerName
        ]);
        $correctionId = (int)$pdo->lastInsertId();

        // 2. Update staff_timecards
        $updStmt = $pdo->prepare("UPDATE staff_timecards SET clock_in = ?, clock_out = ?, total_hours = ?, status = 'corrected', updated_at = NOW() WHERE id = ?");
        $updStmt->execute([$fmtIn, $fmtOut, $correctedHours, $timecardId]);

        // 3. Log to audit_logs
        $auditDetail = "Timecard #{$timecardId} (UID: {$original['timecard_uid']}) corrected by {$managerName} (#{$managerUserId}). Original: [{$original['clock_in']} - {$original['clock_out']}] -> Corrected: [{$fmtIn} - {$fmtOut}] ({$correctedHours}h). Reason: {$reason}";
        AuditLogger::log('TIMECARD_CORRECTED', $auditDetail, 'staff_timecards', (string)$timecardId);

        return [
            'success' => true,
            'timecard_id' => $timecardId,
            'correction_id' => $correctionId,
            'corrected_hours' => $correctedHours,
            'message' => 'Timecard updated successfully with audit trail.'
        ];
    }

    /**
     * Fetch timecards list with filters.
     */
    public static function getTimecards(PDO $pdo, ?string $startDate = null, ?string $endDate = null, ?int $userId = null, int $limit = 100): array {
        $where = [];
        $params = [];

        if ($startDate) {
            $where[] = "DATE(st.clock_in) >= ?";
            $params[] = $startDate;
        }
        if ($endDate) {
            $where[] = "DATE(st.clock_in) <= ?";
            $params[] = $endDate;
        }
        if ($userId) {
            $where[] = "st.user_id = ?";
            $params[] = $userId;
        }

        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        $sql = "
            SELECT 
                st.*,
                u.full_name as staff_name,
                u.username,
                u.role as staff_role,
                (SELECT COUNT(*) FROM timecard_corrections tc WHERE tc.timecard_id = st.id) as correction_count,
                (SELECT reason FROM timecard_corrections tc WHERE tc.timecard_id = st.id ORDER BY tc.id DESC LIMIT 1) as latest_correction_reason,
                (SELECT corrected_by_name FROM timecard_corrections tc WHERE tc.timecard_id = st.id ORDER BY tc.id DESC LIMIT 1) as latest_corrected_by
            FROM staff_timecards st
            JOIN users u ON st.user_id = u.id
            {$whereClause}
            ORDER BY st.clock_in DESC
            LIMIT {$limit}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get total labor hours worked in date range.
     */
    public static function getTotalLaborHours(PDO $pdo, string $startDate, string $endDate, ?int $userId = null): float {
        $params = [$startDate, $endDate];
        $userWhere = "";
        if ($userId) {
            $userWhere = " AND user_id = ?";
            $params[] = $userId;
        }

        // Sum completed & corrected hours
        $sql = "
            SELECT COALESCE(SUM(total_hours), 0) as total_completed_hours
            FROM staff_timecards
            WHERE DATE(clock_in) BETWEEN ? AND ?
              AND status IN ('completed', 'corrected')
              {$userWhere}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $completedHours = (float)$stmt->fetchColumn();

        // Also add active hours for today if in range
        $now = date('Y-m-d');
        if ($startDate <= $now && $endDate >= $now) {
            $actSql = "
                SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, clock_in, NOW())), 0) / 3600.0 as active_hours
                FROM staff_timecards
                WHERE DATE(clock_in) = CURDATE()
                  AND status = 'active'
                  AND clock_out IS NULL
                  {$userWhere}
            ";
            $actStmt = $pdo->prepare($actSql);
            $actParams = $userId ? [$userId] : [];
            $actStmt->execute($actParams);
            $activeHours = (float)$actStmt->fetchColumn();
            $completedHours += $activeHours;
        }

        return round($completedHours, 2);
    }
}
