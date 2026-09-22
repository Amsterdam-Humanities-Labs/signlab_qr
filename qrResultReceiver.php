<?php
/**
 * qr/qrResultReceiver.php
 *
 * Receives decoded QR results from qr_scanner_service.py on the studio Mac and
 * records them against the matching matched_transcriptions row.
 *
 * Behaviour (mirrors the insert/update logic in qrConvert.py):
 *   - a row is identified by (time, date)
 *   - no matching row  -> INSERT a new record
 *   - matching row     -> UPDATE m_transcription and any camera files supplied
 *
 * Rebuilt 2026-08-24 after the original was deleted by `rm -rf qr` on 2026-08-19.
 * The original source could not be carved back off the disk, so the SQL shape is
 * taken from qrConvert.py and the live matched_transcriptions schema.
 *
 * The scanner's contract was confirmed against live requests on 2026-08-25; it
 * POSTs a JSON body of:
 *     {"camera":"L","filename":"L20260824_6288.wav","glosId":"9999",
 *      "type":"test","time":"09:31:36","date":"2026-08-24"}
 * Other field spellings are still accepted via pick(), and every raw payload is
 * logged to RAW_LOG. Anything that cannot be parsed confidently is rejected
 * WITHOUT touching the database.
 */

require_once __DIR__ . '/../mysql_config.php';

header('Content-Type: application/json');

define('RAW_LOG', __DIR__ . '/qrResultReceiver.log');

// zOg is NOT NULL. Supplied by the payload when present; this is the fallback
// used for QR-driven rows (the dominant value on existing label recordings).
define('DEFAULT_ZOG', 'labels');

/** Append the raw request so the true payload shape can be confirmed. */
function logRaw($note = '') {
    $entry = [
        'ts'      => date('c'),
        'note'    => $note,
        'ip'      => $_SERVER['REMOTE_ADDR']    ?? '',
        'ctype'   => $_SERVER['CONTENT_TYPE']   ?? '',
        'clen'    => $_SERVER['CONTENT_LENGTH'] ?? '',
        'post'    => $_POST,
        'raw'     => substr(file_get_contents('php://input'), 0, 2000),
    ];
    @file_put_contents(RAW_LOG, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/** First present, non-empty value among the given aliases. */
function pick(array $src, array $keys) {
    foreach ($keys as $k) {
        if (isset($src[$k]) && $src[$k] !== '') {
            return $src[$k];
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Method not allowed'], 405);
}

logRaw();

// Accept form-encoded POST, or a JSON body if the scanner sends one.
$in = $_POST;
if (empty($in)) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $in = $decoded;
    }
}

$transcription = pick($in, ['m_transcription', 'transcription', 'qr', 'qr_data',
                           'qr_code', 'qrcode', 'glosId', 'glos_id', 'result', 'data']);
$date = pick($in, ['date', 'datum', 'recording_date']);
$time = pick($in, ['time', 'tijd', 'time_str', 'recording_time']);
$zOg  = pick($in, ['zOg', 'zog', 'type', 'selectedType']) ?? DEFAULT_ZOG;

$files = [];
foreach (['m_file', 'l_file', 'r_file', 'a_file', 'b_file'] as $col) {
    $v = pick($in, [$col, str_replace('_file', '', $col), strtoupper($col)]);
    if ($v !== null) {
        $files[$col] = $v;
    }
}

// qr_scanner_service.py names the camera and the file separately
// (camera=L, filename=L20260824_6288.wav) instead of using a per-column key, so
// map that pair onto the right column - without this the file columns stay NULL
// and upload_post.php can never match the row to flip post_processed.
// Falls back to the filename's own prefix when only the filename is supplied.
$camera   = pick($in, ['camera', 'cam']);
$filename = pick($in, ['filename', 'file', 'wav_filename', 'wav']);
if ($filename !== null) {
    $letter = strtolower(substr(trim((string) ($camera !== null ? $camera : $filename)), 0, 1));
    if ($letter !== '' && strpos('lmrab', $letter) !== false) {
        $files[$letter . '_file'] = $filename;
    }
}

// A date+time is what identifies the row, and without a transcription there is
// nothing to record. Refuse rather than write a half-formed row.
if ($transcription === null || $date === null || $time === null) {
    logRaw('unparsed: missing transcription/date/time - no DB write');
    respond([
        'success' => false,
        'error'   => 'Could not identify transcription/date/time in payload',
        'hint'    => 'Raw payload logged to ' . RAW_LOG . ' for contract confirmation',
        'saw'     => array_keys($in),
    ], 400);
}

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    respond(['success' => false, 'error' => 'DB connect failed: ' . $conn->connect_error], 500);
}
$conn->set_charset('utf8');

// Identify the recording by (time, date), exactly as qrConvert.py does.
$stmt = $conn->prepare("SELECT id FROM matched_transcriptions WHERE time = ? AND date = ?");
$stmt->bind_param('ss', $time, $date);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing) {
    // No row for this date/time yet - insert one.
    $cols = ['m_transcription', 'l_transcription', 'r_transcription',
             'a_transcription', 'b_transcription', 'definitive_outcome',
             'added', 'zOg', 'time', 'date'];
    $vals = [$transcription, $transcription, $transcription,
             $transcription, $transcription, $transcription,
             '1', $zOg, $time, $date];

    foreach ($files as $col => $v) {
        $cols[] = $col;
        $vals[] = $v;
    }

    $sql = "INSERT INTO matched_transcriptions (" . implode(', ', $cols) . ") VALUES ("
         . implode(', ', array_fill(0, count($cols), '?')) . ")";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($vals)), ...$vals);
    $ok = $stmt->execute();
    $newId = $conn->insert_id;
    $stmt->close();
    $conn->close();

    respond([
        'success'         => (bool) $ok,
        'action'          => 'inserted',
        'id'              => $newId,
        'm_transcription' => $transcription,
        'date'            => $date,
        'time'            => $time,
    ]);
}

// Row exists for this date/time - update the transcription and any files given.
$set    = [];
$params = [];
foreach (['m_transcription', 'l_transcription', 'r_transcription',
          'a_transcription', 'b_transcription', 'definitive_outcome'] as $col) {
    $set[]    = "`$col` = ?";
    $params[] = $transcription;
}
foreach ($files as $col => $v) {
    $set[]    = "`$col` = ?";
    $params[] = $v;
}
$params[] = $existing['id'];

$sql  = "UPDATE matched_transcriptions SET " . implode(', ', $set) . " WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param(str_repeat('s', count($params) - 1) . 'i', ...$params);
$ok   = $stmt->execute();
$rows = $stmt->affected_rows;
$stmt->close();
$conn->close();

respond([
    'success'         => (bool) $ok,
    'action'          => 'updated',
    'id'              => (int) $existing['id'],
    'rows_updated'    => $rows,
    'm_transcription' => $transcription,
    'date'            => $date,
    'time'            => $time,
]);
