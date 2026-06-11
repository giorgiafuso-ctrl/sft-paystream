<?php
session_name('SFT_SESSION'); session_start();
include('connessione.php');

$stato     = "";
$errore_db = "";

//Recupero e sanificazione dati
$id_corsa     = intval($_POST['idcorsa'] ?? 0);
$prezzo       = floatval($_POST['prezzo'] ?? 0);


$posto_raw = $_POST['posto'] ?? '0|';
$posto_parts = explode('|', $posto_raw);
$posto = intval($posto_parts[0]);
$matricola_sel = $con->real_escape_string($posto_parts[1] ?? '');

$stazionep    = $con->real_escape_string($_POST['stazione_p'] ?? '');
$stazionea    = $con->real_escape_string($_POST['stazione_a'] ?? '');
$id_utente    = intval($_SESSION['sft_id'] ?? 0);
$is_estensione = isset($_POST['estensione']) && $_POST['estensione'] == 1;
$id_biglietto_da_estendere = intval($_POST['idbiglietto'] ?? 0);

if ($id_corsa > 0 && $id_utente > 0) {

   if ($is_estensione && $id_biglietto_da_estendere > 0) {
    
    // Debug    error_log("Estensione: stazionep=$stazionep, stazionea=$stazionea, prezzo=$prezzo, biglietto=$id_biglietto_da_estendere");
    
    $res_p = $con->query("SELECT IDSTAZIONE FROM SFT_STAZIONE WHERE NOME = '$stazionep'");
    $res_a = $con->query("SELECT IDSTAZIONE FROM SFT_STAZIONE WHERE NOME = '$stazionea'");
    
    $id_st_p = ($res_p && $res_p->num_rows > 0) ? $res_p->fetch_assoc()['IDSTAZIONE'] : 0;
    $id_st_a = ($res_a && $res_a->num_rows > 0) ? $res_a->fetch_assoc()['IDSTAZIONE'] : 0;

    if ($id_st_p > 0 && $id_st_a > 0) {

        $sql_update = "UPDATE SFT_BIGLIETTO SET 
                        IDSTAZIONEP = $id_st_p,
                        IDSTAZIONEA = $id_st_a,
                        COSTO = COSTO + $prezzo
                       WHERE IDBIGLIETTO = $id_biglietto_da_estendere AND CODUTENTE = $id_utente";

        if ($con->query($sql_update)) {
            $stato = "successo";
            // Svuota cache dopo prenotazione
            $files = glob(__DIR__ . '/cache/*.json');
            foreach($files as $file){
                if(is_file($file)) unlink($file);
            }
        } else {
            $stato = "errore";
            $errore_db = "Errore durante l'aggiornamento del biglietto: " . $con->error;
        }
    } else {
        $stato = "errore";
        $errore_db = "Stazioni non trovate: P='$stazionep' ($id_st_p), A='$stazionea' ($id_st_a)";
    }
    } else {
      //Controllo preventivo con sovrapposizione tratte (funziona SUD e NORD)
        $res_prog_p = $con->query("SELECT F.PROGRESSIVO FROM SFT_FERMATA F 
                                JOIN SFT_STAZIONE S ON F.IDSTAZIONE = S.IDSTAZIONE 
                                WHERE F.IDCORSA = $id_corsa AND S.NOME = '$stazionep'");
        $res_prog_a = $con->query("SELECT F.PROGRESSIVO FROM SFT_FERMATA F 
                                JOIN SFT_STAZIONE S ON F.IDSTAZIONE = S.IDSTAZIONE 
                                WHERE F.IDCORSA = $id_corsa AND S.NOME = '$stazionea'");
        $prog_p = ($res_prog_p && $res_prog_p->num_rows > 0) ? $res_prog_p->fetch_assoc()['PROGRESSIVO'] : 0;
        $prog_a = ($res_prog_a && $res_prog_a->num_rows > 0) ? $res_prog_a->fetch_assoc()['PROGRESSIVO'] : 0;

        $min_new = min($prog_p, $prog_a);
        $max_new = max($prog_p, $prog_a);

        $check_sql = "SELECT B.IDBIGLIETTO 
                    FROM SFT_BIGLIETTO B
                    JOIN SFT_FERMATA FP ON FP.IDCORSA = B.IDCORSA AND FP.IDSTAZIONE = B.IDSTAZIONEP
                    JOIN SFT_FERMATA FA ON FA.IDCORSA = B.IDCORSA AND FA.IDSTAZIONE = B.IDSTAZIONEA
                    WHERE B.IDCORSA = $id_corsa 
                    AND B.IDPOSTO = $posto 
                    AND B.MATRICOLAMATERIALE = '$matricola_sel'
                    AND $max_new > LEAST(FP.PROGRESSIVO, FA.PROGRESSIVO)
                    AND $min_new < GREATEST(FP.PROGRESSIVO, FA.PROGRESSIVO)";
        $res_check = $con->query($check_sql);

        if ($res_check && $res_check->num_rows > 0) {


            $stato = "errore";
            $errore_db = "Spiacenti, il posto $posto risulta già occupato per questa corsa.";
        } else {
            // matricola selezionata dall'utente
                if (!empty($matricola_sel)) {
                    $matricola = $matricola_sel;

                // Recupero ID stazioni
                $sql_id = "SELECT IDSTAZIONE, NOME FROM SFT_STAZIONE WHERE NOME IN ('$stazionep', '$stazionea')";
                $res_id = $con->query($sql_id);
                $ids = [];
                while ($row = $res_id->fetch_assoc()) { $ids[$row['NOME']] = $row['IDSTAZIONE']; }

                if (isset($ids[$stazionep]) && isset($ids[$stazionea])) {
                    $id_st_p = $ids[$stazionep];
                    $id_st_a = $ids[$stazionea];

                    // Inserimento nuovo biglietto
                    $sql_insert = "INSERT INTO SFT_BIGLIETTO 
                                    (CODUTENTE, IDCORSA, IDPOSTO, MATRICOLAMATERIALE, COSTO, 
                                     IDSTAZIONEP, IDSTAZIONEA, DATAEMISSIONE, STATOPAGAMENTO, METODOPAGAMENTO)
                                   VALUES 
                                    ($id_utente, $id_corsa, $posto, '$matricola', $prezzo, 
                                     $id_st_p, $id_st_a, CURDATE(), 'Pagato', 'PaySteam')";

                    if ($con->query($sql_insert)) {
                        $stato = "successo";
                        // Svuota cache dopo prenotazione
                        $files = glob(__DIR__ . '/cache/*.json');
                        foreach($files as $file){
                            if(is_file($file)) unlink($file);
                        }
                    } else {
                        $stato = "errore";
                        $errore_db = "Errore durante la creazione del biglietto: " . $con->error;
                    }
                } else {
                    $stato = "errore";
                    $errore_db = "Rilevato errore nei nomi delle stazioni.";
                }
            } else {
                $stato = "errore";
                $errore_db = "Posto non valido.";
            }
        }
    }
} else {
    $stato = "errore";
    $errore_db = "Sessione scaduta o dati mancanti.";
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>SFT - Esito Pagamento</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="stile.css">
</head>
<body class="bg-light">

<?php $ruolo = $_SESSION['sft_ruolo'] ?? ''; ?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">SFT - Società Ferroviarie Turistiche</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center gap-1">
                <?php if (isset($_SESSION['sft_id'])): ?>
                    <li class="nav-item me-2 text-white d-flex align-items-center">
                        <i class="bi bi-person-badge me-1"></i>
                        Ciao, <strong class="ms-1"><?php echo htmlspecialchars($_SESSION['sft_nome']); ?></strong>
                        <span class="badge bg-warning text-dark ms-2"><?php echo $ruolo ?: 'Registrato'; ?></span>
                    </li>
                    <li class="nav-item">
                        <a href="index.php" class="btn btn-outline-light btn-sm px-2" title="Home">
                            <i class="bi bi-house"></i>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="logout.php" class="btn btn-danger btn-sm px-3">
                            <i class="bi bi-box-arrow-right"></i> Esci
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<header class="hero-bg text-center shadow">
    <div class="container">
        <h1 class="display-5 fw-bold">
            <?php if ($stato == "successo"): ?>
                <i class="bi bi-check-circle me-2"></i> Pagamento Completato
            <?php else: ?>
                <i class="bi bi-x-circle me-2"></i> Pagamento Fallito
            <?php endif; ?>
        </h1>
    </div>
</header>

<main>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card shadow border-0 text-center p-5">

            <?php if ($stato == "successo"): ?>
                <div style="font-size:3.5rem; color: var(--sft-verde); margin-bottom:1rem;">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <h2 class="fw-bold mb-2" style="color: var(--sft-verde);">Transazione Completata!</h2>
                <p class="text-muted mb-1">Il biglietto per il posto</p>
                <p class="fw-bold fs-4 mb-4" style="color: var(--sft-blu);"><?php echo $posto; ?></p>
                <p class="text-muted">è stato registrato con successo.</p>
                <a href="biglietti_riepilogo.php" class="btn btn-success btn-lg mt-3 w-100">
                    <i class="bi bi-ticket-perforated me-2"></i> I miei Biglietti
                </a>

            <?php else: ?>
                <div style="font-size:3.5rem; color:#dc2626; margin-bottom:1rem;">
                    <i class="bi bi-x-circle-fill"></i>
                </div>
                <h2 class="fw-bold mb-2" style="color:#dc2626;">Operazione Fallita</h2>
                <p class="text-muted">Si è verificato un problema tecnico:</p>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($errore_db); ?>
                </div>
                <a href="index.php" class="btn btn-primary mt-3 w-100">
                    <i class="bi bi-arrow-left me-2"></i> Riprova
                </a>
            <?php endif; ?>

            <hr class="my-4">
            <a href="index.php" class="text-muted small">
                <i class="bi bi-house me-1"></i> Torna alla Home
            </a>

        </div>
    </div>
</div>
        </div>
    </div>
</div>
</body>
</html>