
<?php

$data = [
    'title' => 'Creative AI Studio',
    'body' => '¡Felicidades! Las notificaciones Push ya funcionan correctamente.',
    'url' => '../'
];

$ch = curl_init('http://localhost/creative-ai-studio/push/aviso.php');

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($data)
]);

$response = curl_exec($ch);

if ($response === false) {
    die('Error: ' . curl_error($ch));
}

echo $response;

curl_close($ch);
