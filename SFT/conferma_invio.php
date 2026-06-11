<?php
session_name('SFT_SESSION'); session_start();

if (!isset($_SESSION['sft_id']) || $_SESSION['sft_ruolo'] !== 'Amministrazione') {
    header("Location: index.php?errore=non_autorizzato");
    exit();
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>SFT - Sistema Messaggistica Interna</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
 rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7">
            <div class="card shadow">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-envelope-fill me-2"></i>Client di Messaggistica Interna SFT</span>
                    <span class="badge bg-success"><i class="bi bi-check-lg"></i> Inviato con successo</span>
                </div>
                <div class="card-body p-4">

                    <div class="mb-3">
                        <label class="fw-bold text-muted small">TIPO COMUNICAZIONE</label>
                        <div class="fs-5 fw-bold text-primary">
                            <?php echo htmlspecialchars($_GET['tipo'] ?? ''); ?>
                        </div>
                    </div>

                    <hr>

                    <label class="fw-bold text-muted small">TESTO DELLA COMUNICAZIONE</label>
                    <div class="bg-light border p-3 rounded font-monospace mt-1" style="white-space: pre-wrap;">
                        <?php echo htmlspecialchars($_GET['msg'] ?? ''); ?>
                    </div>

                    <div class="alert alert-success mt-4 mb-0 d-flex justify-content-between align-items-center">
                        <span>
                            <i class="bi bi-check-circle-fill me-2"></i>
                            La richiesta è stata recapitata al Backoffice di Esercizio.
                        </span>
                        <a href="admin_amministrativo.php" class="btn btn-primary btn-sm ms-3">
                            <i class="bi bi-arrow-left"></i> Torna alla Dashboard
                        </a>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
