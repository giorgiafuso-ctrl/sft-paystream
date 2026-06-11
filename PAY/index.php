<?php
session_name('PAY_SESSION'); session_start();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PaySteam - Pagamenti Digitali</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="stile.css">
    <style>
        .steps-timeline {
            position: relative;
            padding: 0;
        }
        .step-item {
            display: flex;
            gap: 20px;
            position: relative;
            padding-bottom: 30px;
        }
        .step-item:last-child {
            padding-bottom: 0;
        }
        .step-number {
            width: 48px;
            height: 48px;
            min-width: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1a3a6b 0%, #2563eb 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
            position: relative;
            z-index: 2;
            box-shadow: 0 4px 12px rgba(26, 58, 107, 0.3);
        }
        .step-item:not(:last-child) .step-number::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            width: 3px;
            height: calc(100% + 30px);
            background: linear-gradient(180deg, #2563eb, #e2e8f0);
            z-index: 1;
        }
        .step-content {
            flex: 1;
            padding-top: 8px;
        }
        .step-content h5 {
            font-weight: 700;
            color: #1a3a6b;
            margin-bottom: 6px;
        }
        .step-content p {
            color: #64748b;
            margin-bottom: 0;
            font-size: 0.95rem;
        }
        @media (max-width: 576px) {
            .step-number {
                width: 40px;
                height: 40px;
                min-width: 40px;
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">
            <i class="bi bi-credit-card-2-front me-1"></i> PaySteam
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            
                <?php if (isset($_SESSION['pay_id'])): ?>
                    <li class="nav-item me-2 text-white d-flex align-items-center">
                        <i class="bi bi-person-badge me-1"></i>
                        Ciao, <strong class="ms-1"><?php echo htmlspecialchars($_SESSION['pay_nome']); ?></strong>
                        <span class="badge bg-warning ms-2"><?php echo htmlspecialchars($_SESSION['pay_tipo']); ?></span>
                    </li>

                    <ul class="navbar-nav ms-auto align-items-center gap-1">
                    <li class="nav-item">
                        <a href="#come-funziona" class="btn btn-outline-light btn-sm px-3">
                            <i class="bi bi-info-circle"></i> Come funziona
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#condizioni" class="btn btn-outline-light btn-sm px-3">
                            <i class="bi bi-file-text"></i> Condizioni
                        </a>
                    </li>

                
                    <?php if ($_SESSION['pay_tipo'] === 'ESERCENTE'): ?>
                        <li class="nav-item">
                            <a href="/esercente.php" class="btn btn-warning btn-sm fw-bold px-3">
                                <i class="bi bi-shop"></i> La mia area
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a href="dashboard.php" class="btn btn-outline-light btn-sm px-3">
                                <i class="bi bi-house"></i> Dashboard
                            </a>
                        </li>
                    <?php endif; ?>

                    <li class="nav-item">
                        <a href="logout.php" class="btn btn-danger btn-sm px-3">
                            <i class="bi bi-box-arrow-right"></i> Esci
                        </a>
                    </li>

                <?php else: ?>
                    <ul class="navbar-nav ms-auto align-items-center gap-1">
                    <li class="nav-item">
                        <a href="#come-funziona" class="btn btn-outline-light btn-sm px-3">
                            <i class="bi bi-info-circle"></i> Come funziona
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#condizioni" class="btn btn-outline-light btn-sm px-3">
                            <i class="bi bi-file-text"></i> Condizioni
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
        <h1 class="display-4 fw-bold">
            <i class="bi bi-credit-card-2-front me-2"></i>PaySteam
        </h1>
        <p class="lead">Paga online beni e servizi in modo semplice, sicuro e tracciato.</p>

        <?php if (!isset($_SESSION['pay_id'])): ?>
        <div class="d-flex justify-content-center gap-3 mt-4 flex-wrap">
            <a href="registrazione.php" class="btn btn-light btn-lg fw-bold px-5">
                <i class="bi bi-person-plus me-1"></i> Crea account gratuito
            </a>
            <a href="login.php" class="btn btn-outline-light btn-lg px-4">
                <i class="bi bi-box-arrow-in-right me-1"></i> Accedi
            </a>
        </div>
        <?php endif; ?>
    </div>
</header>

<main>
<div class="container">

    <div class="card shadow border-0 mb-5" style="margin-top: -40px;">
        <div class="card-body p-4">
            <h4 class="mb-3"><i class="bi bi-wallet2 me-2"></i>Il tuo wallet digitale</h4>
            <p class="text-muted mb-0">
                PaySteam consente ai <strong>consumatori</strong> di autorizzare pagamenti online
                e agli <strong>esercenti</strong> di ricevere incassi tramite una semplice
                integrazione web API. Ogni transazione viene confermata esplicitamente dall'utente.
            </p>
        </div>
    </div>

    <!-- COME FUNZIONA-->
    <section id="come-funziona" class="mb-5">
        <div class="card border-0 shadow">
            <div class="card-body p-4 p-md-5">
                <h3 class="mb-4 fw-bold">
                    <i class="bi bi-diagram-3 me-2 text-primary"></i>Come funziona in 4 passaggi
                </h3>
                
                <div class="steps-timeline">
                    <div class="step-item">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h5><i class="bi bi-cart-check me-2"></i>Acquisti su un servizio</h5>
                            <p>Ad esempio selezioni un biglietto ferroviario sul sito SFT o acquisti un prodotto su un e-commerce partner.</p>
                        </div>
                    </div>
                    
                    <div class="step-item">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h5><i class="bi bi-credit-card me-2"></i>Arrivi su PaySteam</h5>
                            <p>Visualizzi il riepilogo della transazione: esercente, descrizione e importo da pagare.</p>
                        </div>
                    </div>
                    
                    <div class="step-item">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h5><i class="bi bi-shield-check me-2"></i>Accedi e confermi</h5>
                            <p>Inserisci le tue credenziali PaySteam e autorizzi esplicitamente la transazione con un click.</p>
                        </div>
                    </div>
                    
                    <div class="step-item">
                        <div class="step-number">4</div>
                        <div class="step-content">
                            <h5><i class="bi bi-check-circle me-2"></i>Esito al servizio</h5>
                            <p>PaySteam invia l'esito (<code class="bg-success text-white px-2 rounded">OK</code> o <code class="bg-danger text-white px-2 rounded">KO</code>) al sistema chiamante e vieni reindirizzato.</p>
                        </div>
                    </div>
                </div>

                <?php if (!isset($_SESSION['pay_id'])): ?>
                <div class="mt-4 pt-3 border-top text-center">
                    <span class="text-muted">Hai già un account?</span>
                    <a href="login.php" class="btn btn-link fw-semibold p-0 ms-1">Accedi</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Per chi è PaySteam -->
    <section class="mb-5">
        <h3 class="mb-4"><i class="bi bi-people me-2"></i>Per chi è PaySteam</h3>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex p-3 mb-3">
                            <i class="bi bi-person text-primary fs-4"></i>
                        </div>
                        <h5 class="fw-bold">Consumatore</h5>
                        <ul class="text-muted mb-0 ps-3">
                            <li>Visualizza saldo e movimenti</li>
                            <li>Autorizza transazioni esplicitamente</li>
                            <li>Salva più carte di credito</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="rounded-circle bg-warning bg-opacity-10 d-inline-flex p-3 mb-3">
                            <i class="bi bi-shop text-warning fs-4"></i>
                        </div>
                        <h5 class="fw-bold">Esercente</h5>
                        <ul class="text-muted mb-0 ps-3">
                            <li>Riceve pagamenti sul proprio conto</li>
                            <li>Visualizza incassi e storico</li>
                            <li>Integra PaySteam via API</li>
                        </ul>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <section id="condizioni" class="card border-0 shadow mb-5">
        <div class="card-body p-4">
            <h4 class="mb-4"><i class="bi bi-file-text me-2"></i>Condizioni del servizio</h4>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="mb-3 p-3 rounded" style="background:#f0f5ff; border-left: 3px solid #1a3a6b;">
                        <strong><i class="bi bi-person-check me-1 text-primary"></i> Registrazione obbligatoria</strong>
                        <p class="text-muted mb-0 mt-1" style="font-size:0.9rem;">
                            Il servizio è utilizzabile esclusivamente da utenti registrati. Gli esercenti devono fornire Partita IVA valida.
                        </p>
                    </div>

                    <div class="mb-3 p-3 rounded" style="background:#f0f5ff; border-left: 3px solid #1a3a6b;">
                        <strong><i class="bi bi-shield-check me-1 text-primary"></i> Sicurezza</strong>
                        <p class="text-muted mb-0 mt-1" style="font-size:0.9rem;">
                            Le password sono cifrate con BCrypt. I dati delle carte non vengono mai trasmessi agli esercenti.
                        </p>
                    </div>

                    <div class="mb-0 p-3 rounded" style="background:#f0f5ff; border-left: 3px solid #1a3a6b;">
                        <strong><i class="bi bi-check2-circle me-1 text-primary"></i> Autorizzazione esplicita</strong>
                        <p class="text-muted mb-0 mt-1" style="font-size:0.9rem;">
                            Ogni transazione richiede la conferma esplicita del consumatore. Nessun addebito automatico.
                        </p>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-3 p-3 rounded" style="background:#f0f5ff; border-left: 3px solid #1a3a6b;">
                        <strong><i class="bi bi-wallet2 me-1 text-primary"></i> Conto PaySteam</strong>
                        <p class="text-muted mb-0 mt-1" style="font-size:0.9rem;">
                            Ogni utente dispone di un conto interno. I pagamenti scalano il saldo del consumatore e accreditano l'esercente.
                        </p>
                    </div>

                    <div class="mb-3 p-3 rounded" style="background:#f0f5ff; border-left: 3px solid #1a3a6b;">
                        <strong><i class="bi bi-arrow-left-right me-1 text-primary"></i> Transazioni irreversibili</strong>
                        <p class="text-muted mb-0 mt-1" style="font-size:0.9rem;">
                            Le transazioni completate sono definitive. Per contestazioni contattare direttamente l'esercente.
                        </p>
                    </div>

                    <div class="mb-0 p-3 rounded" style="background:#f0f5ff; border-left: 3px solid #1a3a6b;">
                        <strong><i class="bi bi-plug me-1 text-primary"></i> Risposta API</strong>
                        <p class="text-muted mb-0 mt-1" style="font-size:0.9rem;">
                            L'applicazione chiamante riceve l'esito della transazione: <code>OK</code> se completata, <code>KO</code> se rifiutata o fallita.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php if (!isset($_SESSION['pay_id'])): ?>
    <div class="card border-0 shadow mb-5" style="background: linear-gradient(135deg, #0d1f3c, #1a3a6b);">
        <div class="card-body p-5 text-center text-white">
            <h3 class="fw-bold mb-2">Pronto a iniziare?</h3>
            <p class="mb-4" style="opacity:0.8;">Registrati gratuitamente in meno di un minuto.</p>
            <div class="d-flex justify-content-center gap-3 flex-wrap">
                <a href="registrazione.php" class="btn btn-light btn-lg fw-bold px-5">
                    <i class="bi bi-person-plus me-1"></i> Crea account
                </a>
                <a href="login.php" class="btn btn-light btn-lg px-4 fw-bold" style="color:#1a3a6b;">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Ho già un account
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>
</main>

<footer class="text-white py-4 mt-3">
    <div class="container text-center">
        <p class="mb-0">&copy; 2026 PaySteam &mdash; Pagamenti digitali sicuri</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

