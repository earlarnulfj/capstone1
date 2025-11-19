<?php
// Change this secret in production and keep it out of VCS if possible.
// You may set via environment variable as well.
$WEBHOOK_SECRET = getenv('WEBHOOK_SECRET') ?: 'CHANGE_ME_32_CHAR_SECRET';
?>