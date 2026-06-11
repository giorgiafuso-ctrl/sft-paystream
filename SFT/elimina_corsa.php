<?php
session_name('SFT_SESSION'); session_start();
include('connessione.php');
if (!isset($_SESSION['sft_id']) || $_SESSION['sft_ruolo'] !== 'Esercizio') {
    header("Location: index.php?errore=non_autorizzato"); exit();
}
$id = intval($_GET['id'] ?? 0);
if (!$id) { header("Location: admin_esercizio.php?msg=ID mancante"); exit(); }

$r = $con->query("SELECT C.IDCORSA, C.DATA, C.ORA, C.DIREZIONE, C.STATO, CO.NOME AS TRENO
                  FROM SFT_CORSA C JOIN SFT_CONVOGLIO CO ON C.IDCONVOGLIO=CO.IDCONVOGLIO
                  WHERE C.IDCORSA=$id");
if (!$r || $r->num_rows==0) { header("Location: admin_esercizio.php?msg=Corsa non trovata"); exit(); }
$corsa = $r->fetch_assoc();

$rb = $con->query("SELECT B.IDBIGLIETTO, B.COSTO, B.STATOPAGAMENTO, B.CODUTENTE,
                          S1.NOME PARTENZA, S2.NOME ARRIVO
                   FROM SFT_BIGLIETTO B
                   JOIN SFT_STAZIONE S1 ON B.IDSTAZIONEP=S1.IDSTAZIONE
                   JOIN SFT_STAZIONE S2 ON B.IDSTAZIONEA=S2.IDSTAZIONE
                   WHERE B.IDCORSA=$id");
$biglietti = $rb ? $rb->fetch_all(MYSQLI_ASSOC) : [];
$tot_pagati = 0; $n_pagati = 0; $n_altri = 0;
foreach ($biglietti as $b) {
    if ($b['STATOPAGAMENTO']==='Pagato') { $n_pagati++; $tot_pagati += floatval($b['COSTO']); }
    else $n_altri++;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>SFT - Elimina Corsa</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root {
            --sft-blu: #1e3a5f;
            --sft-blu-scuro: #0f172a;
            --sft-azzurro: #3b82f6;
            --sft-ambra: #d97706;
        }
        body { font-family: 'Inter', sans-serif; background: #f8fafc; min-height: 100vh; }
        .navbar-sft { background: linear-gradient(135deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 100%); }

        .card-conferma {
            max-width: 780px;
            margin: 30px auto;
            background: white;
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .card-conferma .hdr {
            background: linear-gradient(135deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 100%);
            color: white;
            padding: 24px 28px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .card-conferma .hdr h3 { font-weight: 700; margin: 0; font-size: 1.25rem; }
        .card-conferma .hdr small { opacity: 0.8; }
        .hdr .hdr-icon {
            background: rgba(255,255,255,0.12);
            width: 52px; height: 52px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.6rem;
        }

        .panel-info {
            background: #eff6ff;
            border-left: 4px solid var(--sft-azzurro);
            color: var(--sft-blu);
            padding: 14px 18px;
            border-radius: 8px;
            font-size: 0.92rem;
            line-height: 1.55;
        }
        .panel-attenzione {
            background: #fffbeb;
            border-left: 4px solid var(--sft-ambra);
            color: #78350f;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.88rem;
        }

        .stat-box {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            transition: all 0.2s;
        }
        .stat-box:hover { box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .stat-box .lbl {
            font-size: 0.7rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .stat-box .num {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--sft-blu);
            font-family: 'Courier New', monospace;
        }
        .stat-box.ambra { border-color: #fde68a; background: #fffbeb; }
        .stat-box.ambra .num { color: var(--sft-ambra); }

        .tabella-biglietti {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }
        .tabella-biglietti thead {
            background: #f8fafc;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
        }
        .tabella-biglietti td { font-size: 0.88rem; vertical-align: middle; }

        .pill-info {
            display: inline-block;
            background: var(--sft-blu);
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 600;
            margin: 0 4px;
        }

        .btn-annulla {
            background: white;
            color: var(--sft-blu);
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 22px;
            font-weight: 600;
            transition: all 0.15s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .btn-annulla:hover {
            background: #f1f5f9;
            border-color: var(--sft-blu);
            color: var(--sft-blu);
        }

        .btn-elimina {
            background: var(--sft-blu);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 10px 22px;
            font-weight: 600;
            transition: all 0.15s;
            box-shadow: 0 4px 10px rgba(30, 58, 95, 0.25);
        }
        .btn-elimina:hover {
            color: white;
            background: var(--sft-blu-scuro);
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(30, 58, 95, 0.35);
        }
        .btn-elimina .badge-refund {
            background: #fde68a;
            color: #78350f;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
            margin-left: 6px;
            font-size: 0.78rem;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark navbar-sft py-3">
    <div class="container">
        <div class="d-flex align-items-center gap-3">
            <a href="admin_esercizio.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left"></i></a>
            <div class="text-white">
                <h1 class="h5 fw-bold mb-0">Elimina Corsa</h1>
                <small class="text-white opacity-75">Conferma operazione</small>
            </div>
        </div>
    </div>
</nav>

<div class="container py-4">

    <div class="card-conferma">
        <div class="hdr">
            <div class="hdr-icon"><i class="bi bi-trash3"></i></div>
            <div>
                <h3>Eliminazione corsa #<?= $corsa['IDCORSA'] ?></h3>
                <small>Verifica i dettagli prima di procedere</small>
            </div>
        </div>

        <div class="p-4">

            <div class="panel-info mb-3">
                Stai per eliminare la corsa
                <span class="pill-info">#<?= $corsa['IDCORSA'] ?></span>
                del <strong><?= date('d/m/Y', strtotime($corsa['DATA'])) ?></strong>
                alle <strong><?= substr($corsa['ORA'],0,5) ?></strong>
                (<?= htmlspecialchars($corsa['DIREZIONE']) ?> · treno
                <strong><?= htmlspecialchars($corsa['TRENO']) ?></strong> · stato
                <?= htmlspecialchars($corsa['STATO']) ?>).
            </div>

            <?php if ($n_pagati > 0): ?>
            <div class="panel-attenzione mb-3">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Attenzione: ci sono <strong><?= $n_pagati ?></strong> biglietti pagati che verranno
                <strong>rimborsati automaticamente</strong> per un totale di
                <strong>€ <?= number_format($tot_pagati, 2, ',', '.') ?></strong>.
                L'operazione è irreversibile.
            </div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stat-box">
                        <div class="lbl"><i class="bi bi-ticket-perforated"></i> Biglietti totali</div>
                        <div class="num"><?= count($biglietti) ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box">
                        <div class="lbl"><i class="bi bi-check-circle"></i> Pagati da rimborsare</div>
                        <div class="num"><?= $n_pagati ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box <?= $tot_pagati > 0 ? 'ambra' : '' ?>">
                        <div class="lbl"><i class="bi bi-cash-coin"></i> Totale rimborso</div>
                        <div class="num">€ <?= number_format($tot_pagati, 2, ',', '.') ?></div>
                    </div>
                </div>
            </div>

            <?php if (count($biglietti) > 0): ?>
                <div class="table-responsive tabella-biglietti mb-4">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="px-3 py-2">ID</th>
                                <th class="py-2">Tratta</th>
                                <th class="py-2">Costo</th>
                                <th class="py-2">Stato</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($biglietti as $b): ?>
                                <tr>
                                    <td class="px-3">#<?= $b['IDBIGLIETTO'] ?></td>
                                    <td>
                                        <?= htmlspecialchars($b['PARTENZA']) ?>
                                        <i class="bi bi-arrow-right text-muted mx-1"></i>
                                        <?= htmlspecialchars($b['ARRIVO']) ?>
                                    </td>
                                    <td class="fw-semibold">€ <?= number_format($b['COSTO'],2,',','.') ?></td>
                                    <td>
                                        <?php if ($b['STATOPAGAMENTO'] === 'Pagato'): ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="bi bi-arrow-counterclockwise"></i> Da rimborsare
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($b['STATOPAGAMENTO']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="panel-info mb-4">
                    <i class="bi bi-info-circle"></i> Nessun biglietto associato a questa corsa.
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                <a href="admin_esercizio.php" class="btn-annulla">
                    <i class="bi bi-x-lg me-1"></i> Annulla
                </a>
                <form method="POST" action="esercizio.php" class="d-inline">
                    <input type="hidden" name="azione" value="elimina">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="conferma_elimina" value="1">
                    <button type="submit" class="btn-elimina"
                            onclick="return confirm('Sei sicuro? L\'operazione non è reversibile.');">
                        <i class="bi bi-trash3 me-1"></i>
                        Conferma eliminazione
                        <?php if ($n_pagati > 0): ?>
                            <span class="badge-refund">€ <?= number_format($tot_pagati,2,',','.') ?></span>
                        <?php endif; ?>
                    </button>
                </form>
            </div>

        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
