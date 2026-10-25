<?php

declare(strict_types=1);

namespace App\Application\Engine\Notification;

use App\Application\Engine\State\HomeAssistantContext;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Repository\GlobalSettingsRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The texts of the notifications (translation domain `notifications`, ICU messages `<type>.title` and
 * `<type>.message`) in the language of the notified people: the locale forced in the settings if any, else the
 * Home Assistant language, else English. A language the add-on is not translated into falls back to English.
 */
final readonly class NotificationTexts
{
    public const string DEFAULT_LOCALE = 'en';
    public const string DOMAIN = 'notifications';

    /** @var list<string> */
    private const array SUPPORTED_LOCALES = ['en', 'fr'];

    public function __construct(
        private TranslatorInterface $translator,
        private GlobalSettingsRepository $settings,
        private HomeAssistantContext $context,
    ) {
    }

    /**
     * @param array<string, scalar|null> $params
     */
    public function title(NotificationType $type, array $params = []): string
    {
        return $this->translator->trans($type->value.'.title', $params, self::DOMAIN, $this->locale());
    }

    /**
     * @param array<string, scalar|null> $params
     */
    public function message(NotificationType $type, array $params = []): string
    {
        return $this->translator->trans($type->value.'.message', $params, self::DOMAIN, $this->locale());
    }

    public function testTitle(): string
    {
        return $this->translator->trans('test.title', [], self::DOMAIN, $this->locale());
    }

    public function testMessage(): string
    {
        return $this->translator->trans('test.message', [], self::DOMAIN, $this->locale());
    }

    public function locale(): string
    {
        $wanted = $this->settings->get()->getNotificationLocale() ?? $this->context->language();
        $language = strtolower(substr($wanted, 0, 2));

        return \in_array($language, self::SUPPORTED_LOCALES, true) ? $language : self::DEFAULT_LOCALE;
    }
}
