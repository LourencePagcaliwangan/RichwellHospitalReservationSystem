<?php
require_once 'includes/JsonStorage.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $storage = new JsonStorage('appointments.json');
    $blockedStorage = new JsonStorage('blocked_slots.json');

    $patientName = trim($_POST['patient_name'] ?? '');
    $patientEmail = trim($_POST['patient_email'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $doctor = trim($_POST['doctor'] ?? '');
    $appointmentDate = trim($_POST['appointment_date'] ?? '');
    $timeSlot = trim($_POST['time_slot'] ?? '');

    if (empty($patientName) || empty($patientEmail) || empty($department) || empty($doctor) || empty($appointmentDate) || empty($timeSlot)) {
        header("Location: index.php?msg=" . urlencode("All booking fields including time slot are required.") . "&type=error");
        exit;
    }

    $appointments = $storage->read();
    $blockedSlots = $blockedStorage->read();

    foreach ($appointments as $r) {
        if ($r['doctor'] === $doctor &&
            $r['appointment_date'] === $appointmentDate &&
            $r['time_slot'] === $timeSlot &&
            $r['status'] !== 'Cancelled') {
            header("Location: index.php?msg=" . urlencode("Sorry, {$doctor} is already booked on {$appointmentDate} ({$timeSlot}).") . "&type=error");
            exit;
        }
    }

    foreach ($blockedSlots as $blk) {
        if ($blk['doctor'] === $doctor &&
            $blk['date'] === $appointmentDate &&
            $blk['time_slot'] === $timeSlot) {
            header("Location: index.php?msg=" . urlencode("Sorry, {$doctor} is unavailable on {$appointmentDate} ({$timeSlot}).") . "&type=error");
            exit;
        }
    }

    $refNo = 'RWH-' . strtoupper(substr(uniqid(), -6));

    $appointments[] = [
        'reference_no' => $refNo,
        'patient_name' => htmlspecialchars($patientName),
        'patient_email' => htmlspecialchars($patientEmail),
        'department' => htmlspecialchars($department),
        'doctor' => htmlspecialchars($doctor),
        'appointment_date' => htmlspecialchars($appointmentDate),
        'time_slot' => htmlspecialchars($timeSlot),
        'status' => 'Pending',
        'created_at' => date('Y-m-d H:i:s')
    ];

    $storage->write($appointments);
    header("Location: index.php?ref=" . urlencode($refNo));
    exit;
}