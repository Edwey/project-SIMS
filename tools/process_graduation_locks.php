<?php
// CLI utility to process graduation account locks.

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$result = process_graduation_account_locks();

$count = (int)($result['processed'] ?? 0);
$userIds = $result['user_ids'] ?? [];

if ($count === 0) {
    echo "No graduation account locks processed.\n";
    exit(0);
}

echo "Processed {$count} graduation account lock" . ($count === 1 ? '' : 's') . ".\n";
if (!empty($userIds)) {
    echo "Affected user IDs: " . implode(', ', array_map('intval', $userIds)) . "\n";
}

exit(0);
