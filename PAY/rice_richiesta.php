<?php
ob_start();
session_name('PAY_SESSION');
session_start();
include('connessione.php');

file_put_contents(
    '/tmp/pay_log.txt',
    date('H:i:s') . " [M2M] rice_richiesta.php - POST=" . json_encode($_POST) . "\n",
    FILE_APPEND
);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['url_inviante' => '', 'id_transazione' => '', 'ack' => 'KO']);
    exit();
}

$url_inviante   = trim($_POST['url_inviante'] ?? '');
$url_risposta   = trim($_POST['url_risposta'] ?? '');
$id_esercente   = intval($_POST['id_esercente'] ?? 0);
$id_transazione = trim($_POST['id_transazione'] ?? '');
$descrizione    = trim($_POST['descrizione'] ?? '');
$prezzo         = floatval($_POST['prezzo'] ?? 0);

if (
    $url_inviante === '' ||
    $url_risposta === '' ||
    $id_esercente <= 0 ||
    $id_transazione === '' ||
    $descrizione === '' ||
    $prezzo <= 0
) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['url_inviante' => $url_inviante, 'id_transazione' => $id_transazione, 'ack' => 'KO']);
    exit();
}

// Sanitizzazione base
$url_in_safe   = $con->real_escape_string($url_inviante);
$url_out_safe  = $con->real_escape_string($url_risposta);
$trans_safe    = $con->real_escape_string($id_transazione);
$desc_safe     = $con->real_escape_string($descrizione);
$id_ese_safe   = intval($id_esercente);
$prezzo_safe   = floatval($prezzo);

// Verifica che l'esercente esista
$check_ese = $con->query("SELECT 1 FROM PAY_UTENTE WHERE IDUTENTE = $id_ese_safe AND TIPO = 'ESERCENTE' LIMIT 1");
if (!$check_ese || $check_ese->num_rows === 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['url_inviante' => $url_inviante, 'id_transazione' => $id_transazione, 'ack' => 'KO']);
    exit();
}

// no duplicati
$check = $con->query("SELECT 1 FROM PAY_TRANSAZIONE WHERE IDTRANSESTERNA = '$trans_safe' LIMIT 1");
if ($check && $check->num_rows > 0) {
    http_response_code(409);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['url_inviante' => $url_inviante, 'id_transazione' => $id_transazione, 'ack' => 'KO']);
}
// Inserimento transazione pending
$sql = "INSERT INTO PAY_TRANSAZIONE
        (IMPORTO, DATAORA, STATO, DESCRIZIONE, URLIN, URLOUT, IDTRANSESTERNA, IDCONSUMATORE, IDESERCENTE)
        VALUES
        ($prezzo_safe, NOW(), 'PENDING', '$desc_safe', '$url_in_safe', '$url_out_safe', '$trans_safe', NULL, $id_ese_safe)";

if ($con->query($sql)) {
    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
    'url_inviante'   => $url_inviante,
    'id_transazione' => $id_transazione,
    'ack'            => 'OK',
    'esito'          => 'OK'
]);



} else {
    file_put_contents(
        '/tmp/pay_log.txt',
        date('H:i:s') . " [M2M-ERR] rice_richiesta.php - SQL=" . $con->error . "\n",
        FILE_APPEND
    );
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
    'url_inviante'   => $url_inviante,
    'id_transazione' => $id_transazione,
    'ack'            => 'KO',
    'esito'          => 'KO'
]);
}
