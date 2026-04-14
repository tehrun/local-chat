<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/assert.php';
require_once __DIR__ . '/../../support/bootstrap_unit.php';

return [
    'normalizeMessageSearchQuery trims spaces and enforces limits' => static function (): void {
        assertSameValue(normalizeMessageSearchQuery('   hello   world   '), 'hello world');
        assertSameValue(normalizeMessageSearchQuery(' '), null);
        assertSameValue(normalizeMessageSearchQuery('a'), null);

        $long = str_repeat('x', 120);
        $normalized = normalizeMessageSearchQuery($long);
        assertTrue(is_string($normalized));
        assertSameValue(strlen($normalized), 80);
    },

    'messageSearchLimit clamps values into safe bounds' => static function (): void {
        assertSameValue(messageSearchLimit(0), 20);
        assertSameValue(messageSearchLimit(-5), 20);
        assertSameValue(messageSearchLimit(1), 1);
        assertSameValue(messageSearchLimit(999), 50);
    },

    'escapeLikePattern escapes wildcard characters' => static function (): void {
        $escaped = escapeLikePattern('10%_\\done');
        assertSameValue($escaped, '10\\%\\_\\\\done');
    },

    'normalizeReactionEmoji trims and limits grapheme payload length' => static function (): void {
        assertSameValue(normalizeReactionEmoji('   😀  '), '😀');
        assertSameValue(normalizeReactionEmoji(''), '');
        assertSameValue(mb_strlen(normalizeReactionEmoji(str_repeat('😀', 25))), 16);
    },

    'presenceLabel reports online, offline and formatted offline time states' => static function (): void {
        assertSameValue(presenceLabel(null), 'Offline');
        assertSameValue(presenceLabel('not-a-date'), 'Offline');
        assertSameValue(presenceLabel(gmdate('c')), 'Online');

        $oldTimestamp = time() - (PRESENCE_TTL_SECONDS + 3600);
        $label = presenceLabel(gmdate('c', $oldTimestamp));
        assertStringContains($label, ' at ');
    },

    'formatChatListTime handles invalid and valid timestamps' => static function (): void {
        assertSameValue(formatChatListTime(null), '');
        assertSameValue(formatChatListTime(''), '');
        assertSameValue(formatChatListTime('invalid'), '');
        assertSameValue(formatChatListTime('2025-01-01T15:06:07+00:00'), '15:06');
    },

    'chatListPreview prioritizes body then attachment indicators' => static function (): void {
        assertSameValue(chatListPreview(['last_message_body' => 'hello']), 'hello');
        assertSameValue(chatListPreview(['last_message_body' => '', 'last_message_image_path' => 'storage/tmp/1.jpg']), '📷 Photo');
        assertSameValue(chatListPreview(['last_message_body' => '', 'last_message_audio_path' => 'storage/uploads/1.m4a']), '🎤 Voice message');
        assertSameValue(chatListPreview(['last_message_body' => '', 'last_message_file_path' => 'storage/uploads/1.pdf']), '📎 File');
        assertSameValue(chatListPreview(['last_message_body' => '', 'last_message_attachment_expired' => 1]), 'Attachment expired');
        assertSameValue(chatListPreview(['last_message_body' => '']), 'Start chatting');
    },

    'groupChatListPreview prioritizes body then attachment indicators' => static function (): void {
        assertSameValue(groupChatListPreview(['last_message_body' => 'hello group']), 'hello group');
        assertSameValue(groupChatListPreview(['last_message_body' => '', 'last_message_image_path' => 'storage/tmp/1.jpg']), '📷 Photo');
        assertSameValue(groupChatListPreview(['last_message_body' => '', 'last_message_audio_path' => 'storage/uploads/1.m4a']), '🎤 Voice message');
        assertSameValue(groupChatListPreview(['last_message_body' => '', 'last_message_file_path' => 'storage/uploads/1.pdf']), '📎 File');
        assertSameValue(groupChatListPreview(['last_message_body' => '']), 'Group created');
    },
];
