<?php
session_name('SFT_SESSION'); session_start();
include('connessione.php');

if (!isset($_SESSION['sft_id']) || $_SESSION['sft_ruolo'] !== 'Amministrativo') { 
    header("Location: index.php?errore=non_autorizzato");
    exit();
}

$cerca_treno = $con->real_escape_string($_GET['cerca_treno'] ?? '');
$filtra_data = $con->real_escape_string($_GET['filtra_data'] ?? '');
$vista = $_GET['vista'] ?? 'future';

$where = " WHERE 1=1 ";
if (!empty($cerca_treno)) $where .= " AND CO.NOME LIKE '%$cerca_treno%' ";
if (!empty($filtra_data)) $where .= " AND C.DATA = '$filtra_data' ";

if ($vista === 'storico') {
    $where .= " AND C.DATA < CURDATE()";
} else {
    $where .= " AND C.DATA >= CURDATE()";
}

$sql = "SELECT 
    C.IDCORSA,
    C.DATA,
    C.ORA,
    C.DIREZIONE,
    CO.NOME AS TRENO,
    CO.IDCONVOGLIO,

    COALESCE((SELECT SUM(MR2.CAPACITA_POSTO)
     FROM SFT_COMPOSTO COMP2
     JOIN SFT_MATERIALEROTABILE MR2 ON COMP2.MATRICOLAMAT = MR2.MATRICOLA
     WHERE COMP2.IDCONVOGLIO = CO.IDCONVOGLIO), 0) AS CAPIENZA_TOTALE,

    COALESCE((SELECT SUM(MR2.COSTOKM)
     FROM SFT_COMPOSTO COMP2
     JOIN SFT_MATERIALEROTABILE MR2 ON COMP2.MATRICOLAMAT = MR2.MATRICOLA
     WHERE COMP2.IDCONVOGLIO = CO.IDCONVOGLIO), 0.25) AS COSTO_KM_TOTALE,

    COUNT(DISTINCT B.IDBIGLIETTO) AS NUM_VENDUTI,
    ROUND((COUNT(DISTINCT B.IDBIGLIETTO) / NULLIF(
        COALESCE((SELECT SUM(MR2.CAPACITA_POSTO)
         FROM SFT_COMPOSTO COMP2
         JOIN SFT_MATERIALEROTABILE MR2 ON COMP2.MATRICOLAMAT = MR2.MATRICOLA
         WHERE COMP2.IDCONVOGLIO = CO.IDCONVOGLIO), 0), 0)) * 100, 1) AS PERC_OCCUPAZIONE,

    IFNULL(SUM(B.COSTO), 0) AS RICAVO_LORDO,

    COALESCE(
        (SELECT MAX(ABS(SA.KMPROGRESSIVO - SP.KMPROGRESSIVO))   
         FROM SFT_BIGLIETTO B2
         JOIN SFT_STAZIONE SP ON B2.IDSTAZIONEP = SP.IDSTAZIONE
         JOIN SFT_STAZIONE SA ON B2.IDSTAZIONEA = SA.IDSTAZIONE
         WHERE B2.IDCORSA = C.IDCORSA),
        (SELECT MAX(SF2.KMPROGRESSIVO) - MIN(SF2.KMPROGRESSIVO)
         FROM SFT_FERMATA FER2
         JOIN SFT_STAZIONE SF2 ON FER2.IDSTAZIONE = SF2.IDSTAZIONE
         WHERE FER2.IDCORSA = C.IDCORSA)
    ) AS DISTANZA_KM,

    (IFNULL(SUM(B.COSTO), 0) - (
        COALESCE(
            (SELECT MAX(ABS(SA.KMPROGRESSIVO - SP.KMPROGRESSIVO))
             FROM SFT_BIGLIETTO B2
             JOIN SFT_STAZIONE SP ON B2.IDSTAZIONEP = SP.IDSTAZIONE
             JOIN SFT_STAZIONE SA ON B2.IDSTAZIONEA = SA.IDSTAZIONE
             WHERE B2.IDCORSA = C.IDCORSA),
            (SELECT MAX(SF2.KMPROGRESSIVO) - MIN(SF2.KMPROGRESSIVO)
             FROM SFT_FERMATA FER2
             JOIN SFT_STAZIONE SF2 ON FER2.IDSTAZIONE = SF2.IDSTAZIONE
             WHERE FER2.IDCORSA = C.IDCORSA)
        )
        *
        COALESCE((SELECT SUM(MR2.COSTOKM)
         FROM SFT_COMPOSTO COMP2
         JOIN SFT_MATERIALEROTABILE MR2 ON COMP2.MATRICOLAMAT = MR2.MATRICOLA
         WHERE COMP2.IDCONVOGLIO = CO.IDCONVOGLIO), 0.25)
    )) AS UTILE

FROM SFT_CORSA C
JOIN SFT_CONVOGLIO CO ON C.IDCONVOGLIO = CO.IDCONVOGLIO
LEFT JOIN SFT_BIGLIETTO B ON C.IDCORSA = B.IDCORSA
$where
GROUP BY C.IDCORSA, C.DATA, C.ORA, C.DIREZIONE, CO.NOME, CO.IDCONVOGLIO
ORDER BY C.DATA ASC, C.ORA ASC";

$res = $con->query($sql);
$bilancio_aziendale = 0;
?><!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>SFT Admin - Amministrazione</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="stile.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">
            SFT - Società Ferroviarie Turistiche
        </a>
        <div class="ms-auto d-flex align-items-center gap-2">
            <span class="badge bg-warning text-dark me-2">
                <i class="bi bi-shield-lock me-1"></i>Amministrativo
            </span>
            <a href="index.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-house"></i> Home
            </a>
            <a href="logout.php" class="btn btn-danger btn-sm">
                <i class="bi bi-box-arrow-right"></i> Esci
            </a>
        </div>
    </div>
</nav>

<main>
<div class="container py-4 mb-5">

    <!-- MESSAGGIO -->
    <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 rounded-3" role="alert">
        <i class="bi bi-send-check-fill me-2"></i>
        <strong>Richiesta inviata:</strong> <?php echo htmlspecialchars($_GET['msg']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

   <!-- HEADER SEZIONE -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1" style="color:#1a3a6b;">
            <?php if ($vista === 'storico'): ?>
                <i class="bi bi-clock-history me-1"></i> Storico Corse Passate
            <?php else: ?>
                <i class="bi bi-graph-up me-1"></i> Corse Future
            <?php endif; ?>
        </h4>
        <small class="text-muted">
            <?php echo $vista === 'storico' ? 'Solo lettura — nessuna azione disponibile' : 'Gestisci, annulla o richiedi straordinari'; ?>
        </small>
    </div>

    <div class="d-flex align-items-center gap-3 flex-wrap">

        <!-- LEGENDA accanto al titolo -->
        <?php if ($vista === 'future'): ?>
        <div class="d-flex flex-wrap gap-3 align-items-center small"
             style="background:#f0f4ff; border:1px solid #d0d9f0; border-radius:8px; padding:6px 12px;">
            <span class="fw-semibold" style="color:#1a3a6b;">
                <i class="bi bi-info-circle me-1"></i>Legenda:
            </span>
            <span><span class="badge bg-success">Verde</span> Utile +</span>
            <span><span class="badge bg-danger">Rosso</span> Utile −</span>
            <span>
                <i class="bi bi-train-front text-warning me-1"></i>
                <span class="text-warning fw-bold">-€</span> Trasf. vuoto
            </span>
            <span class="d-flex align-items-center gap-1">
                <div class="progress" style="width:40px;height:8px;border-radius:4px;">
                    <div class="progress-bar bg-danger" style="width:90%"></div>
                </div>
                <span class="text-muted">&gt;80%</span>
            </span>
        </div>
        <?php endif; ?>

        <!-- PULSANTE STORICO / TORNA FUTURE -->
        <?php if ($vista === 'storico'): ?>
    <a href="admin_amministrativo.php" class="btn btn-sm fw-bold shadow-sm"
       style="background:#1a3a6b;color:white;border:none;">
        <i class="bi bi-arrow-left me-1"></i> Torna alle Future
    </a>
<?php endif; ?>

    </div>
</div>

<?php if ($vista === 'storico'): ?>
<div class="alert alert-secondary d-flex align-items-center gap-2 py-2 mb-3">
    <i class="bi bi-info-circle"></i>
    <span>Stai visualizzando le corse con data <strong>precedente a oggi</strong>. Nessuna azione disponibile.</span>
</div>
<?php endif; ?>

    <!-- FILTRI -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h6 class="fw-bold mb-3" style="color:#1a3a6b;">
                <i class="bi bi-funnel me-1"></i>Filtri di ricerca
            </h6>
            <form method="GET" action="admin_amministrativo.php" class="row g-3 align-items-end">
                <input type="hidden" name="vista" value="<?php echo $vista; ?>">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Cerca per Treno</label>
                    <input type="text" name="cerca_treno" class="form-control"
                           placeholder="Es: SFT.3..."
                           value="<?php echo $_GET['cerca_treno'] ?? ''; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Filtra per Data</label>
                    <input type="date" name="filtra_data" class="form-control"
                           value="<?php echo $_GET['filtra_data'] ?? ''; ?>">
                </div>
                <div class="col-md-5 d-flex gap-2">
                    <button type="submit" class="btn btn-sm fw-bold px-4"
                            style="background:#1a3a6b;color:white;border:none;">
                        <i class="bi bi-search me-1"></i> Filtra
                    </button>
                    <a href="admin_amministrativo.php?vista=<?php echo $vista; ?>"
                       class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-clockwise me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- TABELLA -->
    <div class="table-responsive shadow-sm bg-white rounded p-3">
        <table class="table table-hover align-middle mb-0">
            <thead class="text-uppercase small" style="background:#f0f4ff; color:#1a3a6b;">
                <tr>
                    <th>Corsa / Data</th>
                    <th>Treno</th>
                    <th style="width:220px;">Occupazione</th>
                    <th class="text-center">Distanza</th>
                    <th class="text-center">
                        <?php echo $vista === 'storico' ? 'Ricavo Reale' : 'Ricavo'; ?>
                    </th>
                    <th class="text-center">
                        <?php echo $vista === 'storico' ? 'Utile Reale' : 'Utile'; ?>
                    </th>
                    <?php if ($vista === 'future'): ?>
                    <th class="text-end">Azioni</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if ($res && $res->num_rows > 0): ?>
                <?php
                $tutte_corse = [];
                $res->data_seek(0);
                while ($row = $res->fetch_assoc()) $tutte_corse[] = $row;

                usort($tutte_corse, function($a, $b) {
                    if ($a['DATA'] != $b['DATA']) return strcmp($a['DATA'], $b['DATA']);
                    return strcmp($a['ORA'], $b['ORA']);
                });

                foreach ($tutte_corse as $row):
                    $id_corsa  = $row['IDCORSA'];
                    $id_conv   = $row['IDCONVOGLIO'];
                    $data_corsa = $row['DATA'];
                    $ora_corsa  = $row['ORA'] ?? '00:00:00';
                    $direzione  = $row['DIREZIONE'] ?? 'Sud';
                    $parte_da   = ($direzione === 'Sud') ? 'Nord' : 'Sud';
                    $costo_km   = $row['COSTO_KM_TOTALE'] ?? 0;
                    $km_trasferimento = 54.68;
                    $serve_trasferimento = false;
                    $costo_trasferimento = 0;

                    $r_prec = $con->query("
                        SELECT DIREZIONE FROM SFT_CORSA
                        WHERE IDCONVOGLIO = $id_conv AND DATA = '$data_corsa'
                        AND ORA < '$ora_corsa' AND STATO NOT IN ('Annullata')
                        ORDER BY ORA DESC LIMIT 1
                    ");

                    if ($r_prec && $r_prec->num_rows > 0) {
                        $prec = $r_prec->fetch_assoc();
                        $posizione_attuale = ($prec['DIREZIONE'] === 'Sud') ? 'Sud' : 'Nord';
                        if ($posizione_attuale !== $parte_da) $serve_trasferimento = true;
                    } else {
                        if ($direzione === 'Nord') $serve_trasferimento = true;
                    }

                    if ($serve_trasferimento)
                        $costo_trasferimento = round($km_trasferimento * $costo_km, 2);

                    $margine_lordo = $row['UTILE'] ?? 0;
                    $margine = $margine_lordo - $costo_trasferimento;
                    $distanza = $row['DISTANZA_KM'] ?? 0;
                    $ricavo   = $row['RICAVO_LORDO'] ?? 0;
                    $perc     = $row['PERC_OCCUPAZIONE'] ?? 0;
                    $bilancio_aziendale += $margine;
                ?>
                <tr>
                    <td>
                        <div class="fw-bold" style="color:#1a3a6b;">#<?php echo $id_corsa; ?></div>
                        <div class="small text-muted"><?php echo date("d/m/Y", strtotime($data_corsa)); ?></div>
                        <div class="small text-muted">
                            <?php echo substr($ora_corsa, 0, 5); ?>
                            <span class="badge bg-secondary ms-1" style="font-size:0.65rem;">
                                <?php echo $direzione; ?>
                            </span>
                        </div>
                    </td>
                    <td>
                        <span class="badge" style="background:#1a3a6b;">
                            <?php echo $row['TRENO']; ?>
                        </span>
                    </td>
                    <td>
                        <div class="d-flex justify-content-between mb-1 small">
                            <span><?php echo $row['NUM_VENDUTI']; ?> / <?php echo $row['CAPIENZA_TOTALE']; ?> posti</span>
                            <span class="fw-bold"><?php echo $perc; ?>%</span>
                        </div>
                        <div class="progress" style="height:8px; border-radius:4px;">
                            <div class="progress-bar <?php echo ($perc > 80) ? 'bg-danger' : ($perc > 0 ? 'bg-success' : 'bg-secondary opacity-25'); ?>"
                                 style="width:<?php echo max($perc, 3); ?>%"></div>
                        </div>
                    </td>
                    <td class="text-center text-muted small">
                        <?php echo number_format($distanza, 1); ?> km
                    </td>
                    <td class="text-center fw-bold">
                        <?php echo number_format($ricavo, 2); ?> €
                    </td>
                    <td class="text-center fw-bold <?php echo ($margine >= 0) ? 'text-success' : 'text-danger'; ?>">
                        <?php echo number_format($margine, 2); ?> €
                        <?php if ($serve_trasferimento): ?>
                        <small class="d-block text-warning" style="font-size:0.65rem;"
                               title="Trasferimento vuoto: <?php echo $km_trasferimento; ?> km × <?php echo number_format($costo_km,2); ?> €/km">
                            <i class="bi bi-train-front"></i> -<?php echo number_format($costo_trasferimento, 2); ?>€
                        </small>
                        <?php endif; ?>
                    </td>
                    <?php if ($vista === 'future'): ?>
                    <td class="text-end">
                        <div class="d-flex justify-content-end gap-1">
                           <?php if ($perc > 85): ?>
                            <a href="admin_amministrativo.php?vista=<?php echo $vista; ?>&msg=<?php echo urlencode('Richiesta di treno straordinario per la corsa #' . $id_corsa . ' inviata al backoffice di esercizio.'); ?>"
                            class="btn btn-sm btn-warning fw-bold"
                            title="Richiedi straordinario al backoffice"
                            onclick="return confirm('Inviare al backoffice di esercizio la richiesta di treno straordinario per la corsa #<?php echo $id_corsa; ?>?');">
                                <i class="bi bi-megaphone"></i> Straord.
                            </a>
                            <?php endif; ?>
                            <?php if ($row['NUM_VENDUTI'] == 0): ?>
                            <a href="admin_amministrativo.php?vista=<?php echo $vista; ?>&msg=<?php echo urlencode('Richiesta di cessazione corsa #' . $id_corsa . ' inviata al backoffice di esercizio.'); ?>"
                            class="btn btn-sm btn-outline-danger"
                            title="Richiedi cessazione al backoffice"
                            onclick="return confirm('Inviare al backoffice di esercizio la richiesta di cessazione della corsa #<?php echo $id_corsa; ?>?');">
                                <i class="bi bi-trash"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>

            <?php else: ?>
                <tr>
                    <td colspan="<?php echo $vista === 'future' ? '7' : '6'; ?>"
                        class="text-center py-5 text-muted">
                        <i class="bi bi-inbox display-6 d-block mb-2 opacity-25"></i>
                        Nessuna corsa <?php echo $vista === 'storico' ? 'passata' : 'futura'; ?> trovata.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>

            <tfoot>
                <tr style="background:#1a3a6b; color:white;">
                    <td colspan="5" class="text-end fw-bold py-3">
                        <i class="bi bi-calculator me-1"></i>
                        <?php echo $vista === 'storico' ? 'TOTALE RICAVI STORICI:' : 'BILANCIO TOTALE NETTO:'; ?>
                    </td>
                    <td colspan="<?php echo $vista === 'future' ? '2' : '1'; ?>"
                        class="fw-bold fs-5 py-3">
                        <?php
                        $col = ($bilancio_aziendale >= 0) ? '#4ade80' : '#f87171';
                        echo "<span style='color:{$col}'>" . number_format($bilancio_aziendale, 2) . " €</span>";
                        ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

</div>
</main>

<footer class="bg-dark text-white py-4 mt-5">
    <div class="container text-center">
        <p class="mb-0">&copy; 2026 SFT - Società Ferroviaria Turistica</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>