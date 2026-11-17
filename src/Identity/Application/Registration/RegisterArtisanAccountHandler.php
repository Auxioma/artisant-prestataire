<?php

declare(strict_types=1);

namespace App\Identity\Application\Registration;

use App\Identity\Domain\Legal\LegalDocumentType;
use App\Identity\Domain\User\PersonName;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserKind;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\ValueObject\Email;
use App\Shared\Domain\ValueObject\PhoneNumber;

/**
 * Le profil professionnel (entreprise, métiers, zone…) est créé par le module ArtisanProfile aux étapes suivantes ;
 * la vérification du mobile par SMS (OTP) attend la validation du coût (antifraud.sms_verify_pro_signup, P-17).
 */
final readonly class RegisterArtisanAccountHandler implements CommandHandler
{
    public const array REQUIRED_DOCUMENTS = [LegalDocumentType::TermsOfUse, LegalDocumentType::ProTermsOfSale, LegalDocumentType::Privacy];

    public function __construct(private AccountRegistrar $registrar)
    {
    }

    public function __invoke(RegisterArtisanAccount $command): void
    {
        $email = new Email($command->email);
        $mobile = new PhoneNumber($command->mobile);
        if (!self::isMobile($mobile)) {
            throw new RegistrationRejected('mobile', RegistrationRejected::MOBILE_REQUIRED);
        }
        $documents = $this->registrar->check($email, $command->password, self::REQUIRED_DOCUMENTS, $command->acceptedDocumentIds);
        $this->registrar->register(new UserId($command->userId), $email, $command->password, UserKind::Artisan, new PersonName($command->firstName), new PersonName($command->lastName), $mobile, $documents, $command->ip, $command->ipHash);
    }

    /** Mobile français (06, 07) ; numéro étranger admis tel quel (format international vérifié par PhoneNumber). */
    public static function isMobile(PhoneNumber $phone): bool
    {
        return !str_starts_with($phone->value, '+33') || 1 === preg_match('/^\+33[67]\d{8}$/', $phone->value);
    }
}
