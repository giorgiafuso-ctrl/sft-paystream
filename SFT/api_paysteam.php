<?php
error_reporting(E_ALL); ini_set('display_errors', 1);

file_put_contents(
    '/tmp/pay_log.txt',
    date('H:i:s') . " [1] api_paysteam.php - prezzo=" . ($_POST['prezzo'] ?? 'N/A') .
    " modifica_id=" . ($_POST['modifica_id'] ?? '0') . "
",
    FILE_APPEND
);

ob_start();
session_name('SFT_SESSION'); session_start();
include('connessione.php');
include('controllo_orario.php');

if (!isset($_SESSION['sft_id'])) {
    header("Location: /gio.fuso/SFT/login.php");
    exit();
}

if (!isset($_POST['posto'])) {
    header("Location: /gio.fuso/SFT/index.php");
    exit();
}

$posto_raw   = $_POST['posto'] ?? '0|';
$posto_parts = explode('|', $posto_raw);
$prezzo      = round((float)($_POST['prezzo'] ?? 0), 2);

$id_corsa    = (int)($_POST['idcorsa']     ?? 0);
$staz_p      = $_POST['stazione_p']        ?? '';
$staz_a      = $_POST['stazione_a']        ?? '';
$estensione  = (int)($_POST['estensione']  ?? 0);
$id_bgl_old  = (int)($_POST['idbiglietto'] ?? 0);
$modifica_id = (int)($_POST['modifica_id'] ?? 0);
$posto_num   = (int)($posto_parts[0] ?? 0);
$cambio_data = (int)($_POST['cambio_data'] ?? 0);
$matricola   = $posto_parts[1] ?? '';
$id_utente   = (int)$_SESSION['sft_id'];

if ($id_corsa > 0 && $staz_p !== '') {
    $chk = puo_prenotare($con, $id_corsa, $staz_p, 15);
    if (!$chk['ok']) {
        file_put_contents('/tmp/pay_log.txt',
            date('H:i:s') . " [BLOCCO 15min] motivo=" . $chk['motivo'] . "
",
            FILE_APPEND);
        header("Location: /gio.fuso/SFT/index.php?msg=prenotazione_chiusa&reason=" . urlencode($chk['motivo']));
        exit();
    }
}

if ($id_corsa > 0 && $posto_num > 0 && $matricola !== '' && $staz_p !== '' && $staz_a !== '') {

    // progressivo della stazione P e A della tratta richiesta
    $stmt = $con->prepare(
        "SELECT F.PROGRESSIVO FROM SFT_FERMATA F
         JOIN SFT_STAZIONE S ON S.IDSTAZIONE = F.IDSTAZIONE
         WHERE F.IDCORSA = ? AND S.NOME = ?"
    );
    $stmt->bind_param('is', $id_corsa, $staz_p);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $pp_new = $r ? (int)$r['PROGRESSIVO'] : -1;
    $stmt->close();

    $stmt = $con->prepare(
        "SELECT F.PROGRESSIVO FROM SFT_FERMATA F
         JOIN SFT_STAZIONE S ON S.IDSTAZIONE = F.IDSTAZIONE
         WHERE F.IDCORSA = ? AND S.NOME = ?"
    );
    $stmt->bind_param('is', $id_corsa, $staz_a);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $pa_new = $r ? (int)$r['PROGRESSIVO'] : -1;
    $stmt->close();

    if ($pp_new < 0 || $pa_new < 0) {
        header("Location: /gio.fuso/SFT/index.php?msg=errore_tratta");
        exit();
    }
    $min_new = min($pp_new, $pa_new);
    $max_new = max($pp_new, $pa_new);

    // tutti i biglietti PAGATI sullo stesso (corsa,posto,matricola)
    $sql_ov = "SELECT B.IDBIGLIETTO, FP.PROGRESSIVO PP, FA.PROGRESSIVO PA
               FROM SFT_BIGLIETTO B
               JOIN SFT_FERMATA FP ON FP.IDCORSA=B.IDCORSA AND FP.IDSTAZIONE=B.IDSTAZIONEP
               JOIN SFT_FERMATA FA ON FA.IDCORSA=B.IDCORSA AND FA.IDSTAZIONE=B.IDSTAZIONEA
               WHERE B.IDCORSA            = ?
                 AND B.IDPOSTO            = ?
                 AND B.MATRICOLAMATERIALE = ?
                 AND B.STATOPAGAMENTO     = 'Pagato'";

    $stmt = $con->prepare($sql_ov);
    $stmt->bind_param('iis', $id_corsa, $posto_num, $matricola);
    $stmt->execute();
    $ro = $stmt->get_result();

    $occupato = false;
    while ($row = $ro->fetch_assoc()) {
        $idb = (int)$row['IDBIGLIETTO'];
        // escludo il biglietto che sto estendendo o modificando
        if (($estensione  && $idb === $id_bgl_old) ||
            ($modifica_id > 0 && $idb === $modifica_id) ||
            ($cambio_data && $idb === $id_bgl_old)) {        
            continue;
        }
        $mo = min((int)$row['PP'], (int)$row['PA']);
        $Mo = max((int)$row['PP'], (int)$row['PA']);
        if ($max_new > $mo && $min_new < $Mo) { $occupato = true; break; }
    }
    $stmt->close();

    if ($occupato) {
        file_put_contents('/tmp/pay_log.txt',
            date('H:i:s') . " [EARLY REJECT] posto $posto_num/$matricola occupato su corsa $id_corsa
",
            FILE_APPEND);
        $url_back = ($modifica_id > 0)
            ? "/gio.fuso/SFT/biglietti_riepilogo.php?msg=errore_posto"
            : "/gio.fuso/SFT/acquista.php?partenza=" . urlencode($staz_p) .
              "&arrivo=" . urlencode($staz_a) .
              "&data="    . urlencode(date('Y-m-d')) . "&msg=errore_posto";
        header("Location: $url_back");
        exit();
    }
}

$id_transazione = 'SFT-PAY-' . time() . '-' . rand(1000, 9999);

$_SESSION['pending_ticket'] = [
    'idcorsa'        => $id_corsa,
    'prezzo'         => $prezzo,
    'posto'          => $posto_num,
    'matricola'      => $matricola,
    'stazione_p'     => $staz_p,
    'stazione_a'     => $staz_a,
    'estensione'     => $estensione,
    'idbiglietto'    => $id_bgl_old,
    'modifica_id'    => $modifica_id,
    'is_cambio_posto'=> ($modifica_id > 0 && $prezzo == 0),
    'id_utente_sft'  => $id_utente,
    'cambio_data'    => (bool)$cambio_data,       
    'id_transazione' => $id_transazione,
];

if ($prezzo <= 0) {
    header("Location: /gio.fuso/SFT/risposta_pay.php?esito=OK&id_transazione=" . urlencode($id_transazione));
    exit();
}

$id_esercente      = 1;
$url_inviante      = 'https://webstudenti.unimarconi.it/gio.fuso/SFT/';
$url_risposta      = 'https://webstudenti.unimarconi.it/gio.fuso/SFT/risposta_pay.php';
$descrizione_testo = 'Biglietto SFT - ' . $staz_p . ' -> ' . $staz_a;

$payload = [
    'url_inviante'   => $url_inviante,
    'url_risposta'   => $url_risposta,
    'id_esercente'   => $id_esercente,
    'id_transazione' => $id_transazione,
    'descrizione'    => $descrizione_testo,
    'prezzo'         => $prezzo,
];

$target_url = 'http://127.0.0.1/gio.fuso/PAY/rice_richiesta.php';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $target_url,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
]);

$response   = curl_exec($ch);
$http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
$curl_errno = curl_errno($ch);
curl_close($ch);

file_put_contents(
    '/tmp/pay_log.txt',
    date('H:i:s') . " response=" . trim((string)$response) .
    " http=$http_code errno=$curl_errno err=$curl_error
",
    FILE_APPEND
);

if ($response === false || $http_code >= 400) {
    die("<pre>Errore creazione transazione PAY
HTTP: $http_code
cURL errno: $curl_errno
cURL error: " .
        htmlspecialchars($curl_error) . "
Response: " . htmlspecialchars((string)$response) . "</pre>");
}
$json = json_decode($response, true);

file_put_contents(
    '/tmp/pay_log.txt',
    date('H:i:s') . " [1b] api_paysteam decoded_json=" . json_encode($json) . "
",
    FILE_APPEND
);

if (!is_array($json)) {
    die("<pre>Risposta PAY non valida
Response raw: " . htmlspecialchars((string)$response) . "</pre>");
}

$ack = $json['ack'] ?? $json['esito'] ?? 'KO';

if ($ack === 'OK') {
    ob_end_clean();
    if (!empty($json['url_pagamento'])) {
        header("Location: " . $json['url_pagamento']);
    } else {
        header("Location: /gio.fuso/PAY/pay.php?id_transazione=" . urlencode($id_transazione));
    }
    exit();
}

die("<pre>PAY ha rifiutato la richiesta
Response: " .
    htmlspecialchars(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "</pre>");

