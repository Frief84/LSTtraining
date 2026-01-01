<?php
/**
 * AJAX-Handler für das LST-Training-Plugin
 * – sämtliche Rechteprüfungen laufen über lsttraining_user_can()
 *   (siehe includes/permissions.php).
 */

if (!defined('ABSPATH')) {
    exit(); // Direktzugriff verhindern
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/permissions.php';
require_once dirname(__DIR__) . '/geo.php';
require_once dirname(__DIR__) . '/activity.php';


// Bootstrap für modulare AJAX-Handler (aufgeteilt aus ajax-handlers.php)


/**
 * Einheitlicher Guard für alle AJAX-Handler.
 *
 * Optionen:
 * - area (string)                 Pflicht: Berechtigungsbereich für lsttraining_user_can()
 * - ls_param (string|string[])    Request-Key(s) für Leitstellen-ID (default: 'leitstelle_id')
 * - ls_required (bool)            Wenn true: ohne LS-ID -> 400
 * - nonce_action (string|null)    Wenn gesetzt: Nonce wird geprüft
 * - nonce_field (string)          Request-Key für Nonce (default: 'nonce')
 * - method (string|null)          Optional: erzwingt HTTP-Methode (POST/GET)
 *
 * Rückgabe:
 * - ['ls_id' => ?int]
 */
function lsttraining_ajax_guard(array $opts): array {
    $area         = $opts['area'] ?? null;
    $ls_param     = $opts['ls_param'] ?? 'leitstelle_id';
    $ls_required  = (bool)($opts['ls_required'] ?? false);
    $nonce_action = $opts['nonce_action'] ?? null;
    $nonce_field  = $opts['nonce_field'] ?? 'nonce';
    $capability   = $opts['capability'] ?? null;
    $method       = $opts['method'] ?? null;

    if ( ! $area ) {
        wp_send_json_error(['message' => 'Fehlende Guard-Konfiguration (area).'], 500);
    }

    if ( $method && strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method) ) {
        wp_send_json_error(['message' => 'Ungültige HTTP-Methode.'], 405);
    }

    // Auth
    if ( ! is_user_logged_in() ) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    // Nonce (optional)
    if ( $nonce_action ) {
        $ok = check_ajax_referer($nonce_action, $nonce_field, false);
        if ( ! $ok ) {
            wp_send_json_error(['message' => 'Ungültiger Sicherheits-Token.'], 403);
        }
    }

    // Leitstellen-ID (optional)
    $ls_id = null;
    $params = array_merge((array)$_GET, (array)$_POST);

    $keys = is_array($ls_param) ? $ls_param : [$ls_param];
    foreach ($keys as $k) {
        if ( isset($params[$k]) && $params[$k] !== '' ) {
            $ls_id = absint($params[$k]);
            break;
        }
    }

    if ( $ls_required && ! $ls_id ) {
        wp_send_json_error(['message' => 'Leitstelle fehlt.'], 400);
    }

    // Capability / Scope
    if ( $capability ) {
        if ( ! current_user_can((string)$capability) ) {
            wp_send_json_error(['message' => 'Nicht berechtigt.'], 403);
        }
    } else {
        if ( ! lsttraining_user_can((string)$area, $ls_id ?: null) ) {
                    wp_send_json_error(['message' => 'Nicht berechtigt.'], 403);
        }
    }

    return ['ls_id' => $ls_id ?: null];
}

