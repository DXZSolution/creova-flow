
<?php


$vapidFile = __DIR__ . '/vapid.json';

if (!file_exists($vapidFile)) {
    throw new RuntimeException('No se encontró vapid.json');
}

$vapid = json_decode(file_get_contents($vapidFile), true);

if (
    empty($vapid['publicKey']) ||
    empty($vapid['privateKey'])
) {
    throw new RuntimeException('Las claves VAPID no son válidas');
}


return [
    'vapid' => [
        'subject' => 'mailto:admin@example.com',
        'publicKey' => $vapid['publicKey'],
        'privateKey' => $vapid['privateKey']
    ],

    'subscriptions_file' => __DIR__ . '/subscriptions.json'
];
