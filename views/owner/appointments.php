<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';

Auth::requirePermission('extras.list');
$pageTitle = __('nav_appointments', 'Customer Appointments & Bookings');
$pdo = Database::getInstance();

$message = null;
$messageType = 'success';

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

// Handle Form Actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($csrfError)) {
    $action = $_POST['action'] ?? '';

    // 1. Book Appointment
    if ($action === 'book_appointment') {
        $cName = trim($_POST['customer_name'] ?? '');
        $cPhone = trim($_POST['customer_phone'] ?? '');
        $plate = strtoupper(trim($_POST['plate_number'] ?? ''));
        $vType = trim($_POST['vehicle_type'] ?? 'small_car');
        $sId = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
        $date = trim($_POST['appointment_date'] ?? date('Y-m-d'));
        $time = trim($_POST['appointment_time'] ?? '10:00');
        $notes = trim($_POST['notes'] ?? '');

        if ($cName === '' || $plate === '') {
            $message = 'Customer name and vehicle plate are required.';
            $messageType = 'danger';
        } else {
            $stmt = $pdo->prepare("INSERT INTO appointments (customer_name, customer_phone, plate_number, vehicle_type, service_id, appointment_date, appointment_time, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$cName, $cPhone !== '' ? $cPhone : null, $plate, $vType, $sId, $date, $time, $notes !== '' ? $notes : null]);
            $message = "Appointment booked for {$plate} on {$date} at {$time}!";
        }
    }

    // 2. Update Appointment Status (confirm, arrive, cancel)
    elseif ($action === 'update_status') {
        $appId = (int)($_POST['appointment_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'confirmed');
        if ($appId > 0 && in_array($newStatus, ['confirmed', 'arrived', 'cancelled', 'completed'], true)) {
            $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ?")->execute([$newStatus, $appId]);
            $message = "Appointment marked as " . ucfirst($newStatus) . ".";
        }
    }

    // 3. Delete Appointment
    elseif ($action === 'delete_appointment') {
        $appId = (int)($_POST['appointment_id'] ?? 0);
        if ($appId > 0) {
            $pdo->prepare("DELETE FROM appointments WHERE id = ?")->execute([$appId]);
            $message = "Appointment deleted successfully.";
        }
    }
}

// Fetch Appointments with service names
$appointments = $pdo->query("
    SELECT a.*, s.name_en as service_name, s.name_km as service_name_km 
    FROM appointments a 
    LEFT JOIN services s ON a.service_id = s.id 
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Calculate Quick Stats
$todayDate = date('Y-m-d');
$totalAppointments = count($appointments);
$todayCount = 0;
$upcomingCount = 0;
foreach ($appointments as $a) {
    if ($a['appointment_date'] === $todayDate) {
        $todayCount++;
    } elseif ($a['appointment_date'] > $todayDate) {
        $upcomingCount++;
    }
}

// Fetch active services for the booking modal dropdown
$servicesList = $pdo->query("SELECT id, name_en, name_km FROM services WHERE is_active = 1 ORDER BY name_en ASC")->fetchAll(PDO::FETCH_ASSOC);

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <span class="badge badge-primary" style="padding: 6px 12px; font-size: 13px;">
            <i class="fa-solid fa-calendar-check mr-1"></i> <?= $totalAppointments ?> Total Bookings
        </span>
        <span style="font-size: 13px; color: var(--text-muted);">
            Customer Reservations &amp; Service Bay Scheduling
        </span>
    </div>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <?php if (Auth::can('pos.access')): ?>
            <a href="<?= get_pos_url() ?>" class="btn btn-secondary">
                <i class="fa-solid fa-cash-register mr-1"></i> Open POS
            </a>
        <?php endif; ?>
        <button type="button" class="btn btn-primary" onclick="openModal('appointmentModal')">
            <i class="fa-solid fa-plus mr-1"></i> Book New Appointment
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<!-- Quick KPI Cards -->
<div class="grid grid-cols-3" style="gap: 16px; margin-bottom: 24px;">
    <div class="stat-card" style="border-left: 5px solid var(--primary);">
        <div class="stat-icon" style="background: var(--primary-light); color: var(--primary);">
            <i class="fa-solid fa-calendar-days"></i>
        </div>
        <div>
            <div class="stat-value"><?= $todayCount ?></div>
            <div class="stat-label">Scheduled for Today</div>
        </div>
    </div>

    <div class="stat-card" style="border-left: 5px solid #0284c7;">
        <div class="stat-icon" style="background: #e0f2fe; color: #0284c7;">
            <i class="fa-solid fa-clock"></i>
        </div>
        <div>
            <div class="stat-value"><?= $upcomingCount ?></div>
            <div class="stat-label">Upcoming / Future Days</div>
        </div>
    </div>

    <div class="stat-card" style="border-left: 5px solid var(--success);">
        <div class="stat-icon green">
            <i class="fa-solid fa-clipboard-check"></i>
        </div>
        <div>
            <div class="stat-value"><?= $totalAppointments ?></div>
            <div class="stat-label">Total Bookings on File</div>
        </div>
    </div>
</div>

<!-- Appointments Table Card -->
<div class="card">
    <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <h2 style="font-size: 16px; font-weight: 700; margin: 0; color: var(--text-main);">
            <i class="fa-solid fa-list mr-1" style="color: var(--primary);"></i> Upcoming Booking Schedule
        </h2>
        <div class="text-xs text-muted">
            Showing <?= count($appointments) ?> registered bookings
        </div>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 140px;">Date &amp; Time</th>
                    <th>Customer Name &amp; Phone</th>
                    <th>Vehicle Plate &amp; Size</th>
                    <th>Requested Service</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th style="text-align: right; width: 180px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($appointments)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 48px;">
                            <i class="fa-solid fa-calendar-xmark" style="font-size: 36px; opacity: 0.3; margin-bottom: 12px;"></i>
                            <p style="font-size: 15px; font-weight: 600; margin-bottom: 4px;">No upcoming appointments</p>
                            <p style="font-size: 13px; margin: 0;">Click <strong>"Book New Appointment"</strong> above to schedule a customer visit.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($appointments as $app): 
                        $isToday = ($app['appointment_date'] === $todayDate);
                        $appStatus = strtolower($app['status'] ?? 'confirmed');
                        $badgeClass = ($appStatus === 'arrived') ? 'badge-success' : (($appStatus === 'confirmed') ? 'badge-primary' : (($appStatus === 'cancelled') ? 'badge-danger' : 'badge-slate'));
                    ?>
                    <tr style="<?= $isToday ? 'background: rgba(2, 132, 199, 0.04);' : '' ?>">
                        <td>
                            <strong style="color: <?= $isToday ? 'var(--primary)' : 'var(--text-main)' ?>;">
                                <?= htmlspecialchars($app['appointment_date']) ?>
                            </strong>
                            <?php if ($isToday): ?>
                                <span class="badge badge-amber" style="font-size: 10px; margin-left: 4px; padding: 1px 5px;">Today</span>
                            <?php endif; ?>
                            <div class="text-xs text-muted" style="margin-top: 2px;">
                                <i class="fa-solid fa-clock mr-1"></i> <?= htmlspecialchars(substr($app['appointment_time'], 0, 5)) ?>
                            </div>
                        </td>
                        <td>
                            <div style="font-weight: 700; color: var(--text-main); font-size: 14px;">
                                <?= htmlspecialchars((string)($app['customer_name'] ?? 'Guest Customer')) ?>
                            </div>
                            <?php if (!empty($app['customer_phone'])): ?>
                                <div class="text-xs text-muted" style="margin-top: 2px;">
                                    <i class="fa-solid fa-phone mr-1"></i> <?= htmlspecialchars((string)$app['customer_phone']) ?>
                                </div>
                            <?php else: ?>
                                <div class="text-xs text-muted">-</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="font-family: var(--font-mono); font-weight: 800; background: var(--bg-subtle); padding: 3px 8px; border-radius: var(--radius-sm); border: 1px solid var(--border); font-size: 13px;">
                                <?= htmlspecialchars($app['plate_number']) ?>
                            </span>
                            <div style="margin-top: 4px;"><?= vehicleTypeBadge($app['vehicle_type']) ?></div>
                        </td>
                        <td>
                            <strong style="color: var(--text-main);">
                                <?= htmlspecialchars((Language::current() === 'km' && !empty($app['service_name_km'])) ? $app['service_name_km'] : ($app['service_name'] ?? 'General Wash & Detailing')) ?>
                            </strong>
                        </td>
                        <td>
                            <span class="badge <?= $badgeClass ?>">
                                <?= htmlspecialchars(ucfirst($appStatus)) ?>
                            </span>
                        </td>
                        <td>
                            <span class="text-xs text-muted"><?= htmlspecialchars($app['notes'] ?? '-') ?></span>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px; align-items: center;">
                                <?php if ($appStatus !== 'arrived' && $appStatus !== 'cancelled'): ?>
                                    <a href="<?= get_pos_url() ?>" class="btn btn-primary btn-sm" title="Intake into POS & Bay Queue">
                                        <i class="fa-solid fa-arrow-right mr-1"></i> Intake
                                    </a>
                                <?php endif; ?>
                                <form action="" method="POST" style="margin: 0;" onsubmit="return confirm('Cancel this appointment?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="appointment_id" value="<?= $app['id'] ?>">
                                    <input type="hidden" name="status" value="cancelled">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="padding: 4px 8px;" title="Cancel Booking">
                                        <i class="fa-solid fa-ban" style="color: var(--danger);"></i>
                                    </button>
                                </form>
                                <form action="" method="POST" style="margin: 0;" onsubmit="return confirm('Delete this appointment record?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_appointment">
                                    <input type="hidden" name="appointment_id" value="<?= $app['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="padding: 4px 8px;" title="Delete Booking">
                                        <i class="fa-solid fa-trash" style="color: var(--text-muted);"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Book New Appointment -->
<div id="appointmentModal" class="modal-backdrop" style="display: none;">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fa-solid fa-calendar-plus mr-1" style="color: var(--primary);"></i> Book New Customer Appointment</h3>
            <button type="button" class="modal-close" onclick="closeModal('appointmentModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="book_appointment">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Customer Name <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="customer_name" class="form-input" required placeholder="e.g. Sok Piseth">
                </div>

                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="customer_phone" class="form-input" placeholder="e.g. 012 345 678">
                </div>

                <div class="grid grid-cols-2" style="gap: 12px;">
                    <div class="form-group">
                        <label class="form-label">Plate Number <span style="color: var(--danger);">*</span></label>
                        <input type="text" name="plate_number" class="form-input" required style="font-family: var(--font-mono); font-weight: 700; text-transform: uppercase;" placeholder="e.g. 2BQ-8899">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Vehicle Size <span style="color: var(--danger);">*</span></label>
                        <select name="vehicle_type" class="form-select">
                            <option value="small_car">Small Car / Sedan</option>
                            <option value="medium_car">Medium Car / Crossover</option>
                            <option value="suv_pickup">SUV / Pickup</option>
                            <option value="van_truck">Van / Large Truck</option>
                            <option value="motorbike">Motorbike</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Requested Service Package</label>
                    <select name="service_id" class="form-select">
                        <option value="">-- Select Package --</option>
                        <?php foreach ($servicesList as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars((Language::current() === 'km' && !empty($s['name_km'])) ? $s['name_km'] : $s['name_en']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2" style="gap: 12px;">
                    <div class="form-group">
                        <label class="form-label">Appointment Date <span style="color: var(--danger);">*</span></label>
                        <input type="date" name="appointment_date" class="form-input" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Time Slot <span style="color: var(--danger);">*</span></label>
                        <input type="time" name="appointment_time" class="form-input" required value="10:00">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes / Customer Requests</label>
                    <textarea name="notes" class="form-input" rows="2" placeholder="e.g. Needs interior leather conditioner treatment"></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('appointmentModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check mr-1"></i> Confirm Booking</button>
            </div>
        </form>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>

