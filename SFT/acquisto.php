<?php
session_name('SFT_SESSION');
session_start();
date_default_timezone_set('Europe/Rome');
include('connessione.php');
include('aggiorna_stato.php');

// Formattazione orario con indicatore +1 se e' dopo mezzanotte
function formatOraConGiorno($ora, $ora_partenza_corsa = null) {
    $h = (int)substr($ora, 0, 2);
    $ora_fmt = substr($ora, 0, 5);
    if ($h < 6) {
        if ($ora_partenza_corsa !== null) {
            $h_partenza = (int)substr($ora_partenza_corsa, 0, 2);
            if ($h_partenza >= 20) {
                return $ora_fmt . ' <sup class="text-warning">+1</sup>';
            }
        }
        return $ora_fmt . ' <sup class="text-warning">+1</sup>';
    }
    return $ora_fmt;
}

$partenza = $_GET['partenza'] ?? '';
$arrivo   = $_GET['arrivo']   ?? '';
$data     = $_GET['data']     ?? '';

if (empty($data)) {
    header("Location: index.php");
    exit();
}

$timestamp   = strtotime($data);
$data        = date('Y-m-d', $timestamp);
$giorno_sett = (int)date('N', $timestamp);
$mese        = (int)date('m', $timestamp);
$giorno_mese = date('d-m', $timestamp);

$festivi_fissi = [
    '01-01', '06-01', '25-04', '01-05',
    '02-06', '15-08', '01-11', '08-12',
    '25-12', '26-12'
];

$anno      = (int)date('Y', $timestamp);
$pasqua    = easter_date($anno);
$pasquetta = strtotime('+1 day', $pasqua);
$festivi_mobili = [
    date('d-m', $pasqua),
    date('d-m', $pasquetta)
];

$is_festivo = ($giorno_sett == 7)
           || in_array($giorno_mese, $festivi_fissi)
           || in_array($giorno_mese, $festivi_mobili);

$is_feriale_estivo = !$is_festivo && ($mese >= 6 && $mese <= 9);

if ($is_festivo) {
    $esito_calendario = "FESTIVO";
} elseif ($is_feriale_estivo) {
    $esito_calendario = "FERIALE_ESTIVO";
} else {
    $esito_calendario = "CHIUSO";
}

$oggi        = date('Y-m-d');
$ora_attuale = date('H:i:s');
$ora_chiusura_prenotazione = date('H:i:s', strtotime('+15 minutes'));

$is_oggi   = ($data == $oggi);
$is_futuro = ($data >  $oggi);

if ($is_futuro) {
    $filtro_dinamico = "AND C.STATO = 'Programmata'";
} elseif ($is_oggi) {
    $filtro_dinamico = "AND C.STATO IN ('Programmata','In Viaggio')
                        AND COALESCE(F1.ORAP, F1.ORAA) > '$ora_attuale'";
} else {
    $filtro_dinamico = "AND 1=0";
}

$msg_warning = '';
if (($_GET['msg'] ?? '') === 'errore_posto') {
    $msg_warning = "Il posto scelto è stato appena prenotato da un altro utente. Scegli un altro posto.";
}

// GESTIONE CACHE
$risultati_cache = null;
$from_cache      = false;

if ($esito_calendario != "CHIUSO") {

    $cache_dir = __DIR__ . '/cache/';
    if (!is_dir($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }

    $cache_key    = md5($partenza . $arrivo . $data);
    $cache_file   = $cache_dir . $cache_key . '.json';
    $cache_durata = $is_oggi ? 30 : 300;

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_durata) {
        $risultati_cache = json_decode(file_get_contents($cache_file), true);
        $from_cache = true;
    } else {

        $sql = "SELECT 
            C.IDCORSA,
            C.STATO AS STATO_CORSA,
            CO.IDCONVOGLIO,
            CO.NOME AS NOME_TRENO,
            COALESCE(F1.ORAP, F1.ORAA) AS PARTENZA_REALE,
            COALESCE(F2.ORAA, F2.ORAP) AS ARRIVO_REALE,
            F1.PROGRESSIVO AS PROG_P_RICHIESTA,
            F2.PROGRESSIVO AS PROG_A_RICHIESTA,
            S1.KMPROGRESSIVO AS KM_P,
            S2.KMPROGRESSIVO AS KM_A,         
            ABS(S2.KMPROGRESSIVO - S1.KMPROGRESSIVO) AS DISTANZA,
            (SELECT SUM(MR2.COSTOKM)
             FROM SFT_COMPOSTO COMP2
             JOIN SFT_MATERIALEROTABILE MR2 ON COMP2.MATRICOLAMAT = MR2.MATRICOLA
             WHERE COMP2.IDCONVOGLIO = CO.IDCONVOGLIO) AS COSTO_MATERIALE,
            (SELECT SUM(MR2.CAPACITA_POSTO)
             FROM SFT_COMPOSTO COMP2
             JOIN SFT_MATERIALEROTABILE MR2 ON COMP2.MATRICOLAMAT = MR2.MATRICOLA
             WHERE COMP2.IDCONVOGLIO = CO.IDCONVOGLIO
             AND MR2.CAPACITA_POSTO > 0) AS POSTI_TOTALI,
            (SELECT COUNT(*)
             FROM SFT_BIGLIETTO B
             JOIN SFT_FERMATA FBP ON FBP.IDCORSA = B.IDCORSA AND FBP.IDSTAZIONE = B.IDSTAZIONEP
             JOIN SFT_FERMATA FBA ON FBA.IDCORSA = B.IDCORSA AND FBA.IDSTAZIONE = B.IDSTAZIONEA
             WHERE B.IDCORSA = C.IDCORSA
             AND B.STATOPAGAMENTO = 'Pagato'
             AND GREATEST(F1.PROGRESSIVO, F2.PROGRESSIVO) > LEAST(FBP.PROGRESSIVO, FBA.PROGRESSIVO)
             AND LEAST(F1.PROGRESSIVO, F2.PROGRESSIVO) < GREATEST(FBP.PROGRESSIVO, FBA.PROGRESSIVO)
            ) AS VENDUTI
        FROM SFT_CORSA C
        LEFT JOIN SFT_CONVOGLIO CO ON C.IDCONVOGLIO = CO.IDCONVOGLIO
        JOIN SFT_FERMATA F1 ON F1.IDCORSA = C.IDCORSA
        JOIN SFT_FERMATA F2 ON F2.IDCORSA = C.IDCORSA
        JOIN SFT_STAZIONE S1 ON F1.IDSTAZIONE = S1.IDSTAZIONE
        JOIN SFT_STAZIONE S2 ON F2.IDSTAZIONE = S2.IDSTAZIONE
        WHERE S1.NOME = '$partenza'
        AND S2.NOME = '$arrivo'
        AND C.DATA = '$data'
        AND S1.KMPROGRESSIVO != S2.KMPROGRESSIVO
        AND F1.PROGRESSIVO < F2.PROGRESSIVO
        $filtro_dinamico
        ORDER BY PARTENZA_REALE ASC";

        $res = $con->query($sql);

        if (!$res) {
            echo "ERRORE QUERY: " . $con->error;
            $risultati_cache = [];
        } else {
            $risultati_cache = [];
            while ($row = $res->fetch_assoc()) {
                $risultati_cache[] = $row;
            }
            if (!empty($risultati_cache) && is_writable($cache_dir)) {
                file_put_contents($cache_file, json_encode($risultati_cache));
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>SFT - Selezione Corsa</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="stile.css">
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<div class="container mt-5 flex-grow-1 d-flex flex-column">

<div>
    <div class="card card-viaggio border-0 mb-4 overflow-hidden"
         style="--bs-card-bg: transparent; --sft-card-bg: transparent;">
        <div class="card-body py-4 px-4">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                <div>
                    <div class="testo-muted-viaggio mb-1" style="font-size:0.75rem; letter-spacing:0.1em; text-transform:uppercase;">
                        Soluzioni di viaggio
                    </div>
                    <div class="d-flex align-items-center gap-2 fw-bold" style="font-size:1.35rem;">
                        <span><?php echo htmlspecialchars($partenza); ?></span>
                        <i class="bi bi-arrow-right" style="opacity:0.6;"></i>
                        <span><?php echo htmlspecialchars($arrivo); ?></span>
                    </div>
                </div>
                <div class="d-none d-md-block" style="width:1px; height:48px; background:rgba(255,255,255,0.2);"></div>
                <div class="text-center">
                    <div class="testo-muted-viaggio mb-1" style="font-size:0.75rem; letter-spacing:0.1em; text-transform:uppercase;">
                        Data
                    </div>
                    <div class="fw-bold" style="font-size:1.1rem;">
                        <i class="bi bi-calendar3 me-1" style="opacity:0.7;"></i>
                        <?php echo date("d/m/Y", strtotime($data)); ?>
                    </div>
                    <div style="font-size:0.78rem; opacity:0.6;">
                        <?php
                        $giorni = ['Sunday'=>'Domenica','Monday'=>'Lunedì','Tuesday'=>'Martedì',
                                   'Wednesday'=>'Mercoledì','Thursday'=>'Giovedì',
                                   'Friday'=>'Venerdì','Saturday'=>'Sabato'];
                        echo $giorni[date('l', strtotime($data))];
                        ?>
                    </div>
                </div>
                <div class="d-none d-md-block" style="width:1px; height:48px; background:rgba(255,255,255,0.2);"></div>
                <div>
                    <a href="index.php" class="btn btn-sm btn-light fw-bold px-3">
                        <i class="bi bi-search me-1"></i> Nuova ricerca
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($msg_warning): ?>
        <div class="alert alert-warning shadow-sm">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($msg_warning) ?>
        </div>
    <?php endif; ?>

    <?php if ($esito_calendario == "CHIUSO"): ?>
        <div class="alert alert-warning text-center shadow-sm py-5">
            <i class="bi bi-calendar-x fs-1"></i>
            <h4 class="mt-3">Servizio Non Attivo</h4>
            <p>I treni storici circolano nei festivi tutto l'anno e nei feriali solo da Giugno a Settembre.</p>
            <a href="index.php" class="btn btn-primary">Nuova Ricerca</a>
        </div>
    <?php else: ?>

        <div class="d-flex gap-4 mb-3 align-items-center flex-wrap" style="font-size: 0.8rem;">
            <span class="text-muted">
                <sup class="text-warning fw-bold">+1</sup> = Arrivo il giorno successivo
            </span>
            <?php if ($is_oggi): ?>
                <span class="text-muted">
                    <i class="bi bi-info-circle"></i>
                    Le prenotazioni chiudono <strong>15 minuti</strong> prima della partenza.
                </span>
            <?php endif; ?>
        </div>

        <div class="table-responsive">
            <table class="table table-hover bg-white shadow-sm align-middle rounded overflow-hidden">
                <thead class="table-dark">
                    <tr>
                        <th>Treno / Convoglio</th>
                        <th>Partenza</th>
                        <th>Arrivo</th>
                        <th>Distanza</th>
                        <th>Prezzo</th>
                        <th class="text-end">Azione</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($risultati_cache)): ?>
                    <?php foreach ($risultati_cache as $corsa): ?>
                        <?php
                            $distanza        = $corsa['DISTANZA'];
                            $tariffa_base    = 0.20;
                            $costo_materiale = $corsa['COSTO_MATERIALE'] ?? 0;
                            $tariffa_totale  = $tariffa_base + $costo_materiale;
                            $prezzo_finale   = $distanza * $tariffa_totale;

                            $id_corsa_attuale   = $corsa['IDCORSA'];
                            $id_utente          = $_SESSION['sft_id'] ?? null;
                            $posti_totali_treno = (int)($corsa['POSTI_TOTALI'] ?? 0);
                            $venduti            = (int)($corsa['VENDUTI'] ?? 0);
                            $posti_rimanenti    = $posti_totali_treno > 0
                                ? $posti_totali_treno - $venduti
                                : 999;

                            $partenza_tratta = $corsa['PARTENZA_REALE'];

                            if ($is_futuro) {
                                $stato_prenotazione = 'acquistabile';
                            } else { // oggi
                                if ($partenza_tratta <= $ora_chiusura_prenotazione) {
                                    $stato_prenotazione = 'chiusa_15min';
                                } else {
                                    $stato_prenotazione = 'acquistabile';
                                }
                            }

                            $gia_acquistato   = false;
                            $gia_coperto      = false;
                            $estendibile      = false;
                            $costo_pagato     = 0;
                            $costo_differenza = 0;
                            $biglietto        = null;
                            $partenza_union   = $partenza;
                            $arrivo_union     = $arrivo;

                            if ($id_utente) {
                                $check_sql = "SELECT B.IDBIGLIETTO, B.COSTO, B.IDPOSTO,
                                              SP.NOME AS STAZ_P, SA.NOME AS STAZ_A,
                                              SP.KMPROGRESSIVO AS KM_P_BIGLIETTO,
                                              SA.KMPROGRESSIVO AS KM_A_BIGLIETTO,
                                              FPB.PROGRESSIVO AS PROG_P_BIGLIETTO,
                                              FAB.PROGRESSIVO AS PROG_A_BIGLIETTO
                                       FROM SFT_BIGLIETTO B
                                       JOIN SFT_STAZIONE SP ON SP.IDSTAZIONE = B.IDSTAZIONEP
                                       JOIN SFT_STAZIONE SA ON SA.IDSTAZIONE = B.IDSTAZIONEA
                                       JOIN SFT_FERMATA FPB ON FPB.IDCORSA = B.IDCORSA AND FPB.IDSTAZIONE = B.IDSTAZIONEP
                                       JOIN SFT_FERMATA FAB ON FAB.IDCORSA = B.IDCORSA AND FAB.IDSTAZIONE = B.IDSTAZIONEA
                                       WHERE B.CODUTENTE = '$id_utente'
                                         AND B.IDCORSA   = '$id_corsa_attuale'
                                         AND B.STATOPAGAMENTO = 'Pagato'";
                                $res_check = $con->query($check_sql);

                                $best_kind = 'nuovo';   
                                $best_diff = PHP_FLOAT_MAX;

                                if ($res_check && $res_check->num_rows > 0) {
                                    while ($bgl = $res_check->fetch_assoc()) {

                                        $prog_p_richiesta = (int)($corsa['PROG_P_RICHIESTA'] ?? 0);
                                        $prog_a_richiesta = (int)($corsa['PROG_A_RICHIESTA'] ?? 0);
                                        $prog_p_biglietto = (int)$bgl['PROG_P_BIGLIETTO'];
                                        $prog_a_biglietto = (int)$bgl['PROG_A_BIGLIETTO'];

                                        $km_p_req = (float)($corsa['KM_P'] ?? 0);
                                        $km_a_req = (float)($corsa['KM_A'] ?? 0);
                                        $km_p_big = (float)$bgl['KM_P_BIGLIETTO'];
                                        $km_a_big = (float)$bgl['KM_A_BIGLIETTO'];

                                        $min_req = min($prog_p_richiesta, $prog_a_richiesta);
                                        $max_req = max($prog_p_richiesta, $prog_a_richiesta);
                                        $min_big = min($prog_p_biglietto, $prog_a_biglietto);
                                        $max_big = max($prog_p_biglietto, $prog_a_biglietto);

                                        if ($min_req === $min_big && $max_req === $max_big) {
                                            $best_kind = 'acquistato';
                                            $biglietto = $bgl;
                                            break;
                                        }

                                        if ($min_big <= $min_req && $max_big >= $max_req) {
                                            if ($best_kind !== 'acquistato') {
                                                $best_kind = 'coperto';
                                                $biglietto = $bgl;
                                            }
                                            continue;
                                        }

                                        if ($max_req <= $min_big || $min_req >= $max_big) {
                                            continue;
                                        }

                                        $lo_req = min($km_p_req, $km_a_req);
                                        $hi_req = max($km_p_req, $km_a_req);
                                        $lo_big = min($km_p_big, $km_a_big);
                                        $hi_big = max($km_p_big, $km_a_big);
                                        $km_overlap = max(0, min($hi_req, $hi_big) - max($lo_req, $lo_big));

                                        $distanza_scoperta = max(0, $distanza - $km_overlap);
                                        $diff = round($distanza_scoperta * $tariffa_totale, 2);

                                        if ($best_kind === 'coperto') continue;
                                        if ($best_kind === 'nuovo' || $diff < $best_diff) {
                                            $best_kind        = 'estendibile';
                                            $best_diff        = $diff;
                                            $biglietto        = $bgl;
                                            $costo_pagato     = (float)$bgl['COSTO'];
                                            $costo_differenza = $diff;

                                            $partenza_union = ($min_big <= $min_req)
                                                              ? $bgl['STAZ_P']
                                                              : $partenza;
                                            $arrivo_union   = ($max_big >= $max_req)
                                                              ? $bgl['STAZ_A']
                                                              : $arrivo;
                                        }
                                    }

                                    switch ($best_kind) {
                                        case 'acquistato': $gia_acquistato = true; break;
                                        case 'coperto':    $gia_coperto    = true; break;
                                        case 'estendibile':$estendibile    = true; break;
                                    }
                                }
                            }
                        ?>
                        <tr>
                            <td>
                                <span class="fw-bold text-primary">
                                    <?php echo htmlspecialchars($corsa['NOME_TRENO'] ?? 'Treno ' . $corsa['IDCORSA']); ?>
                                </span>
                                <?php if ($stato_prenotazione === 'chiusa_15min'): ?>
                                    <span class="badge bg-warning text-dark ms-1">
                                        <i class="bi bi-hourglass-split"></i> Prenotazioni chiuse
                                    </span>
                                <?php endif; ?>
                                <br>
                                <small class="badge <?php echo ($posti_rimanenti > 0) ? 'bg-light text-dark' : 'bg-danger'; ?> border">
                                    <?php echo ($posti_totali_treno > 0)
                                        ? "Disponibili: $posti_rimanenti / $posti_totali_treno"
                                        : "Posti disponibili"; ?>
                                </small>
                            </td>
                            <td>
                                <strong class="fs-5">
                                    <?php
                                    $partenza_reale = $corsa['PARTENZA_REALE'] ?? '--:--';
                                    $h_part = (int)substr($partenza_reale, 0, 2);
                                    echo substr($partenza_reale, 0, 5);
                                    if ($h_part < 6): ?>
                                        <sup class="text-warning fw-bold" title="Giorno successivo">+1</sup>
                                    <?php endif; ?>
                                </strong>
                            </td>
                            <td>
                                <span class="fs-5 text-muted">
                                    <?php
                                    $arrivo_reale = $corsa['ARRIVO_REALE'] ?? '--:--';
                                    $h_arr  = (int)substr($arrivo_reale, 0, 2);
                                    $h_part = (int)substr($partenza_reale, 0, 2);
                                    echo substr($arrivo_reale, 0, 5);
                                    if ($h_arr < 6 || ($h_part >= 20 && $h_arr < 12)): ?>
                                        <sup class="text-warning fw-bold" title="Giorno successivo">+1</sup>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td class="fs-5"><?php echo number_format($distanza, 1); ?> km</td>
                            <td class="fw-bold text-success fs-5"><?php echo number_format($prezzo_finale, 2); ?> €</td>
                            <td class="text-end">
                                <?php if ($id_utente): ?>

                                    <?php if ($gia_acquistato): ?>
                                        <a href="biglietti_riepilogo.php" class="btn btn-info btn-sm text-white px-3 fw-bold">
                                            <i class="bi bi-ticket-perforated me-1"></i>VEDI BIGLIETTO
                                        </a>

                                    <?php elseif ($gia_coperto): ?>
                                        <a href="biglietti_riepilogo.php" class="btn btn-outline-info btn-sm px-3 fw-bold">
                                            <i class="bi bi-check-circle me-1"></i>GIÀ COPERTO
                                        </a>

                                    <?php elseif ($stato_prenotazione === 'chiusa_15min'): ?>
                                        <button class="btn btn-warning btn-sm text-dark" disabled
                                                title="Le prenotazioni chiudono 15 minuti prima della partenza">
                                            <i class="bi bi-hourglass-split me-1"></i>PRENOTAZIONE NON PIÙ DISPONIBILE
                                        </button>
                                        <div class="small text-muted mt-1" style="font-size:0.7rem;">
                                            Chiusura 15 min prima della partenza
                                        </div>

                                    <?php elseif ($estendibile && $costo_differenza > 0): ?>
                                        <?php $posto_esistente = $biglietto['IDPOSTO']; ?>
                                        <a href="conferma_pagamento.php?idcorsa=<?php echo $id_corsa_attuale; ?>&prezzo=<?php echo $costo_differenza; ?>&data=<?php echo $data; ?>&partenza=<?php echo urlencode($partenza_union); ?>&arrivo=<?php echo urlencode($arrivo_union); ?>&estensione=1&posto=<?php echo $posto_esistente; ?>&idbiglietto=<?php echo $biglietto['IDBIGLIETTO']; ?>"
                                           class="btn btn-secondary btn-sm px-3 fw-bold shadow-sm"
                                           title="Estendi il biglietto esistente pagando solo i km scoperti">
                                            <i class="bi bi-arrows-expand me-1"></i>ESTENDI (+<?php echo number_format($costo_differenza, 2); ?> €)
                                        </a>

                                    <?php elseif ($posti_rimanenti > 0): ?>
                                        <a href="conferma_pagamento.php?idcorsa=<?php echo $id_corsa_attuale; ?>&prezzo=<?php echo $prezzo_finale; ?>&data=<?php echo $data; ?>&partenza=<?php echo urlencode($partenza); ?>&arrivo=<?php echo urlencode($arrivo); ?>"
                                           class="btn btn-warning btn-sm px-4 fw-bold shadow-sm">
                                            <i class="bi bi-cart-plus me-1"></i>ACQUISTA
                                        </a>

                                    <?php else: ?>
                                        <button class="btn btn-danger btn-sm" disabled>
                                            <i class="bi bi-x-circle me-1"></i>COMPLETO
                                        </button>
                                    <?php endif; ?>

                                <?php else: ?>
                                    <?php if ($stato_prenotazione === 'chiusa_15min'): ?>
                                        <button class="btn btn-outline-secondary btn-sm" disabled>
                                            PRENOTAZIONE CHIUSA
                                        </button>
                                    <?php else: ?>
                                        <a href="login.php" class="btn btn-outline-primary btn-sm px-3">
                                            <i class="bi bi-box-arrow-in-right me-1"></i>Accedi per acquistare
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            Nessuna corsa disponibile per i criteri selezionati.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    
</div>
</div>
<div class="text-center mt-auto mb-5 pt-5">
        <a href="index.php" class="btn btn-link text-decoration-none text-muted">
            <i class="bi bi-house-door"></i> Torna alla Home
        </a>
    </div>
    
<footer class="bg-dark text-white py-4 mt-auto">
    <div class="container text-center">
        <p class="mb-0">&copy; 2026 SFT - Società Ferroviaria Turistica</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

