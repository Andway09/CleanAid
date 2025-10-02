<?php
include('../dB/config.php');
header('Content-Type: application/json');

// -------------------- START CLEANING --------------------
if (isset($_GET['start'])) {
    session_start();

    $_SESSION['clean_progress'] = 0;
    $_SESSION['clean_message']  = "Cleaning started...";

    // --- Save uploaded lists into session for review.php
    if (!empty($_SESSION['uploaded_lists'])) {
        $_SESSION['review_lists'] = $_SESSION['uploaded_lists'];
    }

    $res = $conn->query("SELECT COUNT(*) as total FROM beneficiary");
    $total = $res->fetch_assoc()['total'] ?? 0;

    $_SESSION['clean_total']     = $total;
    $_SESSION['clean_offset']    = 0;
    $_SESSION['clean_batchSize'] = 1000; // safe batch size

    session_write_close(); // unlock session

    echo json_encode(["status" => "started", "total" => $total]);
    exit;
}

// -------------------- PROGRESS CHECK --------------------
if (isset($_GET['progress'])) {
    session_start();
    $offset    = $_SESSION['clean_offset'] ?? 0;
    $batchSize = $_SESSION['clean_batchSize'] ?? 1000;
    $total     = $_SESSION['clean_total'] ?? 1;
    session_write_close(); // unlock quickly

    // --- Fetch next batch
    $rows = [];
    $res = $conn->query("SELECT * FROM beneficiary ORDER BY beneficiary_id LIMIT $offset, $batchSize");
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $processedNow = count($rows);

    // --- Run analyzer only if we got rows
    if ($processedNow > 0) {
        $jsonInput = json_encode($rows);
        $cmd = "python3 ../clean_data.py";
        $spec = [0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]];
        $proc = proc_open($cmd, $spec, $pipes);

        if (is_resource($proc)) {
            fwrite($pipes[0], $jsonInput);
            fclose($pipes[0]);

            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $err = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            proc_close($proc);

            $result = json_decode($output, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($result)) {
                $insertFlag = function($id, $reason) use ($conn) {
                    $id = intval($id);
                    $reason = $conn->real_escape_string($reason);

                    // Check if record already exists
                    $check = $conn->query("SELECT flagged_reason FROM duplicaterecord WHERE beneficiary_id=$id");
                    if ($check && $check->num_rows > 0) {
                        $existing = $check->fetch_assoc()['flagged_reason'];
                        // Avoid duplicate text
                        if (stripos($existing, $reason) === false) {
                            $newReason = $conn->real_escape_string($existing . "; " . $reason);
                            $conn->query("UPDATE duplicaterecord SET flagged_reason='$newReason' WHERE beneficiary_id=$id");
                        }
                    } else {
                        $conn->query("
                            INSERT INTO duplicaterecord (beneficiary_id, flagged_reason, status)
                            VALUES ($id, '$reason', 'flagged')
                        ");
                    }
                };

                // --- Exact duplicates
                foreach ($result['exact_duplicates'] ?? [] as $pair) {
                    $insertFlag($rows[$pair['row1_index']]['beneficiary_id'], "Exact Duplicate");
                    $insertFlag($rows[$pair['row2_index']]['beneficiary_id'], "Exact Duplicate");
                }

                // --- Fuzzy duplicates
                foreach ($result['fuzzy_duplicates'] ?? [] as $pair) {
                    $insertFlag($rows[$pair['row1_index']]['beneficiary_id'], "Possible Duplicate");
                    $insertFlag($rows[$pair['row2_index']]['beneficiary_id'], "Possible Duplicate");
                }

                // --- Sounds-like duplicates
                foreach ($result['sounds_like_duplicates'] ?? [] as $pair) {
                    $insertFlag($rows[$pair['row1_index']]['beneficiary_id'], "Sounds-Like Duplicate");
                    $insertFlag($rows[$pair['row2_index']]['beneficiary_id'], "Sounds-Like Duplicate");
                }

                // --- Missing data
                foreach ($result['missing_data'] ?? [] as $row) {
                    if (!empty($row['beneficiary_id'])) {
                        $insertFlag(intval($row['beneficiary_id']), "Missing Data");
                    }
                }
            } else {
                error_log("Python failed: $err");
            }
        }
    }

    // --- Update progress
    session_start();
    $_SESSION['clean_offset'] = $offset + $processedNow;
    $processed = min($total, $_SESSION['clean_offset']);
    $percent   = $total > 0 ? intval(($processed / $total) * 100) : 100;

    $_SESSION['clean_progress'] = $percent;
    $_SESSION['clean_message']  = ($percent >= 100)
        ? "✅ Cleaning completed. ($processed/$total)"
        : "Cleaning in progress... ($processed/$total)";
    $msg = $_SESSION['clean_message'];
    session_write_close();

    echo json_encode(["percent" => $percent, "message" => $msg]);
    exit;
}
?>
