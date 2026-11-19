
<?php

require __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\VAPID;


$keys = VAPID::createVapidKeys();


$data = [
    'publicKey' => $keys['publicKey'],
    'privateKey' => $keys['privateKey']
];

file_put_contents(
    __DIR__ . '/vapid.json',
    json_encode($data, JSON_PRETTY_PRINT)
);

echo "Claves VAPID generadas y guardadas correctamente.\n";
echo "Archivo: vapid.json\n";
echo "Public Key: " . $keys['publicKey'] . "\n";
