<?php
// ═══════════════════════════════════════════════════════════════
// Helper notifiche persistenti 24h (file JSON)
// Include questo file con: include_once('notifiche_helper.php');
// ═══════════════════════════════════════════════════════════════

if (!function_exists('sft_add_notifica')) {
    /**
     * Aggiunge una notifica 24h per un utente nel file notifiche_utenti.json.
     *
     * @param int    $codutente  ID utente destinatario
     * @param string $tipo       Es: 'Pagamento', 'Rimborso', 'Cancellazione', 'Modifica', 'Info'
     * @param string $testo      Testo della notifica
     * @param int    $ore        Durata in ore (default 24)
     * @return bool              true se scritta correttamente
     */
    function sft_add_notifica($codutente, $tipo, $testo, $ore = 24) {
        $codutente = (int)$codutente;
        if ($codutente <= 0) return false;

        $file_notif = __DIR__ . '/notifiche_utenti.json';
        $all_notif = [];
        if (file_exists($file_notif)) {
            $raw = @file_get_contents($file_notif);
            $dec = json_decode($raw, true);
            if (is_array($dec)) $all_notif = $dec;
        }

        $key = (string)$codutente;
        if (!isset($all_notif[$key])) $all_notif[$key] = [];

        $all_notif[$key][] = [
            'id'    => uniqid('notif_', true),
            'tipo'  => $tipo,
            'testo' => $testo,
            'data'  => date('Y-m-d H:i:s'),
            'scade' => time() + ($ore * 3600),
            'letta' => false
        ];

        // Pulizia scadute (tutti gli utenti)
        $now = time();
        foreach ($all_notif as $cu => $lista) {
            $filtr = array_values(array_filter($lista, fn($x) => ($x['scade'] ?? 0) > $now));
            if (empty($filtr)) unset($all_notif[$cu]);
            else $all_notif[$cu] = $filtr;
        }

        $ok = @file_put_contents(
            $file_notif,
            json_encode($all_notif, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
        if ($ok !== false) @chmod($file_notif, 0664);
        return $ok !== false;
    }
}

if (!function_exists('sft_flash_to_notifica')) {
    /**
     * Converte un messaggio flash (?msg=xxx) in notifica persistente 24h,
     * evitando duplicati nella stessa sessione.
     *
     * @param int    $codutente
     * @param string $msg_code   Valore di $_GET['msg']
     * @return bool              true se la notifica è stata creata in questa chiamata
     */
    function sft_flash_to_notifica($codutente, $msg_code) {
        if (empty($msg_code) || (int)$codutente <= 0) return false;

        // Dedup in sessione: stesso msg non viene ri-aggiunto se la pagina viene ricaricata
        if (!isset($_SESSION['sft_flash_added'])) $_SESSION['sft_flash_added'] = [];
        $dedup_key = $codutente . '|' . $msg_code;
        if (in_array($dedup_key, $_SESSION['sft_flash_added'], true)) return false;

        $map = [
            'pagamento_ok' => [
                'tipo'  => 'Pagamento',
                'testo' => 'Pagamento avvenuto con successo. Il tuo biglietto è stato acquistato ed è disponibile in "I Miei Viaggi".'
            ],
            'rimborsato' => [
                'tipo'  => 'Rimborso',
                'testo' => 'Rimborso effettuato. Il biglietto è stato annullato e l\'importo è stato riaccreditato sul tuo conto PaySteam.'
            ],
            'eliminato' => [
                'tipo'  => 'Cancellazione',
                'testo' => 'Viaggio annullato correttamente. Il posto è stato liberato.'
            ],
            'cambio_ok' => [
                'tipo'  => 'Modifica',
                'testo' => 'Posto aggiornato con successo sul tuo biglietto.'
            ],
        ];

        if (!isset($map[$msg_code])) return false;

        $ok = sft_add_notifica($codutente, $map[$msg_code]['tipo'], $map[$msg_code]['testo']);
        if ($ok) $_SESSION['sft_flash_added'][] = $dedup_key;
        return $ok;
    }
}
?>

