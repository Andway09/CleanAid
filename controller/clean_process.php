<?php
session_start();
include('../dB/config.php');
header('Content-Type: application/json');

// --- START CLEANING ---
if (isset($_GET['start'])) {
    $_SESSION['clean_progress'] = 0;
    $_SESSION['clean_message']  = "Cleaning started...";

    // TODO: Replace this simulation with real cleaning logic
    // For example: remove duplicates from `beneficiary` table
    echo json_encode(["status" => "started"]);
    exit;
}

// --- PROGRESS CHECK ---
if (isset($_GET['progress'])) {
    if (!isset($_SESSION['clean_progress'])) {
        echo json_encode(["percent" => 0, "message" => "Waiting..."]);
        exit;
    }

    // Simulated progress increment
    $_SESSION['clean_progress'] = min(100, $_SESSION['clean_progress'] + 10);

    if ($_SESSION['clean_progress'] >= 100) {
        $_SESSION['clean_message'] = "Cleaning completed.";
    } else {
        $_SESSION['clean_message'] = "Cleaning in progress... " . $_SESSION['clean_progress'] . "%";
    }

    echo json_encode([
        "percent" => $_SESSION['clean_progress'],
        "message" => $_SESSION['clean_message']
    ]);
    exit;
}
