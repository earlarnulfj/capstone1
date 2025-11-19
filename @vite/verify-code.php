<?php
// Silences preview tool’s Vite probe by returning 204.
http_response_code(204);
header('Content-Type: text/plain');
exit;