<?php
declare(strict_types=1);

/**
 * Fapa.cam Kadam wiring — single source of truth for admin pages and preflight.
 * DB credentials come from cmd/config_admin.php; no bearer or password here.
 */
require_once __DIR__ . '/config_admin.php';

const FAPA_KADAM_CAMPAIGN_ID = 973225;
const FAPA_TRACKER_CAMP_ID   = 16;      // local tracker/fapaio camp id
const FAPA_KADAM_APP_ID      = 141381;
const FAPA_KADAM_CLIENT_ID   = 141381;

/** @return array{0:string,1:int,2:int} bearer, app_id, client_id */
function fapa_kadam_token(): array {
    $m = db_connect('fapacrm');
    $row = $m->query("SELECT params_json FROM crm_cron_jobs
                      WHERE id=139 AND connector='kadam_cost' AND is_enabled=1")
             ->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('kadam_cost job 139 not found/disabled');
    }
    $p = json_decode($row['params_json'], true, 512, JSON_THROW_ON_ERROR);
    return [
        (string)$p['bearer'],
        (int)($p['app_id'] ?? FAPA_KADAM_APP_ID),
        (int)($p['client_id'] ?? FAPA_KADAM_CLIENT_ID),
    ];
}
