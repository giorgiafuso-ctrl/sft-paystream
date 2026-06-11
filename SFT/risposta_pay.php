<?php
file_put_contents('/tmp/pay_log.txt',
    date('H:i:s') . " [M2M-CALLBACK] risposta_pay.php - method=" . $_SERVER['REQUEST_METHOD'] .
    " esito=" . ($_POST['esito'] ?? $_GET['esito'] ?? 'N/A') .
    " id_transazione=" . ($_POST['id_transazione'] ?? $_GET['id_transazione'] ?? 'N/A') . "
",
    FILE_APPEND);

  // M2M (POST): risponde subito al server PAY
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $esito          = $_POST['esito'] ?? '';
    $id_transazione = $_POST['id_transazione'] ?? '';
    header('Content-Type: application/json');
    echo json_encode([
    'url_inviante'   => 'https://webstudenti.unimarconi.it/gio.fuso/SFT/',
    'received'       => true,
    'esito'          => $esito,
    'id_transazione' => $id_transazione
]);
    exit();
}
ob_start();
session_name('SFT_SESSION');
session_start();
include('connessione.php');
include('controllo_orario.php');

$esito          = $_GET['esito']          ?? 'KO';
$id_trans_get   = $_GET['id_transazione'] ?? '';

if (!isset($_SESSION['pending_ticket']) && $id_trans_get !== '') {

    // accedo al DB di PAY per leggere lo stato della transazione
    $pay_con = @new mysqli('localhost', 'gio.fuso', 'O1UCL8UW', 'gio_fuso');
    if (!$pay_con->connect_error) {
        $stmt = $pay_con->prepare(
            "SELECT STATO FROM PAY_TRANSAZIONE WHERE IDTRANSESTERNA = ? LIMIT 1"
        );
        $stmt->bind_param('s', $id_trans_get);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $pay_con->close();

        if ($r) {
            $stato = $r['STATO'] ?? '';
            if ($stato === 'COMPLETED' || $stato === 'PAID' || $stato === 'OK') {
                // pagamento già processato: era F5 dopo successo
                header("Location: /gio.fuso/SFT/biglietti_riepilogo.php?msg=pagamento_ok");
                exit();
            }
            if ($stato === 'FAILED' || $stato === 'CANCELED' || $stato === 'CANCELLED') {
                header("Location: /gio.fuso/SFT/index.php?msg=pagamento_annullato");
                exit();
            }
            // stato PENDING senza pending_ticket: probabile abbandono
            header("Location: /gio.fuso/SFT/index.php?msg=pagamento_annullato");
            exit();
        }
    }
    // fallback se non leggo PAY
    header("Location: /gio.fuso/SFT/index.php?msg=pagamento_annullato");
    exit();
}

if ($esito === 'OK' && isset($_SESSION['pending_ticket'])) {

    $t               = $_SESSION['pending_ticket'];
    $id_utente       = (int)($_SESSION['sft_id'] ?? 0);
    $id_corsa        = (int)$t['idcorsa'];
    $prezzo          = (float)$t['prezzo'];
    $posto           = (int)$t['posto'];
    $matricola       = $t['matricola'];
    $staz_p          = $t['stazione_p'];
    $staz_a          = $t['stazione_a'];
    $estensione      = (int)$t['estensione'];
    $id_bgl_old      = (int)$t['idbiglietto'];
    $modifica_id     = (int)($t['modifica_id']     ?? 0);
    $is_cambio_posto = (bool)($t['is_cambio_posto'] ?? false);
    $is_cambio_data  = (bool)($t['cambio_data']     ?? false);

    $chk = puo_prenotare($con, $id_corsa, $staz_p, 0);
    if (!$chk['ok']) {
        file_put_contents('/tmp/pay_log.txt',
            date('H:i:s') . " [risposta_pay BLOCCO] motivo=" . $chk['motivo'] . "
",
            FILE_APPEND);
        unset($_SESSION['pending_ticket']);
        header("Location: /gio.fuso/SFT/index.php?msg=prenotazione_chiusa&reason=" . urlencode($chk['motivo']));
        exit();
    }

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
    $min_new = min($pp_new, $pa_new);
    $max_new = max($pp_new, $pa_new);


    $stmt = $con->prepare("SELECT IDSTAZIONE FROM SFT_STAZIONE WHERE NOME = ?");
    $stmt->bind_param('s', $staz_p);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $id_st_p = $r ? (int)$r['IDSTAZIONE'] : 0;
    $stmt->close();

    $stmt = $con->prepare("SELECT IDSTAZIONE FROM SFT_STAZIONE WHERE NOME = ?");
    $stmt->bind_param('s', $staz_a);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $id_st_a = $r ? (int)$r['IDSTAZIONE'] : 0;
    $stmt->close();

    $con->begin_transaction();
    try {

        $sql_lock = "SELECT B.IDBIGLIETTO, FP.PROGRESSIVO PP, FA.PROGRESSIVO PA
                     FROM SFT_BIGLIETTO B
                     JOIN SFT_FERMATA FP ON FP.IDCORSA=B.IDCORSA AND FP.IDSTAZIONE=B.IDSTAZIONEP
                     JOIN SFT_FERMATA FA ON FA.IDCORSA=B.IDCORSA AND FA.IDSTAZIONE=B.IDSTAZIONEA
                     WHERE B.IDCORSA            = ?
                       AND B.IDPOSTO            = ?
                       AND B.MATRICOLAMATERIALE = ?
                       AND B.STATOPAGAMENTO     = 'Pagato'
                     FOR UPDATE";
        $stmt = $con->prepare($sql_lock);
        $stmt->bind_param('iis', $id_corsa, $posto, $matricola);
        $stmt->execute();
        $rs = $stmt->get_result();

        $occupato = false;
        while ($row = $rs->fetch_assoc()) {
            $idb = (int)$row['IDBIGLIETTO'];
            // escludo il biglietto che sto estendendo o modificando (cambio posto)
            if ($estensione      && $idb === $id_bgl_old)  continue;
            if ($is_cambio_posto && $idb === $modifica_id) continue;
            if ($is_cambio_data  && $idb === $id_bgl_old)  continue;

            $mo = min((int)$row['PP'], (int)$row['PA']);
            $Mo = max((int)$row['PP'], (int)$row['PA']);
            if ($max_new > $mo && $min_new < $Mo) { $occupato = true; break; }
        }
        $stmt->close();

        if ($occupato) {
            $con->rollback();
            unset($_SESSION['pending_ticket']);
            file_put_contents('/tmp/pay_log.txt',
                date('H:i:s') . " [RACE CONFLICT] posto $posto/$matricola corsa $id_corsa
",
                FILE_APPEND);

            // Marco la transazione PAY come FAILED (rimborsare lato PAY)
            if ($id_trans_get !== '') {
                @include $_SERVER['DOCUMENT_ROOT'] . '/gio.fuso/PAY/connessione.php';
                if (isset($con) && $con) {
                    $stmt = $con->prepare(
                        "UPDATE PAY_TRANSAZIONE SET STATO='FAILED'
                         WHERE IDTRANSESTERNA = ? AND STATO = 'COMPLETED'"
                    );
                    $stmt->bind_param('s', $id_trans_get);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            header("Location: /gio.fuso/SFT/biglietti_riepilogo.php?msg=errore_posto");
            exit();
        }

        if ($is_cambio_posto && $modifica_id > 0) {
            $stmt = $con->prepare(
                "SELECT IDBIGLIETTO FROM SFT_BIGLIETTO
                 WHERE IDBIGLIETTO = ? AND CODUTENTE = ? FOR UPDATE"
            );
            $stmt->bind_param('ii', $modifica_id, $id_utente);
            $stmt->execute();
            $own = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$own) {
                $con->rollback();
                unset($_SESSION['pending_ticket']);
                header("Location: /gio.fuso/SFT/biglietti_riepilogo.php");
                exit();
            }

            $stmt = $con->prepare(
                "UPDATE SFT_BIGLIETTO
                 SET IDPOSTO = ?, MATRICOLAMATERIALE = ?
                 WHERE IDBIGLIETTO = ? AND CODUTENTE = ?"
            );
            $stmt->bind_param('isii', $posto, $matricola, $modifica_id, $id_utente);
            $stmt->execute();
            $stmt->close();

            $con->commit();

            $files = glob(__DIR__ . '/cache/*.json');
            foreach ($files as $f) { if (is_file($f)) @unlink($f); }
            unset($_SESSION['pending_ticket']);
            header("Location: /gio.fuso/SFT/biglietti_riepilogo.php?msg=cambio_ok");
            exit();
        }


        if ($is_cambio_data && $id_bgl_old > 0) {
            $stmt = $con->prepare(
                "SELECT IDBIGLIETTO FROM SFT_BIGLIETTO
                 WHERE IDBIGLIETTO = ? AND CODUTENTE = ? FOR UPDATE"
            );
            $stmt->bind_param('ii', $id_bgl_old, $id_utente);
            $stmt->execute();
            $own = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$own) {
                $con->rollback();
                unset($_SESSION['pending_ticket']);
                header("Location: /gio.fuso/SFT/biglietti_riepilogo.php");
                exit();
            }

            $stmt = $con->prepare(
                "UPDATE SFT_BIGLIETTO
                 SET IDCORSA=?, IDPOSTO=?, MATRICOLAMATERIALE=?, COSTO = COSTO + ?
                 WHERE IDBIGLIETTO=? AND CODUTENTE=?"
            );
            $stmt->bind_param('iisdii',
                $id_corsa, $posto, $matricola, $prezzo, $id_bgl_old, $id_utente);
            $stmt->execute();
            $stmt->close();

            $con->commit();
            $files = glob(__DIR__ . '/cache/*.json');
            foreach ($files as $f) { if (is_file($f)) @unlink($f); }
            unset($_SESSION['pending_ticket']);
            header("Location: /gio.fuso/SFT/biglietti_riepilogo.php?msg=cambio_data_ok");
            exit();
        }

        if ($estensione && $id_bgl_old > 0) {
            $stmt = $con->prepare(
                "UPDATE SFT_BIGLIETTO
                 SET IDSTAZIONEP = ?, IDSTAZIONEA = ?, COSTO = COSTO + ?
                 WHERE IDBIGLIETTO = ? AND CODUTENTE = ?"
            );
            $stmt->bind_param('iidii', $id_st_p, $id_st_a, $prezzo, $id_bgl_old, $id_utente);
            $stmt->execute();
            $stmt->close();

            $con->commit();

            $files = glob(__DIR__ . '/cache/*.json');
            foreach ($files as $f) { if (is_file($f)) @unlink($f); }
            unset($_SESSION['pending_ticket']);
            header("Location: /gio.fuso/SFT/biglietti_riepilogo.php?msg=pagamento_ok");
            exit();
        }

        $stmt = $con->prepare(
            "INSERT INTO SFT_BIGLIETTO
             (CODUTENTE, IDCORSA, IDPOSTO, MATRICOLAMATERIALE, COSTO,
              IDSTAZIONEP, IDSTAZIONEA, DATAEMISSIONE, STATOPAGAMENTO, METODOPAGAMENTO)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), 'Pagato', 'PaySteam')"
        );
        $stmt->bind_param('iiisdii',
            $id_utente, $id_corsa, $posto, $matricola, $prezzo, $id_st_p, $id_st_a);
        $stmt->execute();
        $stmt->close();

        $con->commit();

        $files = glob(__DIR__ . '/cache/*.json');
        foreach ($files as $f) { if (is_file($f)) @unlink($f); }
        unset($_SESSION['pending_ticket']);
        header("Location: /gio.fuso/SFT/biglietti_riepilogo.php?msg=pagamento_ok");
        exit();

    } catch (Throwable $e) {
        $con->rollback();
        file_put_contents('/tmp/pay_log.txt',
            date('H:i:s') . " [TX EXCEPTION] " . $e->getMessage() . "
",
            FILE_APPEND);
        unset($_SESSION['pending_ticket']);
        header("Location: /gio.fuso/SFT/biglietti_riepilogo.php?msg=errore_posto");
        exit();
    }

} else {
    if ($id_trans_get !== '') {
        @include $_SERVER['DOCUMENT_ROOT'] . '/gio.fuso/PAY/connessione.php';
        if (isset($con) && $con) {
            $stmt = $con->prepare(
                "UPDATE PAY_TRANSAZIONE SET STATO='FAILED'
                 WHERE IDTRANSESTERNA = ? AND STATO = 'PENDING'"
            );
            $stmt->bind_param('s', $id_trans_get);
            $stmt->execute();
            $stmt->close();
        }
    }
    unset($_SESSION['pending_ticket']);
    header("Location: /gio.fuso/SFT/index.php?msg=pagamento_annullato");
    exit();
}

