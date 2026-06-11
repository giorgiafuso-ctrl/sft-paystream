<?php
session_name('SFT_SESSION'); session_start();
include('connessione.php');

$risultato = $con->query("SELECT * FROM SFT_STAZIONE ORDER BY KMPROGRESSIVO ASC");
$stazioni_list = [];
if ($risultato && $risultato->num_rows > 0) {
    $stazioni_list = $risultato->fetch_all(MYSQLI_ASSOC);
    $risultato->data_seek(0);
}
//vincolo 15 min
$msg_code = $_GET['msg']    ?? '';
$reason   = $_GET['reason'] ?? '';
$alert_html = '';
$alert_type = 'warning';

if ($msg_code === 'prenotazione_chiusa') {
    $alert_type = 'warning';
    switch ($reason) {
        case 'chiusa_15min':
            $alert_html = '<strong>Prenotazione non disponibile.</strong> La corsa scelta parte tra meno di 15 minuti: non è più possibile acquistare o modificare biglietti.';
            break;
        case 'corsa_passata':
            $alert_html = '<strong>Corsa già partita.</strong> L\'operazione non può essere completata.';
            break;
        case 'corsa_non_attiva':
            $alert_html = '<strong>Corsa non attiva.</strong> La corsa è stata annullata o è già conclusa.';
            break;
        case 'stazione_non_sulla_corsa':
            $alert_html = '<strong>Fermata non valida.</strong> La stazione selezionata non è servita dalla corsa.';
            break;
        default:
            $alert_html = '<strong>Operazione non consentita.</strong> Riprova con un altro treno o un\'altra data.';
    }
} elseif ($msg_code === 'pagamento_annullato') {
    $alert_type = 'danger';
    $alert_html = '<strong>Pagamento annullato.</strong> Nessun importo è stato addebitato.';
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>SFT - Società Ferroviaria Turistica</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
     <link rel="stylesheet" href="stile.css">

    <style>
            .linea-treno {
                position: relative;
                height: 4px;
                background: linear-gradient(90deg, #1a3a6b, #004a99, #1a3a6b);
                margin: 50px 0 60px 0;
                border-radius: 2px;
            }

            .punto-stazione {
                position: absolute;
                top: -13px;
                text-align: center;
            }

            .cerchio {
                width: 28px;
                height: 28px;
                background: #fff;
                border: 4px solid #004a99;
                border-radius: 50%;
                margin: 0 auto;
                transition: all 0.2s ease;
                cursor: pointer;
            }

            .cerchio:hover {
                transform: scale(1.2);
                background: #004a99;
                border-color: #1a3a6b;
                box-shadow: 0 4px 12px rgba(0,74,153,0.4);
            }

            .cerchio.capolinea {
                width: 32px;
                height: 32px;
                background: #ffc107;
                border-color: #1a3a6b;
            }

            .cerchio.capolinea:hover {
                background: #ffca2c;
            }
            .border-start.border-4:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(0,0,0,0.1) !important;
                transition: all 0.2s ease;
            }

    </style>
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
                        <span class="badge bg-warning text-dark ms-2">
                            <?php echo $ruolo ?: 'Registrato'; ?>
                        </span>
                    </li>

                    <li class="nav-item">
                        <a href="tabella_orari.php" class="btn btn-outline-light btn-sm px-2" title="Orari">
                            <i class="bi bi-clock-history"></i>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="info.php" class="btn btn-outline-light btn-sm px-2" title="Info">
                            <i class="bi bi-info-circle"></i>
                        </a>
                    </li>

                    <?php if ($ruolo === 'Amministrativo'): ?>
                        <li class="nav-item">
                            <a class="btn btn-warning btn-sm fw-bold shadow-sm" href="admin_amministrativo.php">
                                <i class="bi bi-graph-up-arrow"></i> Amministrazione
                            </a>
                        </li>
                    <?php elseif ($ruolo === 'Esercizio'): ?>
                        <li class="nav-item">
                            <a class="btn btn-primary btn-sm fw-bold shadow-sm" href="admin_esercizio.php">
                                <i class="bi bi-train-front"></i> Esercizio
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a href="biglietti_riepilogo.php" class="btn btn-outline-info btn-sm text-white border-white">
                                <i class="bi bi-ticket-perforated"></i> I miei Biglietti
                            </a>
                        </li>
                    <?php endif; ?>

                    <li class="nav-item">
                        <a href="logout.php" class="btn btn-danger btn-sm px-3">
                            <i class="bi bi-box-arrow-right"></i> Esci
                        </a>
                    </li>

                <?php else: ?>

                    <li class="nav-item">
                        <a href="tabella_orari.php" class="btn btn-outline-light btn-sm px-2" title="Orari">
                            <i class="bi bi-clock"></i> Orari
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="info.php" class="btn btn-outline-light btn-sm px-2" title="Info">
                            <i class="bi bi-info-circle"></i> Info
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="login.php" class="btn btn-outline-light btn-sm px-3">
                            <i class="bi bi-box-arrow-in-right"></i> Accedi
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="registrazione.php" class="btn btn-primary btn-sm px-3">
                            <i class="bi bi-person-plus"></i> Registrati
                        </a>
                    </li>

                <?php endif; ?>

            </ul>
        </div>
    </div>
</nav>

<header class="hero-bg text-center shadow">
    <div class="container">
        <h1 class="display-4 fw-bold">Benvenuti sulla Linea SFT</h1>
        <p class="lead">Scegli la tua destinazione e viaggia con noi!</p>
    </div>
</header>

<main>
    <div class="container">

    <?php if ($alert_html !== ''): ?>
        <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show shadow-sm border-0 mt-4 rounded-3" role="alert">
            <i class="bi bi-<?= $alert_type === 'danger' ? 'x-octagon-fill' : 'exclamation-triangle-fill' ?> me-2"></i>
            <?= $alert_html ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
        </div>
    <?php endif; ?>

    <?php if ($ruolo !== 'Amministrativo' && $ruolo !== 'Esercizio'): ?>
    <!-- SEZIONE CLIENTI-->

    <div class="card shadow border-0 mb-5" style="margin-top: -50px;">
        <div class="card-body p-4">
            <h4 class="mb-3 text-primary">Prenota il tuo viaggio</h4>
            <form action="acquisto.php" method="GET" class="row g-3 align-items-end" onsubmit="return validaRicerca()">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Partenza</label>
                    <select name="partenza" id="partenza" class="form-select" required>
                        <option value="" disabled selected>Da dove parti?</option>
                        <?php foreach ($stazioni_list as $r): ?>
                            <option value="<?php echo $r['NOME']; ?>"><?php echo $r['NOME']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Arrivo</label>
                    <select name="arrivo" id="arrivo" class="form-select" required>
                        <option value="" disabled selected>Dove vai?</option>
                        <?php foreach ($stazioni_list as $r): ?>
                            <option value="<?php echo $r['NOME']; ?>"><?php echo $r['NOME']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Data</label>
                    <input type="date" name="data" class="form-control"
                           value="<?php echo date('Y-m-d'); ?>"
                           min="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100 fw-bold">CERCA TRENI</button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($ruolo === 'Amministrativo'): ?>
    <!--SEZIONE AMMINISTRATIVO-->

    <div class="py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-1" style="color:#1a3a6b;">
                    <i class="bi bi-graph-up-arrow me-2"></i>Area Amministrativa
                </h4>
                <p class="text-muted mb-0 small">Panoramica generale del sistema</p>
            </div>
            <a href="admin_amministrativo.php" class="btn btn-warning fw-bold shadow-sm">
                <i class="bi bi-gear me-1"></i> Gestione
            </a>
        </div>

        <?php
        $n_utenti   = $con->query("SELECT COUNT(*) as tot FROM SFT_UTENTE")->fetch_assoc()['tot'];
        $n_stazioni = $con->query("SELECT COUNT(*) as tot FROM SFT_STAZIONE")->fetch_assoc()['tot'];
        $n_biglietti_attivi = $con->query("
            SELECT COUNT(*) AS tot
            FROM SFT_BIGLIETTO B
            JOIN SFT_CORSA C ON B.IDCORSA = C.IDCORSA
            WHERE B.STATOPAGAMENTO = 'Pagato'
            AND C.STATO IN ('Programmata','In Viaggio')
            AND C.DATA >= CURDATE()
        ")->fetch_assoc()['tot'];
        ?>

        <div class="d-flex gap-4 mb-4 flex-wrap">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-people-fill text-primary fs-5"></i>
                <div>
                    <div class="fw-bold" style="color:#1a3a6b; line-height:1;"><?php echo $n_utenti; ?></div>
                    <small class="text-muted">Utenti</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2"
                title="Solo biglietti Pagati e con corsa non Annullata (i biglietti eliminati non sono più in DB)">
                <i class="bi bi-ticket-perforated-fill text-warning fs-5"></i>
                <div>
                    <div class="fw-bold" style="color:#1a3a6b; line-height:1;">
                        <?php echo $n_biglietti_attivi; ?>
                    </div>
                    <small class="text-muted">Biglietti attivi</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-geo-alt-fill text-danger fs-5"></i>
                <div>
                    <div class="fw-bold" style="color:#1a3a6b; line-height:1;"><?php echo $n_stazioni; ?></div>
                    <small class="text-muted">Stazioni</small>
                </div>
            </div>
        </div>

    <?php else: ?>
    <!--SEZIONE ESERCIZIO-->

   <div class="py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-1" style="color:#1a3a6b;">
                    <i class="bi bi-speedometer2 me-2"></i>Pannello Esercizio
                </h4>
                <p class="text-muted mb-0 small">Gestione operativa circolazione</p>
            </div>
            <a href="admin_esercizio.php" class="btn btn-primary fw-bold shadow-sm">
                <i class="bi bi-gear me-1"></i> Gestione
            </a>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <a href="tabella_orari.php?data=<?php echo date('Y-m-d'); ?>"
                   class="d-block p-3 bg-white rounded shadow-sm text-decoration-none border-start border-4 border-primary">
                    <div class="d-flex align-items-center gap-3">
                        <i class="bi bi-table fs-4 text-primary"></i>
                        <div>
                            <div class="fw-bold" style="color:#1a3a6b;">Tabellone Orari</div>
                            <small class="text-muted">Corse, incroci, ritardi</small>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <a href="diagramma_orario.php?data=<?php echo date('Y-m-d'); ?>"
                   class="d-block p-3 bg-white rounded shadow-sm text-decoration-none border-start border-4 border-success">
                    <div class="d-flex align-items-center gap-3">
                        <i class="bi bi-graph-up fs-4 text-success"></i>
                        <div>
                            <div class="fw-bold" style="color:#1a3a6b;">Diagramma Orario</div>
                            <small class="text-muted">Grafico tempo-spazio</small>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-white rounded shadow-sm border-start border-4 border-secondary">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-calendar3 fs-5 text-secondary"></i>
                        <input type="date" id="data_operativa" class="form-control form-control-sm border-0"
                               value="<?php echo date('Y-m-d'); ?>" onchange="aggiornaLinks()">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <!--LINEA SFT (tutti)-->
    <div class="mb-5">
        <h5 class="mb-3" style="color:#1a3a6b;">
            <i class="bi bi-train-front me-2"></i>Linea SFT
        </h5>
        <div class="linea-treno">
            <?php
            $totale_stazioni = count($stazioni_list);
            foreach ($stazioni_list as $contatore => $riga) {
                $pos = ($totale_stazioni > 1) ? ($contatore / ($totale_stazioni - 1)) * 100 : 50;
                $nome_enc = urlencode($riga['NOME']);
                $isCapolinea = ($contatore === 0 || $contatore === $totale_stazioni - 1);
            ?>
            <div class="punto-stazione" style="left: <?php echo $pos; ?>%; transform: translateX(-50%);">
                <a href="info.php?stazione=<?php echo $nome_enc; ?>"
                   title="<?php echo $riga['NOME']; ?> - km <?php echo $riga['KMPROGRESSIVO']; ?>">
                    <div class="cerchio <?php echo $isCapolinea ? 'capolinea' : ''; ?>"></div>
                </a>
                <small class="fw-bold d-block mt-1" style="font-size: 0.75rem; color:#1a3a6b;">
                    <?php echo $riga['NOME']; ?>
                </small>
            </div>
            <?php } ?>
        </div>
    </div>

    </div>
</main>

<footer class="bg-dark text-white py-4 mt-5">
    <div class="container text-center">
        <p class="mb-0">&copy; 2026 SFT - Società Ferroviaria Turistica</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
function validaRicerca() {
    var p = document.getElementById('partenza').value;
    var a = document.getElementById('arrivo').value;
    if (p === a) {
        alert("Errore: La stazione di arrivo deve essere diversa da quella di partenza.");
        return false;
    }
    return true;
}

function vaiAlTabellone() {
    var dataScelta = document.getElementById('data_tabellone').value;
    if(dataScelta == "") {
        alert("Per favore, seleziona prima una data.");
        return;
    }
    window.location.href = "tabella_orari.php?data=" + dataScelta;
}

function aggiornaLinks() {
    var data = document.getElementById('data_operativa')?.value;
    if (!data) return;
    var links = document.querySelectorAll('a[href*="tabella_orari"], a[href*="diagramma_orario"]');
    links.forEach(function(link) {
        var base = link.href.split('?')[0];
        link.href = base + '?data=' + data;
    });
}
</script>
</body>
</html>

