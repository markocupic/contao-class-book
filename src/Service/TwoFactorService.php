<?php

declare(strict_types=1);

/*
 * This file is part of Contao Class Book.
 *
 * (c) Marko Cupic <m.cupic@gmx.ch>
 * @license MIT
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/contao-class-book
 */

namespace Markocupic\ContaoClassBook\Service;

use Contao\CoreBundle\Security\TwoFactor\Authenticator;
use Contao\CoreBundle\Security\TwoFactor\BackupCodeManager;
use Contao\CoreBundle\Security\TwoFactor\TrustedDeviceManager;
use Contao\FrontendUser;
use ParagonIE\ConstantTime\Base32;
use Symfony\Component\HttpFoundation\Request;

/**
 * The two-factor authentication of a member (TOTP with an authenticator app).
 *
 * This is the same logic as in the two-factor module of Contao and it uses the
 * same services, so a member can also use that module.
 */
final class TwoFactorService
{
    public function __construct(
        private readonly Authenticator $authenticator,
        private readonly BackupCodeManager $backupCodeManager,
        private readonly TrustedDeviceManager $trustedDeviceManager,
    ) {
    }

    /**
     * @return array{enabled: bool, backupCodes: int}
     */
    public function getStatus(FrontendUser $user): array
    {
        try {
            $backupCodes = json_decode((string) $user->backupCodes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $backupCodes = [];
        }

        return [
            'enabled' => (bool) $user->useTwoFactor,
            'backupCodes' => \is_array($backupCodes) ? \count($backupCodes) : 0,
        ];
    }

    /**
     * Starts the setup: creates the secret if there is none and returns it with the QR code
     * (SVG, base64 encoded) for the authenticator app. Returns null if the two-factor
     * authentication is enabled already, the secret is not shown again then.
     *
     * @return array{secret: string, qrCode: string}|null
     */
    public function setup(FrontendUser $user, Request $request): array|null
    {
        if ($user->useTwoFactor) {
            return null;
        }

        if (!$user->secret) {
            $user->secret = random_bytes(128);
            $user->save();
        }

        return [
            'secret' => Base32::encodeUpperUnpadded($user->secret),
            'qrCode' => base64_encode($this->authenticator->getQrCode($user, $request)),
        ];
    }

    /**
     * Enables the two-factor authentication if the code of the authenticator app is valid.
     */
    public function enable(FrontendUser $user, string $code): bool
    {
        $code = (string) preg_replace('/\s+/', '', $code);

        if (!$user->secret || '' === $code || !$this->authenticator->validateCode($user, $code)) {
            return false;
        }

        $user->useTwoFactor = true;
        $user->save();

        return true;
    }

    public function disable(FrontendUser $user): void
    {
        $user->secret = null;
        $user->useTwoFactor = false;
        $user->backupCodes = null;
        $user->save();

        $this->trustedDeviceManager->clearTrustedDevices($user);
    }

    /**
     * Creates new backup codes, the old ones are no longer valid. The codes are stored
     * as hashes, so they can only be shown now. Returns null if the two-factor
     * authentication is not enabled.
     *
     * @return list<string>|null
     */
    public function generateBackupCodes(FrontendUser $user): array|null
    {
        if (!$user->useTwoFactor) {
            return null;
        }

        return array_values($this->backupCodeManager->generateBackupCodes($user));
    }
}
