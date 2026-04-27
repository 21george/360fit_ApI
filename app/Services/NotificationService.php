<?php
namespace App\Services;

use App\Config\Database;

class NotificationService {
    private \MongoDB\Collection $collection;

    public function __construct() {
        $this->collection = Database::getInstance()->getCollection('notifications');
    }

    public function send(string $clientId, string $type, string $title, string $body, array $data = []): void {
        // Get client FCM token
        $clientCol = Database::getInstance()->getCollection('clients');
        $client = $clientCol->findOne(['_id' => new \MongoDB\BSON\ObjectId($clientId)]);

        if (!$client || empty($client['fcm_token'])) return;

        $fcmToken = $client['fcm_token'];
        $credPath = BASE_PATH . '/' . ($_ENV['FIREBASE_CREDENTIALS_PATH'] ?? 'config/firebase-credentials.json');

        // Log notification to DB
        $this->collection->insertOne([
            'client_id' => new \MongoDB\BSON\ObjectId($clientId),
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'sent_at' => new \MongoDB\BSON\UTCDateTime(),
            'read' => false
        ]);

        // Send via FCM HTTP v1 API
        if (!file_exists($credPath)) return;

        try {
            $accessToken = $this->getAccessToken($credPath);
            $projectId = json_decode(file_get_contents($credPath), true)['project_id'];

            $payload = json_encode([
                'message' => [
                    'token' => $fcmToken,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map('strval', $data),
                    'android' => ['priority' => 'high'],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default']]]
                ]
            ]);

            $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer $accessToken",
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => $payload,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Exception $e) {
            // Log silently
        }
    }

    private function getAccessToken(string $credPath): string {
        $creds = json_decode(file_get_contents($credPath), true);
        $now = time();
        $jwt = \Firebase\JWT\JWT::encode([
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], $creds['private_key'], 'RS256');

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ])
        ]);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $res['access_token'] ?? '';
    }
}
