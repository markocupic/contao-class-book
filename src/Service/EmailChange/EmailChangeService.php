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

namespace Markocupic\ContaoClassBook\Service\EmailChange;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\OptIn\OptInInterface;
use Contao\OptInModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Changes the e-mail address of a member in two steps: the new address gets a link, and
 * only when the link is opened the address is changed. Then the old address gets a notice.
 *
 * The link is a double opt-in token of Contao that holds the new address. Until it is
 * confirmed, nothing changes at the member.
 */
final class EmailChangeService
{
    // At most six characters, see OptIn::create()
    public const TOKEN_PREFIX = 'cbmail';

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'contao.opt_in')]
        private readonly OptInInterface $optIn,
        private readonly EmailChangeValidator $validator,
        private readonly EmailChangeMailer $mailer,
        private readonly ContaoFramework $framework,
    ) {
    }

    /**
     * Sends the link to the new address.
     *
     * The caller gets no result on purpose: if the address belongs to another member nothing
     * is sent, and the app shows the same message, so that nobody can find out who has an account.
     *
     * @param string $email validated, see EmailChangeValidator
     * @param string $url   the page with the class book
     */
    public function request(int $memberId, string $email, string $url, string $locale): void
    {
        if ($this->validator->isTaken($email, $memberId)) {
            return;
        }

        // Only the link of the last request is valid
        $this->removeOpenTokens($memberId);

        $token = $this->optIn->create(self::TOKEN_PREFIX, $email, ['tl_member' => [$memberId]]);
        $link = $url.(str_contains($url, '?') ? '&' : '?').'token='.$token->getIdentifier();

        $sent = $this->mailer->sendLink($email, $this->getFirstname($memberId), (string) parse_url($url, PHP_URL_HOST), $link, $locale);

        // Keep what was sent with the token, like Contao does
        $this->connection->update('tl_opt_in', ['emailSubject' => $sent['subject'], 'emailText' => $sent['text']], ['token' => $token->getIdentifier()]);
    }

    /**
     * Changes the address of the member the token belongs to and tells the old address.
     *
     * @param string $host the website, for the notice
     *
     * @return bool false if the link is invalid, has expired or was used before
     */
    public function confirm(string $identifier, string $host, string $locale): bool
    {
        if (!str_starts_with($identifier, self::TOKEN_PREFIX.'-')) {
            return false;
        }

        $token = $this->optIn->find($identifier);

        if (null === $token || !$token->isValid() || $token->isConfirmed()) {
            return false;
        }

        $related = $token->getRelatedRecords();

        if (['tl_member'] !== array_keys($related) || 1 !== \count($related['tl_member'])) {
            return false;
        }

        $memberId = (int) current($related['tl_member']);
        $email = mb_strtolower($token->getEmail());
        $member = $this->connection->fetchAssociative('SELECT email, language FROM tl_member WHERE id = ?', [$memberId]);

        // Somebody else may have got the address since the link was sent
        if (false === $member || '' === $email || $this->validator->isTaken($email, $memberId)) {
            return false;
        }

        $this->connection->update('tl_member', ['email' => $email, 'tstamp' => time()], ['id' => $memberId]);

        // Invalidates the other open links of the member as well
        $token->confirm();

        $old = (string) $member['email'];

        if ('' !== $old && mb_strtolower($old) !== $email) {
            try {
                $this->mailer->sendNotice($old, $this->getFirstname($memberId), $host, $email, (string) $member['language'] ?: $locale);
            } catch (\Throwable) {
                // The address is changed, a notice that cannot be delivered does not undo that
            }
        }

        return true;
    }

    private function getFirstname(int $memberId): string
    {
        return trim(StringUtil::decodeEntities((string) $this->connection->fetchOne('SELECT firstname FROM tl_member WHERE id = ?', [$memberId])));
    }

    private function removeOpenTokens(int $memberId): void
    {
        $this->framework->initialize();

        $models = $this->framework->getAdapter(OptInModel::class)->findByRelatedTableAndIds('tl_member', [$memberId]);

        foreach ($models ?? [] as $model) {
            if (str_starts_with((string) $model->token, self::TOKEN_PREFIX.'-') && !$model->confirmedOn) {
                $model->delete();
            }
        }
    }
}
