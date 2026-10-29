<?php
/** Test-only PHP development-server routing. Production uses .htaccess. */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/routes.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$leaf = ltrim((string)$path, '/');
if (in_array($leaf, LCS_PUBLIC_PAGE_ROUTES, true)) {
    $_SERVER['SCRIPT_NAME'] = '/' . $leaf . '.php';
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    require dirname(__DIR__) . '/' . $leaf . '.php';
    return true;
}
return false;
