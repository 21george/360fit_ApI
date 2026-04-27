<?php

namespace App\Services;

use App\Config\Database;
use MongoDB\BSON\ObjectId;

class PushNotificationService
{
    private string $serverKey;
    private string $fcmUrl = 'https://fcm.googleapis.com/fcm/send';

    public function __construct()
    {
        $this->serverKey = $_ENV['FCM_SERVER_KEY'] ?? '';
    }

    public function send(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        if (empty($this->serverKey) || empty($fcmToken)) {
            return false;
        }

        $payload = [
            'to'           => $fcmToken,
            'notification' => [
                'title' => $title,
                'body'  => $body,
                'sound' => 'default',
            ],
            'data'         => array_merge($data, ['title' => $title, 'body' => $body]),
        ];

        $ch = curl_init($this->fcmUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: key=' . $this->serverKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    public function sendToMultiple(array $fcmTokens, string $title, string $body, array $data = []): array
    {
        $results = [];
        foreach ($fcmTokens as $token) {
            $results[$token] = $this->send($token, $title, $body, $data);
        }
        return $results;
    }

    public function logNotification(string $clientId, string $type, string $title, string $body): void
    {
        try {
            Database::getInstance()->getCollection('notifications')->insertOne([
                'client_id'  => new ObjectId($clientId),
                'type'       => $type,
                'title'      => $title,
                'body'       => $body,
                'read'       => false,
                'created_at' => new \MongoDB\BSON\UTCDateTime(),
            ]);
        } catch (\Exception) {}
    }
}
