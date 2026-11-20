<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Domain\Client\ClientProfile;
use App\Identity\Domain\Client\ClientType;
use App\Identity\Domain\Legal\LegalDocumentType;
use App\Identity\Domain\User\IdentityRuleViolation;
use App\Identity\Domain\User\UserId;
use App\Identity\Infrastructure\Antifraud\FileDisposableEmailDetector;
use App\Shared\Domain\ValueObject\Email;
use App\Shared\Domain\ValueObject\Siret;
use PHPUnit\Framework\TestCase;

/** A02 : profil client, documents légaux, e-mails jetables. */
final class ClientRegistrationRulesTest extends TestCase
{
    private const string ID = '0190f5e4-0000-7000-8000-000000000401';

    public function testIndividualAndBusinessProfiles(): void
    {
        $now = new \DateTimeImmutable();
        $individual = ClientProfile::individual(new UserId(self::ID), ' 14000 ', $now);
        self::assertSame([ClientType::Individual, '14000', null], [$individual->type(), $individual->postalCode(), $individual->companyName()]);

        $business = ClientProfile::business(new UserId(self::ID), '14000', '  Boulangerie   Exemple ', new Siret('90000000100017'), true, $now);
        self::assertSame('Boulangerie Exemple', $business->companyName());
        self::assertTrue($business->isSiretVerified());

        $business->subscribeToNewsletter($now);
        self::assertTrue($business->isSubscribedToNewsletter());
        $business->unsubscribeFromNewsletter($now);
        self::assertFalse($business->isSubscribedToNewsletter());
    }

    public function testBusinessNeedsACompanyNameAndPostalCodeHasFiveDigits(): void
    {
        $now = new \DateTimeImmutable();
        $refused = [];
        foreach (['raison sociale vide' => static fn () => ClientProfile::business(new UserId(self::ID), '14000', ' ', null, false, $now),
            'balise' => static fn () => ClientProfile::business(new UserId(self::ID), '14000', '<b>X</b>', null, false, $now),
            'code postal' => static fn () => ClientProfile::individual(new UserId(self::ID), '1400', $now)] as $case => $build) {
            try {
                $build();
            } catch (IdentityRuleViolation) {
                $refused[] = $case;
            }
        }
        self::assertSame(['raison sociale vide', 'balise', 'code postal'], $refused);
    }

    public function testUnverifiedFlagWithoutSiret(): void
    {
        self::assertFalse(ClientProfile::business(new UserId(self::ID), '14000', 'Exemple SARL', null, true, new \DateTimeImmutable())->isSiretVerified());
    }

    public function testLegalDocumentSlugs(): void
    {
        self::assertSame(LegalDocumentType::Privacy, LegalDocumentType::fromSlug('confidentialite'));
        self::assertSame(LegalDocumentType::Privacy, LegalDocumentType::legacySlug('politique-de-confidentialite'), 'ancienne adresse redirigée');
        self::assertSame('cgu', LegalDocumentType::TermsOfUse->slug());
        self::assertSame(LegalDocumentType::LegalNotice, LegalDocumentType::fromSlug('mentions-legales'));
        self::assertNull(LegalDocumentType::fromSlug('politique-de-confidentialite'), 'ancienne adresse : redirection seulement');
    }

    public function testDisposableDomainsIncludingSubdomains(): void
    {
        $detector = new FileDisposableEmailDetector(\dirname(__DIR__, 3).'/config/identity/disposable-email-domains.txt');

        self::assertTrue($detector->isDisposable(new Email('test@yopmail.com')));
        self::assertTrue($detector->isDisposable(new Email('test@Mail.Mailinator.com')));
        foreach (['gmail.com', 'orange.fr', 'free.fr', 'laposte.net', 'outlook.fr', 'exemple.test'] as $domain) {
            self::assertFalse($detector->isDisposable(new Email('camille@'.$domain)), $domain);
        }
    }
}
