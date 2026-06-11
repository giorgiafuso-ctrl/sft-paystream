<?php
session_name('SFT_SESSION'); session_start();
if (!isset($_SESSION['sft_id']) || $_SESSION['sft_ruolo'] !== 'Esercizio') {
    header("Location: index.php?errore=non_autorizzato"); exit();
}
if (empty($_SESSION['trasf_pending'])) {
    header("Location: admin_esercizio.php?msg=Nessun trasferimento in sospeso"); exit();
}
$P  = $_SESSION['trasf_pending'];
$cv = $P['corsa_vuoto'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>SFT - Conferma Trasferimento a Vuoto</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<style>
body{font-family:'Inter',sans-serif;background:linear-gradient(180deg,#f0f4ff 0%,#fff 100%);}
.card-main{max-width:720px;margin:40px auto;border:none;border-radius:18px;box-shadow:0 25px 60px rgba(13,31,60,.15);overflow:hidden;}
.hdr{background:linear-gradient(135deg,#0d1f3c 0%,#1a3a6b 60%,#004a99 100%);color:#fff;padding:26px;}
.hdr h3{font-weight:700;margin:0;}
.stat{background:linear-gradient(135deg,#f0f4ff 0%,#fff 100%);border:1px solid #c0d0f0;border-radius:14px;padding:16px;}
.stat .num{font-size:1.4rem;font-weight:800;color:#1a3a6b;font-family:'JetBrains Mono',monospace;}
.stat .lbl{font-size:.72rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-top:3px;}
.section-title{font-weight:700;color:#1a3a6b;text-transform:uppercase;letter-spacing:.08em;font-size:.8rem;border-bottom:2px solid #e8f0fe;padding-bottom:8px;margin:20px 0 12px;}
.sosta-row{background:#fff8e1;border-left:4px solid #d97706;padding:10px 14px;border-radius:8px;margin-bottom:6px;}
.btn-go{background:linear-gradient(135deg,#16a34a 0%,#15803d 100%);color:#fff;font-weight:600;border:none;border-radius:10px;padding:10px 20px;}
.btn-go:hover{transform:translateY(-2px);color:#fff;}
.btn-no{background:#fff;color:#1a3a6b;border:1px solid #c0d0f0;border-radius:10px;padding:10px 20px;font-weight:600;}
.alert-note{background:#f0f4ff;border-left:3px solid #1a3a6b;color:#1a3a6b;padding:12px 16px;border-radius:8px;font-size:.9rem;}
</style>
</head>
<body>
<div class="card card-main">
  <div class="hdr d-flex align-items-center gap-3">
    <i class="bi bi-exclamation-triangle-fill" style="font-size:2rem;"></i>
    <div>
      <h3>Conferma trasferimento a vuoto</h3>
      <small class="opacity-75">Il convoglio non è al capolinea di partenza della nuova corsa</small>
    </div>
  </div>
  <div class="p-4">

    <div class="alert-note mb-3">
      <i class="bi bi-info-circle"></i>
      Il convoglio si trova attualmente a <strong><?= htmlspecialchars($P['posizione']['stazione_nome']) ?></strong>
      (disponibile dalle <strong><?= substr($P['posizione']['disponibile_da'],0,5) ?></strong>).
      La nuova corsa parte da <strong><?= htmlspecialchars($P['nome_staz_partenza']) ?></strong> alle
      <strong><?= substr($P['ora'],0,5) ?></strong><?php if ($P['ora']!==$P['ora_originale']): ?>
      <em>(originale <?= substr($P['ora_originale'],0,5) ?> → posticipata a <?= substr($P['ora'],0,5) ?>) per completare il trasferimento a vuoto</em>
      <?php endif; ?>.
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-4"><div class="stat">
        <div class="lbl">Trasferimento</div>
        <div class="num"><?= substr($cv['ora_partenza'],0,5) ?> → <?= substr($cv['ora_arrivo'],0,5) ?></div>
      </div></div>
      <div class="col-md-4"><div class="stat">
        <div class="lbl">Percorso</div>
        <div class="num"><?= $cv['distanza_km'] ?> km</div>
        <small class="text-muted"><?= $cv['tempo_puro_min'] ?> min base<?php if ($cv['ritardo_soste_min']>0): ?> + <?= $cv['ritardo_soste_min'] ?> min soste<?php endif; ?></small>
      </div></div>
      <div class="col-md-4"><div class="stat">
        <div class="lbl">Tratta vuoto</div>
        <div class="num" style="font-size:.95rem;"><?= htmlspecialchars($cv['stazione_partenza']) ?> → <?= htmlspecialchars($cv['stazione_arrivo']) ?></div>
      </div></div>
    </div>

    <?php if (!empty($cv['soste'])): ?>
    <div class="section-title"><i class="bi bi-pause-circle"></i> Soste previste sul trasferimento</div>
    <?php foreach ($cv['soste'] as $s): ?>
      <div class="sosta-row">
        <strong><?= htmlspecialchars($s['stazione']) ?></strong>
        (+<?= $s['minuti'] ?> min) — <?= $s['tipo'] ?> con corsa #<?= $s['corsa_id'] ?>
        <span class="text-muted small">→ tratta verso <?= htmlspecialchars($s['tratta_verso']) ?></span>
      </div>
    <?php endforeach; ?>
    <?php else: ?>
      <div class="alert-note"><i class="bi bi-check-circle"></i> Nessuna sosta prevista: tratta libera.</div>
    <?php endif; ?>

    <?php if (!empty($P['messaggi_posticipo'])): ?>
    <div class="section-title"><i class="bi bi-clock-history"></i> Posticipi applicati</div>
    <?php foreach ($P['messaggi_posticipo'] as $m): ?>
      <div class="alert alert-warning py-2 mb-2 small"><?= htmlspecialchars($m) ?></div>
    <?php endforeach; ?>
    <?php endif; ?>



    <div class="d-flex justify-content-end gap-2 mt-4">
      <a href="admin_esercizio.php" class="btn-no"><i class="bi bi-x"></i> Annulla</a>
      <form method="POST" action="esercizio.php" class="d-inline">
        <input type="hidden" name="azione" value="inserisci">
        <input type="hidden" name="data"   value="<?= htmlspecialchars($P['data']) ?>">
        <input type="hidden" name="ora"    value="<?= htmlspecialchars(substr($P['ora'],0,5)) ?>">
        <input type="hidden" name="ora_originale_vera" value="<?= htmlspecialchars(substr($P['ora_originale'],0,5)) ?>">
        <input type="hidden" name="id_convoglio" value="<?= intval($P['id_convoglio']) ?>">
        <input type="hidden" name="direzione" value="<?= htmlspecialchars($P['direzione']) ?>">
        <input type="hidden" name="conferma_vuoto" value="1">
        <button type="submit" class="btn-go"><i class="bi bi-check2-circle"></i> Conferma e inserisci corsa</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
