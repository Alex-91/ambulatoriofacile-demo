<?php

namespace App\Services;

class WhatsAppConversationService
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array{total_messages:int, total_conversations:int, conversations:array<int, array<string, mixed>>}
     */
    public function buildDashboard(array $messages): array
    {
        $conversations = [];
        $acceptedMessages = 0;

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $direction = strtolower(trim((string) ($message['direction'] ?? 'incoming')));
            if (!in_array($direction, ['incoming', 'outgoing'], true)) {
                $direction = 'incoming';
            }

            $peer = trim((string) ($message['peer'] ?? ''));
            if ($peer === '') {
                $peer = trim((string) ($direction === 'outgoing'
                    ? ($message['to'] ?? '')
                    : ($message['from'] ?? '')));
            }
            if ($peer === '') {
                continue;
            }

            $receivedAt = trim((string) ($message['received_at'] ?? ''));
            $timestamp = $receivedAt !== '' ? strtotime($receivedAt) : false;
            $timestamp = $timestamp !== false ? $timestamp : 0;
            $senderName = trim((string) ($message['sender_name'] ?? ''));
            $text = trim((string) ($message['text'] ?? ''));
            $messageType = trim((string) ($message['message_type'] ?? 'unsupported'));

            $normalizedMessage = [
                'message_id' => trim((string) ($message['message_id'] ?? '')),
                'direction' => $direction,
                'peer' => $peer,
                'from' => trim((string) ($message['from'] ?? '')),
                'to' => trim((string) ($message['to'] ?? '')),
                'sender_name' => $senderName,
                'text' => $text,
                'message_type' => $messageType !== '' ? $messageType : 'unsupported',
                'delivery_status' => trim((string) ($message['delivery_status'] ?? ($direction === 'outgoing' ? 'sent' : 'received'))),
                'delivered_at' => trim((string) ($message['delivered_at'] ?? '')),
                'read_at' => trim((string) ($message['read_at'] ?? '')),
                'is_group' => !empty($message['is_group']),
                'received_at' => $receivedAt,
                '_timestamp' => $timestamp,
            ];

            if (!isset($conversations[$peer])) {
                $conversations[$peer] = [
                    'peer' => $peer,
                    'label' => $senderName !== '' ? $senderName : $peer,
                    'last_message' => '',
                    'last_at' => '',
                    '_last_timestamp' => -1,
                    'messages' => [],
                ];
            }

            if ($direction === 'incoming' && $senderName !== '') {
                $conversations[$peer]['label'] = $senderName;
            }
            $conversations[$peer]['messages'][] = $normalizedMessage;
            if ($timestamp >= (int) $conversations[$peer]['_last_timestamp']) {
                $conversations[$peer]['last_message'] = $this->preview($text, $messageType);
                $conversations[$peer]['last_at'] = $receivedAt;
                $conversations[$peer]['_last_timestamp'] = $timestamp;
            }
            $acceptedMessages++;
        }

        foreach ($conversations as &$conversation) {
            usort($conversation['messages'], static function (array $left, array $right): int {
                return ((int) ($left['_timestamp'] ?? 0)) <=> ((int) ($right['_timestamp'] ?? 0));
            });
            foreach ($conversation['messages'] as &$message) {
                unset($message['_timestamp']);
            }
            unset($message);
        }
        unset($conversation);

        $conversations = array_values($conversations);
        usort($conversations, static function (array $left, array $right): int {
            return ((int) ($right['_last_timestamp'] ?? 0)) <=> ((int) ($left['_last_timestamp'] ?? 0));
        });
        foreach ($conversations as &$conversation) {
            unset($conversation['_last_timestamp']);
        }
        unset($conversation);

        return [
            'total_messages' => $acceptedMessages,
            'total_conversations' => count($conversations),
            'conversations' => $conversations,
        ];
    }

    private function preview(string $text, string $messageType): string
    {
        if ($text === '') {
            return match ($messageType) {
                'image' => 'Immagine',
                'video' => 'Video',
                'document' => 'Documento',
                'audio' => 'Messaggio audio',
                'sticker' => 'Sticker',
                'location' => 'Posizione',
                'contact' => 'Contatto',
                default => 'Messaggio non testuale',
            };
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
        if (function_exists('mb_strlen') && mb_strlen($text) > 80) {
            return mb_substr($text, 0, 77) . '...';
        }
        if (!function_exists('mb_strlen') && strlen($text) > 80) {
            return substr($text, 0, 77) . '...';
        }
        return $text;
    }
}
