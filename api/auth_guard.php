<?php
/**
 * Sdílená kontrola přístupu k API.
 *
 * Endpointy patří do tří skupin:
 *   veřejné    — přihlášení, registrace, odhlášení, překlady labelů
 *   uživatelské — cokoli čte nebo zapisuje data uživatele → require_login()
 *   údržbové   — zakládání schématu, opravy, cron        → require_admin()
 *
 * CLI se nikdy neblokuje: cron služby na Railway startují příkazem
 * `php api/cron-task.php prices`, takže spuštění ze shellu je důvěryhodné
 * z podstaty. Je to zároveň záchranná cesta k údržbovým endpointům —
 * `railway run php api/init_broker.php` projde i bez role admin.
 */

if (!function_exists('api_is_cli')) {

    function api_is_cli(): bool {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    /**
     * Je soubor, který si o kontrolu řekl, skutečně tím, na co přišel požadavek?
     *
     * Některé „endpointy“ zároveň slouží jako knihovna — `setup_dividend_db.php`
     * si vtahuje `ajax-fetch-history.php`, `ajax_import_ticker.php`,
     * `api-dividend-comparison.php`, `googlefinanceservice.php` i `v3/install-db.php`.
     * Kontrola v takovém souboru pak shodila i běžný požadavek, který s údržbou
     * nemá nic společného: stahování cen začalo obyčejnému uživateli vracet
     * „vyžaduje roli admin“ a nově naimportované tickery zůstaly bez ceny.
     *
     * Hlídáme proto jen přímé volání přes HTTP. Vtažení jako knihovna projde —
     * kontrolu už udělal ten skript, na který požadavek doopravdy přišel.
     */
    function api_je_vstupni_bod(): bool {
        // Rámec 0 nese soubor, ze kterého se require_login()/require_admin() volá.
        $stopa = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        $volajici = $stopa[0]['file'] ?? '';
        $vstup = $_SERVER['SCRIPT_FILENAME'] ?? '';
        if ($volajici === '' || $vstup === '') return true;   // neurčité → raději hlídat
        $a = realpath($volajici);
        $b = realpath($vstup);
        return $a === false || $b === false || $a === $b;
    }

    function api_start_session(): void {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    /** ID přihlášeného uživatele, nebo 0. Snese všechny tvary, které login ukládá. */
    function api_current_user_id(): int {
        api_start_session();
        $klice = ['user_id', 'uid', 'userid', 'id'];
        foreach ($klice as $k) {
            if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) {
                return (int)$_SESSION[$k];
            }
        }
        if (isset($_SESSION['user'])) {
            $u = $_SESSION['user'];
            if (is_array($u)) {
                foreach ($klice as $k) if (isset($u[$k]) && is_numeric($u[$k])) return (int)$u[$k];
            } elseif (is_object($u)) {
                foreach ($klice as $k) if (isset($u->$k) && is_numeric($u->$k)) return (int)$u->$k;
            }
        }
        return 0;
    }

    function api_deny(int $kod, string $zprava): void {
        http_response_code($kod);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $zprava], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Vyžaduje přihlášeného uživatele. Vrací jeho ID, aby volající mohl rovnou
     * filtrovat data — samotná kontrola nestačí, dotazy musí být omezené na
     * `user_id`, jinak by přihlášený viděl cizí data.
     */
    function require_login(): int {
        if (api_is_cli() || !api_je_vstupni_bod()) return 0;
        $id = api_current_user_id();
        if ($id > 0) return $id;
        api_deny(401, 'Unauthorized');
    }

    /** Vyžaduje roli admin. Údržba a zásahy do schématu. */
    function require_admin(): int {
        if (api_is_cli() || !api_je_vstupni_bod()) return 0;
        $id = api_current_user_id();
        api_start_session();
        $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
        if ($id > 0 && $role === 'admin') return $id;
        if ($id > 0) api_deny(403, 'Forbidden: vyžaduje roli admin');
        api_deny(401, 'Unauthorized');
    }
}
