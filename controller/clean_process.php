<?php
session_start();
include('../dB/config.php');
header('Content-Type: application/json');

// ------------------------------------
// START CLEANING
// ------------------------------------
if (isset($_GET['start'])) {
    $_SESSION['clean_progress'] = 0;
    $_SESSION['clean_message']  = "Cleaning started...";

    // Count total beneficiaries
    $res = $conn->query("SELECT COUNT(*) as total FROM beneficiary");
    $total = $res->fetch_assoc()['total'] ?? 0;

    $_SESSION['clean_total'] = $total;
    $_SESSION['clean_offset'] = 0;
    $_SESSION['clean_batchSize'] = 2000; // 2k–5k recommended

    echo json_encode(["status" => "started", "total" => $total]);
    exit;
}

// ------------------------------------
// PROGRESS CHECK (process next batch)
// ------------------------------------
if (isset($_GET['progress'])) {
    $offset = $_SESSION['clean_offset'] ?? 0;
    $batchSize = $_SESSION['clean_batchSize'] ?? 2000;
    $total = $_SESSION['clean_total'] ?? 1;

    // Fetch one batch
    $rows = [];
    $res = $conn->query("SELECT * FROM beneficiary ORDER BY beneficiary_id LIMIT $offset, $batchSize");
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }

    $processedNow = count($rows);

    if ($processedNow > 0) {
        $jsonInput = json_encode($rows);

        // Run Python analyzer
        $cmd = "python3 ../controller/clean_data.py";
        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];
        $proc = proc_open($cmd, $descriptorspec, $pipes);

        if (is_resource($proc)) {
            fwrite($pipes[0], $jsonInput);
            fclose($pipes[0]);

            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($proc);

            $result = json_decode($output, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($result)) {

                // helper to insert flagged records
                $insertFlag = function($id, $reason) use ($conn) {
                    $id = intval($id);
                    $reason = $conn->real_escape_string($reason);
                    $conn->query("
                        INSERT INTO duplicaterecord (beneficiary_id, flagged_reason, status)
                        VALUES ($id, '$reason', 'flagged')
                        ON DUPLICATE KEY UPDATE flagged_reason=VALUES(flagged_reason)
                    ");
                };

                // Exact duplicates
                if (!empty($result['exact_duplicates'])) {
                    foreach ($result['exact_duplicates'] as $pair) {
                        $id1 = $rows[$pair['row1_index']]['beneficiary_id'];
                        $id2 = $rows[$pair['row2_index']]['beneficiary_id'];
                        $insertFlag($id1, "Exact Duplicate");
                        $insertFlag($id2, "Exact Duplicate");
                    }
                }

                // Fuzzy duplicates
                if (!empty($result['fuzzy_duplicates'])) {
                    foreach ($result['fuzzy_duplicates'] as $pair) {
                        $id1 = $rows[$pair['row1_index']]['beneficiary_id'];
                        $id2 = $rows[$pair['row2_index']]['beneficiary_id'];
                        $sim = intval($pair['similarity']);
                        $reason = "Fuzzy Duplicate ($sim% match)";
                        $insertFlag($id1, $reason);
                        $insertFlag($id2, $reason);
                    }
                }

                // Phonetic matches
                if (!empty($result['sounds_like_duplicates'])) {
                    foreach ($result['sounds_like_duplicates'] as $pair) {
                        $id1 = $rows[$pair['row1_index']]['beneficiary_id'];
                        $id2 = $rows[$pair['row2_index']]['beneficiary_id'];
                        $code = $conn->real_escape_string($pair['phonetic_code']);
                        $reason = "Phonetic Match ($code)";
                        $insertFlag($id1, $reason);
                        $insertFlag($id2, $reason);
                    }
                }
            }
        }
    }

    // Update offset safely
    $_SESSION['clean_offset'] = $offset + $processedNow;

    // Progress percent
    $processed = min($total, $_SESSION['clean_offset']);
    $percent = $total > 0 ? intval(($processed / $total) * 100) : 100;

    if ($percent >= 100) {
        $_SESSION['clean_progress'] = 100;
        $_SESSION['clean_message'] = "Cleaning completed.";
    } else {
        $_SESSION['clean_progress'] = $percent;
        $_SESSION['clean_message'] = "Cleaning in progress... $percent%";
    }

    echo json_encode([
        "percent" => $_SESSION['clean_progress'],
        "message" => $_SESSION['clean_message']
    ]);
    exit;
}
?>
