<?php
file_put_contents('/tmp/pay_log.txt', 
    date('H:i:s') . " [2] pay.php - id_trans=" . ($_GET['id_transazione'] ?? 'N/A') . "\n", 
    FILE_APPEND);


ob_start();
session_name('PAY_SESSION');
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/gio.fuso/PAY/connessione.php';

/* Debug 
if (isset($_GET['debug'])) {
    echo '<pre>';
    print_r(['GET' => $_GET, 'SESSION' => $_SESSION]);
    echo '</pre>';
    exit();
}
*/
$id_transazione = trim($_GET['id_transazione'] ?? '');

// Fallback dal token
if ($id_transazione === '' && !empty($_GET['token'])) {
    $decoded = json_decode(base64_decode($_GET['token']), true);
    if ($decoded && !empty($decoded['id_transesterna'])) {
        $id_transazione = $decoded['id_transesterna'];
    }
}

if ($id_transazione === '') {
    http_response_code(400);
    die('<pre>ERRORE: id_transazione mancante</pre>');
}

$id_transazione = $con->real_escape_string($id_transazione);

$res_pay = $con->query("
    SELECT t.*, u.NOME AS NOME_ESERCENTE
    FROM PAY_TRANSAZIONE t
    JOIN PAY_UTENTE u ON t.IDESERCENTE = u.IDUTENTE
    WHERE t.IDTRANSESTERNA = '$id_transazione'
      AND t.STATO = 'PENDING'
    LIMIT 1
");

if (!$res_pay || $res_pay->num_rows === 0) {
    die("Transazione non trovata o già processata.");
}

$pay_row  = $res_pay->fetch_assoc();
$pay_data = [
    'id_esercente'    => intval($pay_row['IDESERCENTE']),
    'nome_esercente'  => $pay_row['NOME_ESERCENTE'],
    'descrizione'     => $pay_row['DESCRIZIONE'],
    'prezzo'          => floatval($pay_row['IMPORTO']),
    'url_risposta'    => $pay_row['URLOUT'],
    'url_inviante'    => $pay_row['URLIN'],
    'id_transesterna' => $pay_row['IDTRANSESTERNA'],
];

// Salva i dati della transazione in sessione
$_SESSION['pay_pending'] = $pay_data;

$token = base64_encode(json_encode($pay_data));

// Se l'utente è già loggato su PAY, va a conferma senza rifare il login
$utente_loggato = (
    isset($_SESSION['pay_id']) &&
    isset($_SESSION['pay_tipo']) &&
    $_SESSION['pay_tipo'] === 'CONSUMATORE'
);

// Se arriva da SFT (id_transazione nel GET) → forza login
$da_sft = !empty($_GET['id_transazione']);

if ($utente_loggato && !$da_sft) {
    // Torna dalla ricarica va diretto a conferma
    header('Location: /gio.fuso/PAY/conferma.php?token=' . urlencode($token));
} else {
    // Nuovo pagamento da SFT , forza login
    unset($_SESSION['pay_id']);
    unset($_SESSION['pay_nome']);
    unset($_SESSION['pay_tipo']);
    header('Location: /gio.fuso/PAY/login.php?redirect=pay&token=' . urlencode($token));
}
exit();