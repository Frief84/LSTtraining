<?php
// includes/activity.php

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'db.php'; // Pfad ggf. anpassen

/**
 * Minimaler Audit-Logger.
 *
 * @param array $args {
 *   @type string   $entity_type  Pflicht. Bereich: 'leitstelle','nebenstelle','hospital','wache','fahrzeug','permission','user', ...
 *   @type string   $action       Pflicht. 'create','update','delete','permission_change','login', ...
 *   @type int|null $entity_id    Optional. Betroffene ID.
 *   @type int|null $user_id      Optional. Standard: aktueller WP-User.
 *   @type array    $meta         Optional. Kleine Zusatzinfos (werden als JSON gespeichert).
 * }
 * @return bool Erfolg
 */
function lsttraining_log_activity(array $args): bool
{
    $entity_type = isset($args['entity_type']) ? sanitize_text_field($args['entity_type']) : '';
    $action      = isset($args['action'])      ? sanitize_text_field($args['action'])      : '';
    $entity_id   = isset($args['entity_id'])   ? (int)$args['entity_id']                   : null;
    $user_id     = isset($args['user_id'])     ? (int)$args['user_id']                     : get_current_user_id();
    $meta        = isset($args['meta'])        ? (array)$args['meta']                      : [];

    if ($entity_type === '' || $action === '') {
        return false;
    }

    // Meta auf sinnvolle Größe begrenzen
    $meta_json = '';
    if (!empty($meta)) {
        // Notfalls kürzen
        $encoded = wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 4000) {
            $encoded = substr($encoded, 0, 4000);
        }
        $meta_json = $encoded;
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        return false;
    }

    // IP (optional)
    $ip_bin = null;
    $remote_ip = $_SERVER['REMOTE_ADDR'] ?? null;
    if ($remote_ip && filter_var($remote_ip, FILTER_VALIDATE_IP)) {
        // IPv4 -> inet_pton
        $packed = @inet_pton($remote_ip);
        if ($packed !== false) {
            $ip_bin = $packed;
        }
    }

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ua = substr($ua, 0, 255);

    $sql = "INSERT INTO lst_activity_log (user_id, entity_type, entity_id, action, ip, user_agent, meta_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    try {
        return $stmt->execute([
            $user_id ?: null,
            $entity_type,
            $entity_id ?: null,
            $action,
            $ip_bin,
            $ua,
            $meta_json
        ]);
    } catch (PDOException $e) {
        // Optional: error_log('[LST] log_activity failed: '.$e->getMessage());
        return false;
    }
}
