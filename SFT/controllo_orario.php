<?php

if (!function_exists('puo_prenotare')) {
function puo_prenotare(mysqli $con, int $id_corsa, string $nome_stazione_partenza, int $minuti_soglia = 15): array {
    $id_corsa = (int)$id_corsa;
    $nome_esc = $con->real_escape_string($nome_stazione_partenza);

    // Prendo data+ora di partenza DELLA CORSA (iniziale) e DELLA STAZIONE UTENTE
    $sql = "SELECT C.DATA, C.STATO, C.ORA AS ORA_INIZIALE,
                   COALESCE(F.ORAP, F.ORAA) AS ORA_STAZIONE_UTENTE
            FROM SFT_CORSA C
            LEFT JOIN SFT_STAZIONE S ON S.NOME = '$nome_esc'
            LEFT JOIN SFT_FERMATA  F ON F.IDCORSA = C.IDCORSA AND F.IDSTAZIONE = S.IDSTAZIONE
            WHERE C.IDCORSA = $id_corsa
            LIMIT 1";
    $res = $con->query($sql);
    if (!$res || $res->num_rows === 0) {
        return ['ok' => false, 'motivo' => 'corsa_non_trovata', 'ts_partenza' => null];
    }
    $row = $res->fetch_assoc();

    if (in_array($row['STATO'], ['Annullata', 'Conclusa'], true)) {
        return ['ok' => false, 'motivo' => 'corsa_non_attiva', 'ts_partenza' => null];
    }

    // Se non ho l'orario della stazione utente ricado su ORA inizial
    $ora_part = $row['ORA_STAZIONE_UTENTE'] ?: $row['ORA_INIZIALE'];
    if (empty($ora_part)) {
        return ['ok' => false, 'motivo' => 'stazione_non_sulla_corsa', 'ts_partenza' => null];
    }

    // Gestione "+1 giorno": corsa che parte in tarda sera e passa per la stazione dell'utente dopo mezzanotte 
    $data_rif       = $row['DATA'];
    $hh_stazione    = (int)substr($ora_part, 0, 2);
    $hh_iniziale    = (int)substr($row['ORA_INIZIALE'] ?? '00:00', 0, 2);
    if ($hh_stazione < 6 && $hh_iniziale >= 20) {
        $data_rif = date('Y-m-d', strtotime($row['DATA'] . ' +1 day'));
    }

    $ts_partenza = strtotime($data_rif . ' ' . $ora_part);
    if ($ts_partenza === false) {
        return ['ok' => false, 'motivo' => 'corsa_non_trovata', 'ts_partenza' => null];
    }

    $ts_soglia = time() + ($minuti_soglia * 60);

    if ($ts_partenza < time()) {
        return ['ok' => false, 'motivo' => 'corsa_passata', 'ts_partenza' => $ts_partenza];
    }
    if ($ts_partenza < $ts_soglia) {
        return ['ok' => false, 'motivo' => 'chiusa_' . $minuti_soglia . 'min', 'ts_partenza' => $ts_partenza];
    }
    return ['ok' => true, 'motivo' => 'ok', 'ts_partenza' => $ts_partenza];
}

function blocca_se_chiusa(mysqli $con, int $id_corsa, string $nome_stazione_partenza,
                          string $redirect_url = 'index.php',
                          int $minuti_soglia = 15): void {
    $r = puo_prenotare($con, $id_corsa, $nome_stazione_partenza, $minuti_soglia);
    if (!$r['ok']) {
        $sep = (strpos($redirect_url, '?') === false) ? '?' : '&';
        header('Location: ' . $redirect_url . $sep . 'msg=prenotazione_chiusa&reason=' . urlencode($r['motivo']));
        exit();
    }
}

} 

