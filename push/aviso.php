
<?php

header('Content-Type: application/json; charset=utf-8');

function isAllowedCaller() {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';

    if (in_array($remote, ['127.0.0.1', '::1'], true)) {
        return true;
    }

    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($origin !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST);

        return is_string($originHost) && strcasecmp($originHost, $host) === 0;
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    if ($referer !== '') {
        $refererHost = parse_url($referer, PHP_URL_HOST);

        return is_string($refererHost) && strcasecmp($refererHost, $host) === 0;
    }

    return false;
}

if (!isAllowedCaller()) {
    http_response_code(403);
    echo json_encode(['message' => 'Acceso no permitido']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Metodo no permitido']);
    exit;
}

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'El servidor debe usar PHP 8.2 o superior. Version actual: ' . PHP_VERSION
    ]);
    exit;
}

require __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

try {


    $config = require __DIR__ . '/config.php';


    $file = $config['subscriptions_file'];

    if (!file_exists($file)) {
        throw new RuntimeException('No existe el archivo de suscripciones');
    }

    $subscriptions = json_decode(file_get_contents($file), true);

    if (!is_array($subscriptions) || empty($subscriptions)) {
        throw new RuntimeException('No hay usuarios suscritos');
    }


    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        $data = $_POST;
    }

    $payload = json_encode([
        'title' => $data['title'] ?? 'Creative AI Studio',
        'body' => $data['body'] ?? 'Tienes una nueva actualización.',
        'url' => $data['url'] ?? './'
    ]);

    $webPush = new WebPush([
        'VAPID' => [
            'subject' => $config['vapid']['subject'],
            'publicKey' => $config['vapid']['publicKey'],
            'privateKey' => $config['vapid']['privateKey']
        ]
    ]);


    foreach ($subscriptions as $subscriptionData) {

        $subscription = Subscription::create($subscriptionData);

        $webPush->queueNotification(
            $subscription,
            $payload
        );
    }

    $sent = 0;
    $failed = 0;
    $expired = [];


    foreach ($webPush->flush() as $report) {

        if ($report->isSuccess()) {
            $sent++;
        } else {
            $failed++;

            if ($report->isSubscriptionExpired()) {
                $expired[] = $report->getEndpoint();
            }
        }
    }


    if (!empty($expired)) {

        $subscriptions = array_values(array_filter(
            $subscriptions,
            function ($subscription) use ($expired) {
                return !in_array($subscription['endpoint'], $expired, true);
            }
        ));

        file_put_contents(
            $file,
            json_encode($subscriptions, JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    echo json_encode([
        'success' => $failed === 0,
        'sent' => $sent,
        'failed' => $failed,
        'message' => 'Proceso de envio finalizado'
    ]);

} catch (Throwable $error) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $error->getMessage()
    ]);
}
