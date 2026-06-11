<?php
session_name('SFT_SESSION'); session_start();
include('connessione.php');
include('controllo_orario.php'); 

if (!isset($_SESSION['sft_id'])) { header("Location: login.php"); exit(); }
$id_utente = (int)$_SESSION['sft_id'];

if (($_POST['azione'] ?? '') === 'conferma_cambio') {
    $id_big        = (int)($_POST['id_biglietto']   ?? 0);
    $id_corsa_new  = (int)($_POST['id_corsa_nuova'] ?? 0);
    $posto_raw     = $_POST['id_posto_nuovo'] ?? '';
    $posto_parts   = explode('|', $posto_raw);
    $id_posto_new  = (int)($posto_parts[0] ?? 0);
    $matricola_new = $posto_parts[1] ?? '';

    $stmt = $con->prepare(
        "SELECT B.IDCORSA AS IDCORSA_OLD, B.COSTO AS COSTO_OLD,
                B.IDSTAZIONEP, B.IDSTAZIONEA,
                S1.NOME AS NOME_P, S2.NOME AS NOME_A,
                S1.KMPROGRESSIVO AS KM_P, S2.KMPROGRESSIVO AS KM_A
         FROM SFT_BIGLIETTO B
         JOIN SFT_STAZIONE S1 ON S1.IDSTAZIONE = B.IDSTAZIONEP
         JOIN SFT_STAZIONE S2 ON S2.IDSTAZIONE = B.IDSTAZIONEA
         WHERE B.IDBIGLIETTO = ? AND B.CODUTENTE = ?"
    );
    $stmt->bind_param('ii', $id_big, $id_utente);
    $stmt->execute();
    $rowB = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$rowB) { header("Location: biglietti_riepilogo.php"); exit(); }

    $id_corsa_old = (int)$rowB['IDCORSA_OLD'];
    $costo_old    = (float)$rowB['COSTO_OLD'];
    $nome_p_old   = $rowB['NOME_P'];
    $nome_a_old   = $rowB['NOME_A'];
    $id_staz_p    = (int)$rowB['IDSTAZIONEP'];
    $id_staz_a    = (int)$rowB['IDSTAZIONEA'];
    $distanza     = abs((float)$rowB['KM_A'] - (float)$rowB['KM_P']);

    $ok_old = puo_prenotare($con, $id_corsa_old, $nome_p_old, 15);
    if (!$ok_old['ok']) {
        header("Location: biglietti_riepilogo.php?msg=cambio_data_non_consentito&reason=" . urlencode($ok_old['motivo']));
        exit();
    }
    $ok_new = puo_prenotare($con, $id_corsa_new, $nome_p_old, 15);
    if (!$ok_new['ok']) {
        header("Location: cambio_data.php?id=$id_big&err=" . urlencode($ok_new['motivo']));
        exit();
    }

    $qp = $con->query("SELECT PROGRESSIVO FROM SFT_FERMATA WHERE IDCORSA=$id_corsa_new AND IDSTAZIONE=$id_staz_p");
    $qa = $con->query("SELECT PROGRESSIVO FROM SFT_FERMATA WHERE IDCORSA=$id_corsa_new AND IDSTAZIONE=$id_staz_a");
    $prog_p  = ($qp && $qp->num_rows) ? (int)$qp->fetch_assoc()['PROGRESSIVO'] : 0;
    $prog_a  = ($qa && $qa->num_rows) ? (int)$qa->fetch_assoc()['PROGRESSIVO'] : 0;
    $min_new = min($prog_p, $prog_a);
    $max_new = max($prog_p, $prog_a);

    $occupato = false;
    $mat_esc  = $con->real_escape_string($matricola_new);
    $sql_ov = "SELECT FP.PROGRESSIVO PP, FA.PROGRESSIVO PA
               FROM SFT_BIGLIETTO B
               JOIN SFT_FERMATA FP ON FP.IDCORSA=B.IDCORSA AND FP.IDSTAZIONE=B.IDSTAZIONEP
               JOIN SFT_FERMATA FA ON FA.IDCORSA=B.IDCORSA AND FA.IDSTAZIONE=B.IDSTAZIONEA
               WHERE B.IDCORSA=$id_corsa_new
                 AND B.IDPOSTO=$id_posto_new
                 AND B.MATRICOLAMATERIALE='$mat_esc'
                 AND B.STATOPAGAMENTO='Pagato'
                 AND B.IDBIGLIETTO <> $id_big";
    if ($ro = $con->query($sql_ov)) {
        while ($r = $ro->fetch_assoc()) {
            $mo = min((int)$r['PP'], (int)$r['PA']);
            $Mo = max((int)$r['PP'], (int)$r['PA']);
            if ($max_new > $mo && $min_new < $Mo) { $occupato = true; break; }
        }
    }
    if ($occupato) {
        header("Location: cambio_data.php?id=$id_big&err=posto_occupato");
        exit();
    }

    $stmt = $con->prepare(
        "SELECT (SELECT COALESCE(SUM(MR.COSTOKM),0)
                 FROM SFT_COMPOSTO COMP
                 JOIN SFT_MATERIALEROTABILE MR ON COMP.MATRICOLAMAT = MR.MATRICOLA
                 WHERE COMP.IDCONVOGLIO = C.IDCONVOGLIO) AS COSTO_MAT
         FROM SFT_CORSA C WHERE C.IDCORSA = ?"
    );
    $stmt->bind_param('i', $id_corsa_new);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $tariffa_new = 0.20 + (float)($r['COSTO_MAT'] ?? 0);
    $prezzo_new  = round($distanza * $tariffa_new, 2);
    $delta       = round($prezzo_new - $costo_old, 2);

    file_put_contents('/tmp/pay_log.txt',
        date('H:i:s') . " [CAMBIO_DATA] big=$id_big old=$costo_old new=$prezzo_new delta=$delta\n",
        FILE_APPEND);


    if (abs($delta) < 0.01) {
        $stmt = $con->prepare(
            "UPDATE SFT_BIGLIETTO
             SET IDCORSA=?, IDPOSTO=?, MATRICOLAMATERIALE=?
             WHERE IDBIGLIETTO=? AND CODUTENTE=?"
        );
        $stmt->bind_param('iisii', $id_corsa_new, $id_posto_new, $matricola_new, $id_big, $id_utente);
        $stmt->execute();
        $stmt->close();
        header("Location: biglietti_riepilogo.php?msg=cambio_data_ok");
        exit();
    }


    if ($delta > 0) {
        ?>
        <!DOCTYPE html><html><head><meta charset="UTF-8"><title>Reindirizzamento al pagamento…</title></head>
        <body onload="document.forms[0].submit()" style="font-family:sans-serif;text-align:center;padding:60px;">
            <p>Reindirizzamento al gateway di pagamento per il delta tariffario di <strong><?= number_format($delta,2) ?> €</strong>…</p>
            <form method="POST" action="api_paysteam.php">
                <input type="hidden" name="cambio_data"  value="1">
                <input type="hidden" name="idcorsa"      value="<?= $id_corsa_new ?>">
                <input type="hidden" name="idbiglietto"  value="<?= $id_big ?>">
                <input type="hidden" name="prezzo"       value="<?= $delta ?>">
                <input type="hidden" name="posto"        value="<?= $id_posto_new ?>|<?= htmlspecialchars($matricola_new, ENT_QUOTES) ?>">
                <input type="hidden" name="stazione_p"   value="<?= htmlspecialchars($nome_p_old, ENT_QUOTES) ?>">
                <input type="hidden" name="stazione_a"   value="<?= htmlspecialchars($nome_a_old, ENT_QUOTES) ?>">
                <input type="hidden" name="estensione"   value="0">
                <input type="hidden" name="modifica_id"  value="0">
                <noscript><button type="submit">Continua</button></noscript>
            </form>
        </body></html>
        <?php
        exit();
    }

    $rimborso = abs($delta);

    $con->begin_transaction();
    try {
        $stmt = $con->prepare(
            "UPDATE SFT_BIGLIETTO
             SET IDCORSA=?, IDPOSTO=?, MATRICOLAMATERIALE=?, COSTO = COSTO + ?
             WHERE IDBIGLIETTO=? AND CODUTENTE=?"
        );
        $stmt->bind_param('iisdii', $id_corsa_new, $id_posto_new, $matricola_new, $delta, $id_big, $id_utente);
        $stmt->execute();
        $stmt->close();

        // Inserisco la riga di rimborso nel DB di PAY
        $pay_con = @new mysqli('localhost', 'gio.fuso', 'O1UCL8UW', 'gio_fuso');
        if ($pay_con->connect_error) throw new Exception('PAY DB connect failed');

        $idtrans_ext = 'NOTIFY-CDATA-' . $id_big . '-' . time();
        $descrizione = "Rimborso cambio data biglietto #$id_big [SFT_UTENTE:$id_utente]";

        $stmt = $pay_con->prepare(
            "INSERT INTO PAY_TRANSAZIONE
             (IDTRANSESTERNA, IMPORTO, STATO, DATAORA, DESCRIZIONE)
             VALUES (?, ?, 'REFUNDED', NOW(), ?)"
        );
        $stmt->bind_param('sds', $idtrans_ext, $rimborso, $descrizione);
        if (!$stmt->execute()) {
            file_put_contents('/tmp/pay_log.txt',
                date('H:i:s') . " [CAMBIO_DATA REFUND INSERT FAIL] " . $stmt->error . "\n",
                FILE_APPEND);
            throw new Exception('refund insert failed');
        }
        $stmt->close();
        $pay_con->close();

        $con->commit();
        header("Location: biglietti_riepilogo.php?msg=cambio_data_ok");
        exit();

    } catch (Throwable $e) {
        $con->rollback();
        file_put_contents('/tmp/pay_log.txt',
            date('H:i:s') . " [CAMBIO_DATA EXCEPTION] " . $e->getMessage() . "\n",
            FILE_APPEND);
        header("Location: cambio_data.php?id=$id_big&err=rimborso_fallito");
        exit();
    }
}


$id_biglietto = (int)($_GET['id'] ?? 0);
$sql = "SELECT B.IDBIGLIETTO, B.IDCORSA, B.IDPOSTO, B.MATRICOLAMATERIALE,
               B.IDSTAZIONEP, B.IDSTAZIONEA,
               C.DATA AS DATA_ORIG, C.ORA AS ORA_ORIG, C.DIREZIONE,
               CO.NOME AS NOME_TRENO,
               S1.NOME AS NOME_P, S2.NOME AS NOME_A,
               COALESCE(FP.ORAP, C.ORA) AS ORA_PART_ORIG
        FROM SFT_BIGLIETTO B
        JOIN SFT_CORSA      C  ON B.IDCORSA = C.IDCORSA
        JOIN SFT_CONVOGLIO  CO ON C.IDCONVOGLIO = CO.IDCONVOGLIO
        JOIN SFT_STAZIONE   S1 ON S1.IDSTAZIONE = B.IDSTAZIONEP
        JOIN SFT_STAZIONE   S2 ON S2.IDSTAZIONE = B.IDSTAZIONEA
        LEFT JOIN SFT_FERMATA FP ON FP.IDCORSA = B.IDCORSA AND FP.IDSTAZIONE = B.IDSTAZIONEP
        WHERE B.IDBIGLIETTO = $id_biglietto AND B.CODUTENTE = $id_utente";
$bigl = $con->query($sql)->fetch_assoc();
if (!$bigl) { header("Location: biglietti_riepilogo.php"); exit(); }

$ok_entry = puo_prenotare($con, (int)$bigl['IDCORSA'], $bigl['NOME_P'], 15);
if (!$ok_entry['ok']) {
    header("Location: biglietti_riepilogo.php?msg=cambio_data_non_consentito&reason=" . urlencode($ok_entry['motivo']));
    exit();
}

$direzione   = $bigl['DIREZIONE'];
$id_p        = (int)$bigl['IDSTAZIONEP'];
$id_a        = (int)$bigl['IDSTAZIONEA'];
$data_scelta = $_GET['data']  ?? date('Y-m-d', strtotime('+1 day'));
$corsa_sel   = (int)($_GET['corsa'] ?? 0);


$sql_corse = "
    SELECT C.IDCORSA, C.DATA, C.ORA, CO.NOME AS NOME_TRENO,
           F1.ORAP AS ORA_P, F2.ORAA AS ORA_A
    FROM SFT_CORSA C
    JOIN SFT_CONVOGLIO CO ON C.IDCONVOGLIO = CO.IDCONVOGLIO
    JOIN SFT_FERMATA F1  ON F1.IDCORSA = C.IDCORSA AND F1.IDSTAZIONE = $id_p
    JOIN SFT_FERMATA F2  ON F2.IDCORSA = C.IDCORSA AND F2.IDSTAZIONE = $id_a
    WHERE C.DATA = '" . $con->real_escape_string($data_scelta) . "'
      AND C.DIREZIONE = '" . $con->real_escape_string($direzione) . "'
      AND C.STATO NOT IN ('Annullata','Conclusa')
      AND C.IDCORSA <> " . (int)$bigl['IDCORSA'] . "
      AND F1.PROGRESSIVO < F2.PROGRESSIVO
    ORDER BY C.ORA ASC";
$corse_disp = [];
if ($r = $con->query($sql_corse)) $corse_disp = $r->fetch_all(MYSQLI_ASSOC);

// Filtro 15 min (solo per corse di oggi)
$corse_disp = array_values(array_filter($corse_disp, function($cd) use ($con, $bigl) {
    $r = puo_prenotare($con, (int)$cd['IDCORSA'], $bigl['NOME_P'], 15);
    return $r['ok'];
}));

foreach ($corse_disp as &$cd) {
    $rr = $con->query("SELECT COUNT(*) AS n FROM SFT_BIGLIETTO WHERE IDCORSA = " . (int)$cd['IDCORSA']);
    $cd['POSTI_OCC'] = (int)$rr->fetch_assoc()['n'];
}
unset($cd);

$carrozze = [];
$posti_occupati = [];

if ($corsa_sel > 0) {
    $ok_sel = puo_prenotare($con, $corsa_sel, $bigl['NOME_P'], 15);
    if (!$ok_sel['ok']) {
        header("Location: cambio_data.php?id=$id_biglietto&data=" . urlencode($data_scelta) . "&err=" . urlencode($ok_sel['motivo']));
        exit();
    }

    $qp = $con->query("SELECT PROGRESSIVO FROM SFT_FERMATA
                       WHERE IDCORSA=$corsa_sel AND IDSTAZIONE=$id_p");
    $qa = $con->query("SELECT PROGRESSIVO FROM SFT_FERMATA
                       WHERE IDCORSA=$corsa_sel AND IDSTAZIONE=$id_a");
    $prog_p = ($qp && $qp->num_rows) ? (int)$qp->fetch_assoc()['PROGRESSIVO'] : 0;
    $prog_a = ($qa && $qa->num_rows) ? (int)$qa->fetch_assoc()['PROGRESSIVO'] : 0;
    $min_new = min($prog_p, $prog_a);
    $max_new = max($prog_p, $prog_a);

    $sql_posti_reali = "SELECT P.IDPOSTO, P.MATRICOLAMATERIALE, MR.NOME AS NOME_CARROZZA, MR.TIPO
        FROM SFT_POSTO P
        JOIN SFT_MATERIALEROTABILE MR ON P.MATRICOLAMATERIALE = MR.MATRICOLA
        JOIN SFT_COMPOSTO COMP        ON P.MATRICOLAMATERIALE = COMP.MATRICOLAMAT
        JOIN SFT_CORSA C              ON C.IDCONVOGLIO        = COMP.IDCONVOGLIO
        WHERE C.IDCORSA = $corsa_sel
          AND MR.CAPACITA_POSTO > 0
        ORDER BY P.MATRICOLAMATERIALE, P.IDPOSTO ASC";
    $res_reali = $con->query($sql_posti_reali);
    if ($res_reali) {
        while ($row = $res_reali->fetch_assoc()) {
            $mat = $row['MATRICOLAMATERIALE'];
            if (!isset($carrozze[$mat])) {
                $carrozze[$mat] = [
                    'nome'  => $row['NOME_CARROZZA'],
                    'tipo'  => $row['TIPO'],
                    'posti' => []
                ];
            }
            $carrozze[$mat]['posti'][] = $row['IDPOSTO'];
        }
    }

    // Posti occupati per overlap sulla nuova corsa
    if ($min_new > 0 && $max_new > 0 && $min_new !== $max_new) {
        $sql_b = "SELECT B.IDBIGLIETTO, B.IDPOSTO, B.MATRICOLAMATERIALE,
                         FP.PROGRESSIVO AS PP, FA.PROGRESSIVO AS PA
                  FROM SFT_BIGLIETTO B
                  JOIN SFT_FERMATA FP ON FP.IDCORSA=B.IDCORSA AND FP.IDSTAZIONE=B.IDSTAZIONEP
                  JOIN SFT_FERMATA FA ON FA.IDCORSA=B.IDCORSA AND FA.IDSTAZIONE=B.IDSTAZIONEA
                  WHERE B.IDCORSA = $corsa_sel";
        $rb = $con->query($sql_b);
        if ($rb) {
            while ($row = $rb->fetch_assoc()) {
                if ((int)$row['IDBIGLIETTO'] === $id_biglietto) continue;
                $mo = min((int)$row['PP'], (int)$row['PA']);
                $Mo = max((int)$row['PP'], (int)$row['PA']);
                if ($max_new > $mo && $min_new < $Mo) {
                    $posti_occupati[$row['IDPOSTO'] . '_' . $row['MATRICOLAMATERIALE']] = true;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>SFT - Cambio Data Viaggio</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<style>
    body { background:#f5f7fa; }
    .corsa-card { border:1px solid #dbe7ff; border-radius:14px; padding:16px 20px; margin-bottom:12px; background:#fff; transition:all .2s; }
    .corsa-card:hover { transform:translateY(-2px); box-shadow:0 10px 25px rgba(13,110,253,0.12); border-color:#0d6efd; }
    .corsa-card.selected { border-color:#198754; background:#e9f7ef; }
    .corsa-ora { font-size:1.4rem; font-weight:800; color:#0d6efd; }
    .orig-box { background:#eef4ff; border-left:4px solid #1a3a6b; border-radius:10px; padding:12px 16px; }
    .vagone-container { max-width: 420px; margin: 0 auto; }
    .posto-btn { width:48px; height:48px; margin:0; border-radius:10px; font-weight:700; border:2px solid #dbe7ff; background:#fff; color:#0d6efd; cursor:pointer; transition:.15s; padding:0; }
    .posto-btn:hover:not(.occupato) { background:#0d6efd; color:#fff; }
    .posto-btn.occupato { background:#dc3545; color:#fff; cursor:not-allowed; border-color:#dc3545; opacity:.8; }
    .posto-btn.selezionato { background:#198754; color:#fff; border-color:#198754; }
    .posto-btn.attuale { background:#ffc107; color:#000; border-color:#ffc107; }
    .seat-sample { display:inline-block; width:14px; height:14px; border-radius:4px; margin-right:4px; vertical-align:middle; }
</style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary shadow-sm mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold" href="biglietti_riepilogo.php"><i class="bi bi-arrow-left me-2"></i>I miei viaggi</a>
        <span class="text-white"><i class="bi bi-calendar-event me-1"></i> Cambio Data</span>
    </div>
</nav>

<div class="container pb-5">

    <?php $err_code = $_GET['err'] ?? ''; if ($err_code !== ''): ?>
        <div class="alert alert-danger">
            <?php
            switch ($err_code) {
                case 'posto_occupato': echo 'Il posto selezionato è già stato occupato per questa tratta. Scegline un altro.'; break;
                case 'chiusa_15min':   echo 'Questa corsa parte tra meno di 15 minuti: non è più modificabile.'; break;
                case 'corsa_passata':  echo 'La corsa selezionata è già partita.'; break;
                case 'corsa_non_attiva': echo 'La corsa selezionata non è più attiva (annullata o conclusa).'; break;
                default: echo 'Operazione non consentita ('. htmlspecialchars($err_code) .').';
            }
            ?>
        </div>
    <?php endif; ?>

    <div class="orig-box mb-4">
        <div class="small text-muted">Biglietto attuale #<?= $bigl['IDBIGLIETTO'] ?></div>
        <div class="fw-bold"><?= htmlspecialchars($bigl['NOME_P']) ?> <i class="bi bi-arrow-right"></i> <?= htmlspecialchars($bigl['NOME_A']) ?></div>
        <div class="small">
            <i class="bi bi-train-front-fill me-1"></i><?= htmlspecialchars($bigl['NOME_TRENO']) ?>
            &middot; <?= date('d/m/Y', strtotime($bigl['DATA_ORIG'])) ?> alle <?= substr($bigl['ORA_PART_ORIG'],0,5) ?>
            &middot; Posto <?= $bigl['IDPOSTO'] ?>
        </div>
    </div>

    <form method="GET" class="row g-2 align-items-end mb-4">
        <input type="hidden" name="id" value="<?= $id_biglietto ?>">
        <div class="col-auto">
            <label class="form-label fw-semibold small mb-1">Nuova data</label>
            <input type="date" name="data" class="form-control" value="<?= htmlspecialchars($data_scelta) ?>" min="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-auto">
            <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Cerca corse</button>
        </div>
    </form>

    <h5 class="fw-bold mb-3">
        Corse disponibili per il <?= date('d/m/Y', strtotime($data_scelta)) ?>
        <span class="badge bg-primary ms-2"><?= count($corse_disp) ?></span>
    </h5>

    <?php if (empty($corse_disp)): ?>
        <div class="alert alert-warning border-0 shadow-sm rounded-3 d-flex align-items-center gap-3 p-4">
            <i class="bi bi-exclamation-triangle-fill fs-2 text-warning"></i>
            <div>
                <div class="fw-bold mb-1">Attenzione</div>
                <div>Al momento non ci sono corse disponibili per questa data sul tragitto
                    <strong><?= htmlspecialchars($bigl['NOME_P']) ?> → <?= htmlspecialchars($bigl['NOME_A']) ?></strong>.
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($corse_disp as $cd): $is_sel = ($corsa_sel == $cd['IDCORSA']); ?>
            <a href="cambio_data.php?id=<?= $id_biglietto ?>&data=<?= urlencode($data_scelta) ?>&corsa=<?= $cd['IDCORSA'] ?>#posti"
               class="text-decoration-none text-dark">
                <div class="corsa-card d-flex justify-content-between align-items-center flex-wrap gap-3 <?= $is_sel ? 'selected' : '' ?>">
                    <div>
                        <div class="corsa-ora">
                            <i class="bi bi-clock me-1"></i><?= substr($cd['ORA_P'] ?: $cd['ORA'],0,5) ?>
                            <i class="bi bi-arrow-right mx-2 text-muted"></i><?= substr($cd['ORA_A'] ?: $cd['ORA'],0,5) ?>
                        </div>
                        <div class="small text-muted">
                            <i class="bi bi-train-front-fill me-1"></i><?= htmlspecialchars($cd['NOME_TRENO']) ?>
                            &middot; Corsa #<?= $cd['IDCORSA'] ?>
                            &middot; <?= $cd['POSTI_OCC'] ?> posti occupati
                        </div>
                    </div>
                    <span class="btn btn-outline-primary"><?= $is_sel ? 'Selezionata' : 'Scegli' ?> <i class="bi bi-chevron-right ms-1"></i></span>
                </div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($corsa_sel > 0 && !empty($carrozze)): ?>
    <div id="posti" class="mt-5 p-4 bg-white rounded-3 shadow-sm">
        <h5 class="fw-bold mb-3"><i class="bi bi-grid-3x3-gap-fill me-2"></i>Scegli il posto</h5>
        <form method="POST" action="cambio_data.php" id="frmConferma">
            <input type="hidden" name="azione"         value="conferma_cambio">
            <input type="hidden" name="id_biglietto"   value="<?= $id_biglietto ?>">
            <input type="hidden" name="id_corsa_nuova" value="<?= $corsa_sel ?>">
            <input type="hidden" name="id_posto_nuovo" id="inpPosto" value="">

            <?php foreach ($carrozze as $matricola => $carrozza): ?>
            <div class="mb-4">
                <div class="text-center mb-2">
                    <span class="badge bg-secondary px-3 py-2"><?= htmlspecialchars($carrozza['nome']); ?></span>
                </div>
                <div class="vagone-container bg-dark p-3 rounded-4 shadow">
                    <div class="d-flex justify-content-between align-items-center mb-3 text-white-50 small fw-bold px-2">
                        <div style="flex:1; text-align:left;"><span title="Finestrino">F</span></div>
                        <div style="flex:0 0 auto; width:80px; text-align:center; font-size:9px; letter-spacing:1px; color:#adb5bd;">CORRIDOIO</div>
                        <div style="flex:1; text-align:right;"><span title="Finestrino">F</span></div>
                    </div>
                    <?php
                    $posti  = $carrozza['posti'];
                    $totale = count($posti);
                    $file   = ceil($totale / 4);
                    for ($f = 0; $f < $file; $f++):
                        $base = $f * 4;
                    ?>
                    <div class="d-flex justify-content-between mb-2">
                        <div class="d-flex gap-2">
                            <?php for ($p = 1; $p <= 2; $p++):
                                $idx = $base + $p - 1;
                                if (!isset($posti[$idx])) continue;
                                $i = $posti[$idx];
                                $occ = isset($posti_occupati[$i . '_' . $matricola]);
                                $is_attuale = ((int)$bigl['IDPOSTO'] === (int)$i && $bigl['MATRICOLAMATERIALE'] === $matricola);
                                $cls = $occ ? 'occupato' : ($is_attuale ? 'attuale' : '');
                                $num_display = $idx + 1;
                            ?>
                                <button type="button"
                                        class="posto-btn <?= $cls ?>"
                                        <?= $occ ? 'disabled' : '' ?>
                                        data-posto="<?= $i ?>"
                                        data-mat="<?= htmlspecialchars($matricola) ?>"><?= $num_display ?></button>
                            <?php endfor; ?>
                        </div>
                        <div class="d-flex gap-2">
                            <?php for ($p = 3; $p <= 4; $p++):
                                $idx = $base + $p - 1;
                                if (!isset($posti[$idx])) continue;
                                $i = $posti[$idx];
                                $occ = isset($posti_occupati[$i . '_' . $matricola]);
                                $is_attuale = ((int)$bigl['IDPOSTO'] === (int)$i && $bigl['MATRICOLAMATERIALE'] === $matricola);
                                $cls = $occ ? 'occupato' : ($is_attuale ? 'attuale' : '');
                                $num_display = $idx + 1;
                            ?>
                                <button type="button"
                                        class="posto-btn <?= $cls ?>"
                                        <?= $occ ? 'disabled' : '' ?>
                                        data-posto="<?= $i ?>"
                                        data-mat="<?= htmlspecialchars($matricola) ?>"><?= $num_display ?></button>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="text-center mt-3 small text-muted">
                <span class="me-3"><span class="seat-sample bg-danger"></span> Occupato</span>
                <span class="me-3"><span class="seat-sample" style="background:#fff;border:2px solid #dbe7ff;"></span> Libero</span>
                <span class="me-3"><span class="seat-sample bg-success"></span> Selezionato</span>
                <span><span class="seat-sample bg-warning"></span> Posto attuale</span>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-success btn-lg" id="btnConferma" disabled>
                    <i class="bi bi-check2-circle me-1"></i>Conferma cambio data
                </button>
                <a href="biglietti_riepilogo.php" class="btn btn-outline-secondary btn-lg">Annulla</a>
            </div>
        </form>
    </div>

    <script>
        document.querySelectorAll('.posto-btn:not(.occupato)').forEach(b => {
            b.addEventListener('click', () => {
                document.querySelectorAll('.posto-btn.selezionato').forEach(x => x.classList.remove('selezionato'));
                b.classList.add('selezionato');
                document.getElementById('inpPosto').value = b.dataset.posto + '|' + b.dataset.mat;
                document.getElementById('btnConferma').disabled = false;
            });
        });
    </script>
    <?php endif; ?>

    <div class="mt-4">
        <a href="biglietti_riepilogo.php" class="btn btn-outline-secondary">
            <i class="bi bi-x-lg me-1"></i>Torna indietro
        </a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

