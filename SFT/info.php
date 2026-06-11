<?php
session_name('SFT_SESSION'); session_start();
include('connessione.php');

$stazione_selezionata = isset($_GET['stazione']) ? $_GET['stazione'] : null;
$ruolo = $_SESSION['sft_ruolo'] ?? '';

$sql = "SELECT * FROM SFT_STAZIONE ORDER BY KMPROGRESSIVO ASC";
$risultato = $con->query($sql);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Info Linea - SFT</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="stile.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">SFT - Società Ferroviarie Turistiche</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center gap-1">

                <?php if (isset($_SESSION['sft_id'])): ?>

                    <!-- Ciao, Nome + badge ruolo -->
                    <li class="nav-item me-2 text-white d-flex align-items-center">
                        <i class="bi bi-person-badge me-1"></i>
                        Ciao, <strong class="ms-1"><?php echo htmlspecialchars($_SESSION['sft_nome']); ?></strong>
                        <span class="badge bg-warning text-dark ms-2">
                            <?php echo $ruolo ?: 'Registrato'; ?>
                        </span>
                    </li>

                    <!-- Bottone contestuale per ruolo -->
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

                    <!-- Home -->
                    <li class="nav-item">
                        <a href="index.php" class="btn btn-outline-light btn-sm px-2" title="Home">
                            <i class="bi bi-house"></i>
                        </a>
                    </li>

                    <!-- Esci -->
                    <li class="nav-item">
                        <a href="logout.php" class="btn btn-danger btn-sm px-3">
                            <i class="bi bi-box-arrow-right"></i> Esci
                        </a>
                    </li>

                <?php else: ?>

                    <!-- Non loggato -->
                    <li class="nav-item">
                        <a href="tabella_orari.php?data=<?php echo date('Y-m-d'); ?>"
                           class="btn btn-outline-light btn-sm px-3">
                            <i class="bi bi-clock"></i> Orari
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="info.php" class="btn btn-outline-light btn-sm px-3 active">
                            <i class="bi bi-info-circle"></i> Info
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="login.php" class="btn btn-outline-light btn-sm px-3">Accedi</a>
                    </li>
                    <li class="nav-item">
                        <a href="registrazione.php" class="btn btn-primary btn-sm px-3">Registrati</a>
                    </li>

                <?php endif; ?>

            </ul>
        </div>
    </div>
</nav>

<header class="hero-bg text-center shadow">
    <div class="container">
        <h1 class="display-5 fw-bold">Informazioni sulla Linea SFT</h1>
        <p class="lead">Scopri le stazioni e i dettagli del percorso</p>
    </div>
</header>

<main>
    <div class="container py-4">

        <h3 class="mb-4">Mappa della Linea</h3>
        <div class="linea-treno shadow-sm mb-5">
            <?php
            $totale_stazioni = $risultato->num_rows;
            if ($totale_stazioni > 0) {
                $risultato->data_seek(0);
                $contatore = 0;
                while ($riga = $risultato->fetch_assoc()) {
                    $pos = ($totale_stazioni > 1) ? ($contatore / ($totale_stazioni - 1)) * 100 : 50;
                    echo "<div class='punto-stazione' style='left: {$pos}%; transform: translateX(-50%);'>";
                    echo "<div class='cerchio' title='KM: {$riga['KMPROGRESSIVO']}'></div>";
                    echo "<small class='fw-bold d-block mt-1' style='font-size: 0.75rem;'>{$riga['NOME']}</small>";
                    echo "</div>";
                    $contatore++;
                }
            }
            ?>
        </div>

        <h5 class="text-muted mb-3">Dettaglio Stazioni</h5>
        <table class="table table-sm table-hover table-bordered mt-2 bg-white shadow-sm">
            <thead class="table-secondary text-uppercase">
                <tr>
                    <th>Cod.</th>
                    <th>Nome</th>
                    <th>KM Progressivo</th>
                    <th>Descrizione Paesi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $risultato->data_seek(0);
                while ($riga = $risultato->fetch_assoc()) {
                    echo "<tr>
                            <td>{$riga['IDSTAZIONE']}</td>
                            <td class='fw-bold text-primary'>{$riga['NOME']}</td>
                            <td>{$riga['KMPROGRESSIVO']} km</td>
                            <td><small class='text-muted'>{$riga['DESCPAESI']}</small></td>
                          </tr>";
                }
                ?>
            </tbody>
        </table>

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