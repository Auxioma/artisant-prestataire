<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messaging;

use App\Messaging\Domain\Conversation\Conversation;
use App\Messaging\Domain\Conversation\ConversationId;
use App\Messaging\Domain\Conversation\ConversationKind;
use App\Messaging\Domain\Conversation\Event\MessagePosted;
use App\Messaging\Domain\Conversation\Message;
use App\Messaging\Domain\Conversation\MessagingRuleViolation;
use App\Shared\Domain\Text\ContactDetector;
use PHPUnit\Framework\TestCase;

/** RG-081 (pas de démarchage), participants seuls, blocage, messages bornés ; RG-080 masquage des coordonnées. */
final class ConversationTest extends TestCase
{
    private const string CLIENT = '0190f5e4-0000-7000-8000-00000000c701';
    private const string ARTISAN_USER = '0190f5e4-0000-7000-8000-00000000a701';

    private static function request(): Conversation
    {
        return Conversation::start(new ConversationId('0190f5e4-0000-7000-8000-00000000e701'), ConversationKind::Request, 'request-fictive', self::CLIENT, 'artisan-fictif', self::ARTISAN_USER, self::ARTISAN_USER, new \DateTimeImmutable());
    }

    public function testPostingRecordsEventForTheCounterpart(): void
    {
        $conversation = self::request();
        $conversation->post(new Message('m1', self::ARTISAN_USER, 'Bonjour', null, new \DateTimeImmutable()));

        $events = $conversation->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MessagePosted::class, $events[0]);
        self::assertSame(self::CLIENT, $events[0]->recipientUserId);
        self::assertCount(1, $conversation->releasePosted());
    }

    public function testArtisanCannotColdCallWithAPreQuestion(): void
    {
        $this->expectException(MessagingRuleViolation::class);
        Conversation::start(new ConversationId('0190f5e4-0000-7000-8000-00000000e702'), ConversationKind::PreQuestion, null, self::CLIENT, 'artisan-fictif', self::ARTISAN_USER, self::ARTISAN_USER, new \DateTimeImmutable());
    }

    public function testStrangersAndBlockedThreadsCannotPost(): void
    {
        $conversation = self::request();
        try {
            $conversation->post(new Message('m1', '0190f5e4-0000-7000-8000-00000000f701', 'Intrus', null, new \DateTimeImmutable()));
            self::fail('intrus accepté');
        } catch (MessagingRuleViolation) {
            self::addToAssertionCount(1);
        }
        $conversation->block(self::CLIENT);
        $this->expectExceptionMessage('bloquée');
        $conversation->post(new Message('m2', self::ARTISAN_USER, 'Encore moi', null, new \DateTimeImmutable()));
    }

    public function testOnlyTheBlockerUnblocks(): void
    {
        $conversation = self::request();
        $conversation->block(self::CLIENT);
        $this->expectException(MessagingRuleViolation::class);
        $conversation->unblock(self::ARTISAN_USER);
    }

    public function testEmptyAndTooLongMessagesAreRefused(): void
    {
        foreach (['   ', str_repeat('a', Message::MAX_LENGTH + 1)] as $body) {
            try {
                new Message('m', self::CLIENT, $body, null, new \DateTimeImmutable());
                self::fail('message accepté');
            } catch (MessagingRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testContactsAreMasked(): void
    {
        [$masked, $found] = ContactDetector::mask('Appelez-moi au 06 12 34 56 78 ou artisan.fictif@exemple.test, voir www.exemple-fictif.fr');

        self::assertSame([ContactDetector::EMAIL, ContactDetector::PHONE, ContactDetector::LINK], $found);
        self::assertStringNotContainsString('06 12', $masked);
        self::assertStringNotContainsString('@', $masked);
        self::assertSame(3, substr_count($masked, ContactDetector::MASK));
        self::assertSame(['Rendez-vous mardi à 10 h', []], ContactDetector::mask('Rendez-vous mardi à 10 h'));
    }
}
