<?php
session_start();

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: admin_login.php");
    exit;
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

require_once 'includes/JsonStorage.php';
$storage = new JsonStorage('appointments.json');
$doctorStorage = new JsonStorage('doctors.json');
$blockedStorage = new JsonStorage('blocked_slots.json');
$historyStorage = new JsonStorage('admin_history.json');

$toastMsg = $_GET['msg'] ?? null;
$toastType = $_GET['type'] ?? 'success';

function safeJsonList($rawData): array {
    return is_array($rawData) ? $rawData : [];
}

function recordAdminHistory(JsonStorage $historyStorage, string $actionLabel, string $details): void {
    $logs = safeJsonList($historyStorage->read());
    array_unshift($logs, [
        'timestamp' => date('Y-m-d H:i:s'),
        'admin' => $_SESSION['admin_user'] ?? 'admin',
        'action' => $actionLabel,
        'details' => $details
    ]);
    $historyStorage->write(array_slice($logs, 0, 100));
}

// Handle Form Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Update Appointment Status
    if ($action === 'update_status') {
        $ref = trim($_POST['reference_no'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $allowedStatuses = ['Pending', 'Confirmed', 'Completed', 'Cancelled'];

        if ($ref !== '' && in_array($status, $allowedStatuses, true)) {
            $appointments = safeJsonList($storage->read());
            foreach ($appointments as &$app) {
                if (($app['reference_no'] ?? '') === $ref) {
                    $app['status'] = $status;
                    break;
                }
            }
            unset($app);

            $storage->write($appointments);
            recordAdminHistory($historyStorage, 'Updated Status', "Appointment {$ref} set to {$status}");
            header("Location: admin.php?msg=" . urlencode("Status updated to {$status} for {$ref}.") . "&type=success");
            exit;
        }
    }

    // 2. Delete Appointment
    if ($action === 'delete') {
        $ref = trim($_POST['reference_no'] ?? '');
        if ($ref !== '') {
            $appointments = safeJsonList($storage->read());
            $filtered = array_values(array_filter($appointments, function($app) use ($ref) {
                return ($app['reference_no'] ?? '') !== $ref;
            }));

            $storage->write($filtered);
            recordAdminHistory($historyStorage, 'Deleted Appointment', "Removed appointment {$ref}");
            header("Location: admin.php?msg=" . urlencode("Appointment {$ref} deleted.") . "&type=success");
            exit;
        }
    }

    // 3. Add Doctor
    if ($action === 'add_doctor') {
        $docName = trim($_POST['doc_name'] ?? '');
        $docDept = trim($_POST['doc_dept'] ?? '');
        $docHours = trim($_POST['doc_hours'] ?? '');
        $docBio = trim($_POST['doc_bio'] ?? '');

        if ($docHours === '') {
            $docHours = 'Mon - Fri | 9:00 AM - 3:00 PM';
        }

        if ($docName !== '' && $docDept !== '') {
            $doctors = safeJsonList($doctorStorage->read());
            $doctors[] = [
                'name' => $docName,
                'department' => $docDept,
                'hours' => $docHours,
                'bio' => $docBio
            ];
            $doctorStorage->write($doctors);
            recordAdminHistory($historyStorage, 'Added Doctor', "{$docName} ({$docDept})");
            header("Location: admin.php?msg=" . urlencode("Doctor {$docName} added to directory.") . "&type=success");
            exit;
        }
    }

    // 4. Delete Doctor
    if ($action === 'delete_doctor') {
        $docName = trim($_POST['doc_name'] ?? '');
        if ($docName !== '') {
            $doctors = safeJsonList($doctorStorage->read());
            $filtered = array_values(array_filter($doctors, function($d) use ($docName) {
                return ($d['name'] ?? '') !== $docName;
            }));

            $doctorStorage->write($filtered);
            recordAdminHistory($historyStorage, 'Removed Doctor', "Removed {$docName} from directory");
            header("Location: admin.php?msg=" . urlencode("Doctor {$docName} removed.") . "&type=success");
            exit;
        }
    }

    // 5. Block Slot
    if ($action === 'block_slot') {
        $blkDoc = trim($_POST['blk_doctor'] ?? '');
        $blkDate = trim($_POST['blk_date'] ?? '');
        $blkSlot = trim($_POST['blk_slot'] ?? '');

        if ($blkDoc !== '' && $blkDate !== '' && $blkSlot !== '') {
            $blocked = safeJsonList($blockedStorage->read());
            $alreadyBlocked = false;
            foreach ($blocked as $item) {
                if (
                    ($item['doctor'] ?? '') === $blkDoc &&
                    ($item['date'] ?? '') === $blkDate &&
                    ($item['time_slot'] ?? '') === $blkSlot
                ) {
                    $alreadyBlocked = true;
                    break;
                }
            }

            if (!$alreadyBlocked) {
                $blocked[] = [
                    'doctor' => $blkDoc,
                    'date' => $blkDate,
                    'time_slot' => $blkSlot
                ];
                $blockedStorage->write($blocked);
                recordAdminHistory($historyStorage, 'Blocked Slot', "{$blkDoc} on {$blkDate} ({$blkSlot})");
                header("Location: admin.php?msg=" . urlencode("Slot {$blkSlot} on {$blkDate} blocked for {$blkDoc}.") . "&type=success");
            } else {
                header("Location: admin.php?msg=" . urlencode("That slot is already blocked.") . "&type=error");
            }
            exit;
        }
    }

    // 6. Unblock Slot
    if ($action === 'unblock_slot') {
        $index = filter_input(INPUT_POST, 'blk_index', FILTER_VALIDATE_INT);
        $blocked = safeJsonList($blockedStorage->read());
        if ($index !== null && $index !== false && isset($blocked[$index])) {
            $removed = $blocked[$index];
            unset($blocked[$index]);
            $blockedStorage->write(array_values($blocked));
            recordAdminHistory(
                $historyStorage,
                'Unblocked Slot',
                ($removed['doctor'] ?? 'Doctor') . ' on ' . ($removed['date'] ?? '') . ' (' . ($removed['time_slot'] ?? '') . ')'
            );
            header("Location: admin.php?msg=" . urlencode("Slot unblocked.") . "&type=success");
            exit;
        }
    }
}

$appointments = safeJsonList($storage->read());
$doctors = safeJsonList($doctorStorage->read());
$blockedSlots = safeJsonList($blockedStorage->read());
$historyLogs = safeJsonList($historyStorage->read());

$search = strtolower(trim($_GET['search'] ?? ''));
$statusFilter = $_GET['status_filter'] ?? 'All';

$filteredAppointments = array_filter($appointments, function($r) use ($search, $statusFilter) {
    $status = $r['status'] ?? 'Pending';
    $refNo = $r['reference_no'] ?? '';
    $patientName = $r['patient_name'] ?? '';
    $patientEmail = $r['patient_email'] ?? '';
    $doctor = $r['doctor'] ?? '';
    $department = $r['department'] ?? '';

    $matchesStatus = ($statusFilter === 'All') || ($status === $statusFilter);
    $matchesSearch = ($search === '') ||
        stripos($refNo, $search) !== false ||
        stripos($patientName, $search) !== false ||
        stripos($patientEmail, $search) !== false ||
        stripos($doctor, $search) !== false ||
        stripos($department, $search) !== false;

    return $matchesStatus && $matchesSearch;
});

$totalCount = count($appointments);
$pendingCount = count(array_filter($appointments, fn($r) => ($r['status'] ?? '') === 'Pending'));
$confirmedCount = count(array_filter($appointments, fn($r) => ($r['status'] ?? '') === 'Confirmed'));
$completedCount = count(array_filter($appointments, fn($r) => ($r['status'] ?? '') === 'Completed'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Richwell Hospital</title>
    <style>
        @keyframes fadeIn {
            0% { opacity: 0; transform: translateY(10px) scale(0.99); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes slideInRight {
            0% { opacity: 0; transform: translateX(40px); }
            100% { opacity: 1; transform: translateX(0); }
        }
        @keyframes runnerStrideAdmin {
            0%, 100% { transform: translate(0, 0) scale(1); }
            45% { transform: translate(6px, -1px) scale(0.94); }
            85% { transform: translate(11px, 0) scale(0.75); opacity: 0.2; }
        }
        .animate-fade { animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }

        html, body { height: 100%; margin: 0; padding: 0; overflow-x: hidden; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.8), rgba(49, 46, 129, 0.7)), url('assets/richwell hospital.png') no-repeat center center fixed;
            background-size: cover; padding: 20px; color: #1e293b; box-sizing: border-box;
            display: flex; justify-content: center; align-items: center;
        }
        .container { 
            width: 100%; max-width: 1150px; background: rgba(255, 255, 255, 0.97); 
            padding: 30px; border-radius: 16px; box-shadow: 0 22px 48px rgba(15, 23, 42, 0.38); 
            border-top: 6px solid #1e3a8a; backdrop-filter: blur(12px);
            min-height: 550px; max-height: 92vh; overflow-y: auto;
            border-left: 1px solid rgba(255, 255, 255, 0.85);
            border-right: 1px solid rgba(255, 255, 255, 0.85);
            border-bottom: 1px solid rgba(255, 255, 255, 0.85);
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            scrollbar-width: thin; scrollbar-color: #93c5fd #f1f5f9;
            box-sizing: border-box;
        }
        .container::-webkit-scrollbar { width: 6px; }
        .container::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 8px; }
        .container::-webkit-scrollbar-thumb { background: #93c5fd; border-radius: 8px; }

        /* Linear Single-Line Header Alignment */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }
        .header-title-group {
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        /* Static, High-Contrast Logo Badge */
        .logo-frame {
            width: 64px;
            height: 64px;
            border-radius: 14px;
            background: linear-gradient(135deg, #1e3a8a, #4338ca);
            border: 2px solid #c4b5fd;
            box-shadow: 0 6px 16px rgba(30, 58, 138, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 6px;
            box-sizing: border-box;
            flex-shrink: 0;
        }
        .header img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            filter: contrast(1.15) saturate(1.2) drop-shadow(0 2px 4px rgba(0, 0, 0, 0.35));
        }

        .header h1 { color: #1e3a8a; margin: 0; font-size: 19px; font-weight: 700; line-height: 1.2; }
        .header p { margin: 3px 0 0 0; color: #475569; font-size: 12px; line-height: 1.2; }

        .header-nav {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-shrink: 0;
            white-space: nowrap;
        }
        .logout-anim-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 8px;
            background: #1e293b;
            color: #ffffff;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            border: 1px solid #334155;
            box-shadow: 0 3px 10px rgba(15, 23, 42, 0.18);
            transition: transform 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
            overflow: hidden;
        }
        .logout-anim-btn:hover {
            background: #0f172a;
            transform: translateY(-1px);
            box-shadow: 0 5px 14px rgba(109, 40, 217, 0.24);
        }
        .logout-door-stage {
            position: relative;
            width: 24px;
            height: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
        }
        .logout-doorway {
            width: 11px;
            height: 16px;
            border-radius: 2px;
            background: #4338ca;
            border: 1px solid #a5b4fc;
            position: relative;
            overflow: hidden;
            box-sizing: border-box;
        }
        .logout-door-leaf {
            position: absolute;
            inset: 0;
            background: #c4b5fd;
            transform-origin: left center;
            transform: scaleX(0.35);
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .logout-runner {
            position: absolute;
            left: 0;
            width: 11px;
            height: 14px;
            color: #ffffff;
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.25s ease;
        }
        .logout-anim-btn.logging-out .logout-runner {
            transform: translateX(11px) scale(0.75);
            opacity: 0;
            animation: runnerStrideAdmin 0.35s ease-in forwards;
        }
        .logout-anim-btn.logging-out .logout-door-leaf {
            transform: scaleX(1);
            transition-delay: 0.22s;
        }
        .portal-link { color: #1e3a8a; text-decoration: none; font-size: 12px; font-weight: bold; transition: color 0.2s ease; line-height: 1; }
        .portal-link:hover { color: #6d28d9; text-decoration: underline; }

        /* Toast Notification */
        .toast-container { position: fixed; top: 25px; right: 25px; z-index: 9999; }
        .toast {
            background: #0f766e; color: white; padding: 14px 22px; border-radius: 10px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.25); font-size: 13px; font-weight: 600;
            display: flex; align-items: center; gap: 10px;
            animation: slideInRight 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .toast.error { background: #b91c1c; }

        /* Stats Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 22px; }
        .stat-card { 
            background: #f8fafc; padding: 18px; border-radius: 12px; border-left: 5px solid #1e3a8a; 
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.04); transition: transform 0.25s ease, box-shadow 0.25s ease;
            border-top: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 25px rgba(109, 40, 217, 0.14); }
        .stat-num { font-size: 26px; font-weight: 800; color: #1e3a8a; }
        .stat-label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: bold; margin-top: 4px; letter-spacing: 0.5px; }

        /* Always-Visible Admin Navigation Bar */
        .tabs {
            position: sticky;
            top: 0;
            z-index: 20;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 22px;
            background: #f1f5f9;
            padding: 6px;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
            box-sizing: border-box;
        }
        .tab-btn { 
            background: transparent;
            border: none;
            padding: 10px 12px;
            font-weight: 700;
            border-radius: 8px; 
            cursor: pointer;
            color: #475569;
            font-size: 12.5px;
            z-index: 2;
            text-align: center;
            transition: color 0.2s ease, background 0.2s ease;
            box-sizing: border-box;
            white-space: nowrap;
        }
        .tab-btn:hover { color: #1e3a8a; background: rgba(255, 255, 255, 0.65); }
        .tab-btn.active { color: #ffffff; background: transparent; }

        .tab-indicator {
            position: absolute;
            top: 6px;
            bottom: 6px;
            left: 6px; 
            width: calc((100% - 12px - 24px) / 4);
            background: linear-gradient(135deg, #1e3a8a, #3730a3);
            border-radius: 8px;
            transition: transform 0.32s cubic-bezier(0.16, 1, 0.3, 1); 
            z-index: 1;
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.25);
            will-change: transform;
        }

        .section { display: none; opacity: 0; transform: translateY(8px); transition: opacity 0.25s ease, transform 0.25s cubic-bezier(0.16, 1, 0.3, 1); }
        .section.active { display: block; opacity: 1; transform: translateY(0); }

        .filter-bar { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 15px; align-items: center; background: #f8fafc; padding: 12px; border-radius: 10px; border: 1px solid #e2e8f0; }
        input, select { padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff; transition: all 0.2s; }
        input:focus, select:focus { border-color: #1e3a8a; outline: none; box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.14); }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 12.5px; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04); }
        th, td { border: 1px solid #e2e8f0; padding: 10px; text-align: left; }
        th { background: #1e3a8a; color: white; font-weight: 600; }
        tr { transition: background-color 0.2s ease; }
        tr:hover { background-color: #f8fafc !important; }

        .btn-sm { padding: 6px 10px; font-size: 11px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; color: white; transition: transform 0.1s; }
        .btn-sm:active { transform: scale(0.95); }
        .btn-save { background: #0f766e; }
        .btn-delete { background: #b91c1c; }

        .form-card { background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #cbd5e1; margin-bottom: 20px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
        label { display: block; margin-bottom: 4px; font-weight: 600; font-size: 12px; color: #1e3a8a; }
        .btn-primary { 
            background: linear-gradient(135deg, #1e3a8a, #3730a3); color: white; padding: 10px 18px; border: none; 
            border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: bold; transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 4px 15px rgba(30, 58, 138, 0.25);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(30, 58, 138, 0.36); }
        .btn-primary:active { transform: scale(0.98); }

        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-weight: bold; font-size: 11px; }
        .history-tag {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            background: #eef2ff;
            color: #1e3a8a;
        }

        @media (max-width: 860px) {
            .tabs { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .tab-indicator { display: none; }
            .tab-btn.active { background: #1e3a8a; color: #ffffff; }
        }
        @media (max-width: 768px) {
            .header { flex-direction: column; align-items: flex-start; }
            .header-nav { width: 100%; justify-content: flex-end; }
        }
    </style>
    <script>
        function playLogoutAndGo(event, url) {
            event.preventDefault();
            const btn = event.currentTarget;
            if (btn.classList.contains('logging-out')) return;
            btn.classList.add('logging-out');
            setTimeout(() => {
                window.location.href = url;
            }, 650);
        }

        function switchAdminTab(tabId, element, index) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            if (element) element.classList.add('active');

            const indicator = document.getElementById('adminTabIndicator');
            if (indicator) {
                indicator.style.transform = `translateX(calc(${index} * (100% + 8px)))`;
            }

            document.querySelectorAll('.section').forEach(sec => sec.classList.remove('active'));
            const targetSec = document.getElementById(tabId);
            if (targetSec) {
                targetSec.classList.add('active');
            }
        }

        function liveSearchTable(inputId, tableId) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const query = input.value.toLowerCase();
            const rows = document.querySelectorAll(`#${tableId} tbody tr`);
            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        }

        window.addEventListener('DOMContentLoaded', () => {
            const toast = document.getElementById('toastAlert');
            if (toast) {
                setTimeout(() => {
                    toast.style.opacity = '0';
                    toast.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => toast.remove(), 500);
                }, 4000);
            }
        });
    </script>
</head>
<body>

<?php if ($toastMsg): ?>
    <div class="toast-container" id="toastAlert">
        <div class="toast <?php echo $toastType === 'error' ? 'error' : ''; ?>">
            <span><?php echo $toastType === 'error' ? '⚠️' : '✅'; ?></span>
            <span><?php echo htmlspecialchars($toastMsg); ?></span>
        </div>
    </div>
<?php endif; ?>

<div class="container animate-fade">
    <div class="header">
        <div class="header-left">
            <div class="logo-frame">
                <img src="assets/logo.png" alt="Richwell Logo">
            </div>
            <div class="header-title-group">
                <h1>Richwell Hospital - Admin Dashboard</h1>
                <p>Manage Outpatient Reservations, Doctors, Slot Availability & History</p>
            </div>
        </div>
        <div class="header-nav">
            <a href="index.php" class="portal-link">&larr; Patient Portal</a>
            <a href="admin.php?logout=1" class="logout-anim-btn" onclick="playLogoutAndGo(event, 'admin.php?logout=1')">
                <span>Log Out</span>
                <span class="logout-door-stage" aria-hidden="true">
                    <svg class="logout-runner" viewBox="0 0 16 16" fill="currentColor">
                        <circle cx="9" cy="2.8" r="1.6" />
                        <path d="M6.8 5.2l2.6 1.2 1.8-.9.7 1.3-2.2 1.1-.8 2.4 2.1 1.9-1 1.1-2.4-2.2-.8 2.4-1.5-.4 1-3-1.4-.7-1.1 1.7-1.2-.8 1.6-2.4z" />
                    </svg>
                    <span class="logout-doorway"><span class="logout-door-leaf"></span></span>
                </span>
            </a>
        </div>
    </div>

    <!-- STATS OVERVIEW -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-num"><?php echo $totalCount; ?></div>
            <div class="stat-label">Total Bookings</div>
        </div>
        <div class="stat-card" style="border-left-color: #d97706;">
            <div class="stat-num" style="color: #d97706;"><?php echo $pendingCount; ?></div>
            <div class="stat-label">Pending</div>
        </div>
        <div class="stat-card" style="border-left-color: #0f766e;">
            <div class="stat-num" style="color: #0f766e;"><?php echo $confirmedCount; ?></div>
            <div class="stat-label">Confirmed</div>
        </div>
        <div class="stat-card" style="border-left-color: #6d28d9;">
            <div class="stat-num" style="color: #6d28d9;"><?php echo $completedCount; ?></div>
            <div class="stat-label">Completed</div>
        </div>
    </div>

    <!-- ALWAYS-VISIBLE ADMIN NAVIGATION TABS -->
    <nav class="tabs" aria-label="Admin Features Navigation">
        <div id="adminTabIndicator" class="tab-indicator"></div>
        <button type="button" class="tab-btn active" onclick="switchAdminTab('appointments-tab', this, 0)">Appointments</button>
        <button type="button" class="tab-btn" onclick="switchAdminTab('doctors-tab', this, 1)">Doctors</button>
        <button type="button" class="tab-btn" onclick="switchAdminTab('slots-tab', this, 2)">Slot Blocking</button>
        <button type="button" class="tab-btn" onclick="switchAdminTab('history-tab', this, 3)">Action History</button>
    </nav>

    <!-- TAB 1: APPOINTMENTS MANAGER -->
    <div id="appointments-tab" class="section active">
        <form action="admin.php" method="GET" class="filter-bar">
            <input type="text" id="adminLiveSearch" name="search" placeholder="🔍 Filter or search ref, patient, doctor..." value="<?php echo htmlspecialchars($search); ?>" onkeyup="liveSearchTable('adminLiveSearch', 'adminAppTable')" style="flex: 1; min-width: 220px;">
            <select name="status_filter">
                <option value="All" <?php if ($statusFilter === 'All') echo 'selected'; ?>>All Statuses</option>
                <option value="Pending" <?php if ($statusFilter === 'Pending') echo 'selected'; ?>>Pending</option>
                <option value="Confirmed" <?php if ($statusFilter === 'Confirmed') echo 'selected'; ?>>Confirmed</option>
                <option value="Completed" <?php if ($statusFilter === 'Completed') echo 'selected'; ?>>Completed</option>
                <option value="Cancelled" <?php if ($statusFilter === 'Cancelled') echo 'selected'; ?>>Cancelled</option>
            </select>
            <button type="submit" class="btn-primary">Filter</button>
            <a href="admin.php" style="font-size: 12px; color: #64748b; text-decoration: none; margin-left: 5px;">Reset</a>
        </form>

        <table id="adminAppTable">
            <thead>
                <tr>
                    <th>Ref No</th>
                    <th>Patient Name</th>
                    <th>Department</th>
                    <th>Doctor</th>
                    <th>Schedule</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($filteredAppointments)): ?>
                    <tr><td colspan="7" style="text-align: center; color: #64748b; padding: 20px;">No records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($filteredAppointments as $r): ?>
                        <?php
                        $refNo = $r['reference_no'] ?? '';
                        $patientName = $r['patient_name'] ?? '';
                        $patientEmail = $r['patient_email'] ?? '';
                        $department = $r['department'] ?? '';
                        $doctor = $r['doctor'] ?? '';
                        $appDate = $r['appointment_date'] ?? '';
                        $timeSlot = $r['time_slot'] ?? '';
                        $status = $r['status'] ?? 'Pending';

                        $badgeBg = '#fef3c7; color: #b45309;';
                        if ($status === 'Confirmed') $badgeBg = '#dcfce7; color: #15803d;';
                        elseif ($status === 'Completed') $badgeBg = '#dbeafe; color: #1d4ed8;';
                        elseif ($status === 'Cancelled') $badgeBg = '#fee2e2; color: #b91c1c;';
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($refNo); ?></strong></td>
                            <td>
                                <?php echo htmlspecialchars($patientName); ?><br>
                                <span style="font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($patientEmail); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($department); ?></td>
                            <td><?php echo htmlspecialchars($doctor); ?></td>
                            <td><?php echo htmlspecialchars(trim($appDate . ' | ' . $timeSlot, ' |')); ?></td>
                            <td>
                                <span class="status-badge" style="background: <?php echo $badgeBg; ?>"><?php echo htmlspecialchars($status); ?></span>
                            </td>
                            <td style="white-space: nowrap;">
                                <form action="admin.php" method="POST" style="display:inline-flex; gap: 4px;">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="reference_no" value="<?php echo htmlspecialchars($refNo); ?>">
                                    <select name="status" style="padding: 4px; font-size: 11px;">
                                        <option value="Pending" <?php if ($status === 'Pending') echo 'selected'; ?>>Pending</option>
                                        <option value="Confirmed" <?php if ($status === 'Confirmed') echo 'selected'; ?>>Confirmed</option>
                                        <option value="Completed" <?php if ($status === 'Completed') echo 'selected'; ?>>Completed</option>
                                        <option value="Cancelled" <?php if ($status === 'Cancelled') echo 'selected'; ?>>Cancelled</option>
                                    </select>
                                    <button type="submit" class="btn-sm btn-save">Save</button>
                                </form>

                                <form action="admin.php" method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete this appointment?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="reference_no" value="<?php echo htmlspecialchars($refNo); ?>">
                                    <button type="submit" class="btn-sm btn-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- TAB 2: DOCTORS MANAGEMENT -->
    <div id="doctors-tab" class="section">
        <div class="form-card">
            <h3 style="margin-top: 0; color: #1e3a8a;">Add New Medical Specialist</h3>
            <form action="admin.php" method="POST">
                <input type="hidden" name="action" value="add_doctor">
                <div class="form-grid">
                    <div>
                        <label>Doctor Name:</label>
                        <input type="text" name="doc_name" placeholder="Dr. Maria Clara Santos" required>
                    </div>
                    <div>
                        <label>Department:</label>
                        <input type="text" name="doc_dept" placeholder="Pediatrics" required>
                    </div>
                    <div>
                        <label>Schedule / Hours:</label>
                        <input type="text" name="doc_hours" placeholder="Mon - Fri | 9:00 AM - 3:00 PM">
                    </div>
                    <div>
                        <label>Specialty Bio:</label>
                        <input type="text" name="doc_bio" placeholder="Child care and outpatient pediatrics">
                    </div>
                </div>
                <button type="submit" class="btn-primary" style="margin-top: 12px;">+ Add Doctor</button>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Doctor Name</th>
                    <th>Department</th>
                    <th>Schedule</th>
                    <th>Bio</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($doctors)): ?>
                    <tr><td colspan="5" style="text-align: center; color: #64748b; padding: 20px;">No doctors in directory yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($doctors as $doc): ?>
                        <?php $docName = $doc['name'] ?? ''; ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($docName); ?></strong></td>
                            <td><?php echo htmlspecialchars($doc['department'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($doc['hours'] ?? 'Mon - Fri | 9:00 AM - 3:00 PM'); ?></td>
                            <td><?php echo htmlspecialchars($doc['bio'] ?? ''); ?></td>
                            <td>
                                <form action="admin.php" method="POST" style="display:inline;" onsubmit="return confirm('Remove this doctor?');">
                                    <input type="hidden" name="action" value="delete_doctor">
                                    <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($docName); ?>">
                                    <button type="submit" class="btn-sm btn-delete">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- TAB 3: SLOT BLOCKING -->
    <div id="slots-tab" class="section">
        <div class="form-card">
            <h3 style="margin-top: 0; color: #1e3a8a;">Block Unavailable Time Slot</h3>
            <form action="admin.php" method="POST">
                <input type="hidden" name="action" value="block_slot">
                <div class="form-grid">
                    <div>
                        <label>Select Doctor:</label>
                        <select name="blk_doctor" required>
                            <?php foreach ($doctors as $doc): ?>
                                <?php $docName = $doc['name'] ?? ''; ?>
                                <option value="<?php echo htmlspecialchars($docName); ?>"><?php echo htmlspecialchars($docName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Date:</label>
                        <input type="date" name="blk_date" min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div>
                        <label>Time Slot:</label>
                        <select name="blk_slot" required>
                            <option value="08:00 AM - 09:00 AM">08:00 AM - 09:00 AM</option>
                            <option value="09:00 AM - 10:00 AM">09:00 AM - 10:00 AM</option>
                            <option value="10:00 AM - 11:00 AM">10:00 AM - 11:00 AM</option>
                            <option value="01:00 PM - 02:00 PM">01:00 PM - 02:00 PM</option>
                            <option value="02:00 PM - 03:00 PM">02:00 PM - 03:00 PM</option>
                            <option value="03:00 PM - 04:00 PM">03:00 PM - 04:00 PM</option>
                            <option value="04:00 PM - 05:00 PM">04:00 PM - 05:00 PM</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-primary" style="margin-top: 12px; background: linear-gradient(135deg, #b91c1c, #991b1b);">🚫 Block Slot</button>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Doctor</th>
                    <th>Blocked Date</th>
                    <th>Time Slot</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($blockedSlots)): ?>
                    <tr><td colspan="4" style="text-align: center; color: #64748b; padding: 20px;">No slots are currently blocked.</td></tr>
                <?php else: ?>
                    <?php foreach ($blockedSlots as $idx => $blk): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($blk['doctor'] ?? ''); ?></strong></td>
                            <td><?php echo htmlspecialchars($blk['date'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($blk['time_slot'] ?? ''); ?></td>
                            <td>
                                <form action="admin.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="unblock_slot">
                                    <input type="hidden" name="blk_index" value="<?php echo (int)$idx; ?>">
                                    <button type="submit" class="btn-sm btn-save">Unblock</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- TAB 4: ACTION HISTORY -->
    <div id="history-tab" class="section">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 10px;">
            <h3 style="margin: 0; color: #1e3a8a;">Recent Admin Activity History</h3>
            <input type="text" id="historyLiveSearch" placeholder="🔍 Search history..." onkeyup="liveSearchTable('historyLiveSearch', 'adminHistoryTable')" style="max-width: 260px;">
        </div>

        <table id="adminHistoryTable">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Admin</th>
                    <th>Action</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($historyLogs)): ?>
                    <tr><td colspan="4" style="text-align: center; color: #64748b; padding: 20px;">No admin actions recorded yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($historyLogs as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($log['timestamp'] ?? ''); ?></td>
                            <td><strong><?php echo htmlspecialchars($log['admin'] ?? 'admin'); ?></strong></td>
                            <td><span class="history-tag"><?php echo htmlspecialchars($log['action'] ?? ''); ?></span></td>
                            <td><?php echo htmlspecialchars($log['details'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
