<?php
require_once __DIR__.'/../cmd/config.php';
$c = db_connect();
$e = $c->real_escape_string($_POST['event'] ?? '');
$u = $c->real_escape_string($_COOKIE['uiq'] ?? '');
if ($e) $c->query("INSERT INTO funnel_events (event_type, page, uiq) VALUES ('$e','site','$u')");
$c->close();
http_response_code(204);
