<?php

declare(strict_types=1);

namespace Aurora\Core\Locale\EventSubscriber;

use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Platform\User\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;

use function is_string;

final readonly class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LocaleContextInterface $localeContext,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
            SecurityEvents::INTERACTIVE_LOGIN => 'onInteractiveLogin',
        ];
    }

    public function onInteractiveLogin(InteractiveLoginEvent $event): void
    {
        if ($this->localeContext->isSingleLocaleMode()) {
            return;
        }

        $user = $event->getAuthenticationToken()->getUser();

        if ($user instanceof User) {
            $event->getRequest()->getSession()->set('_locale', $user->getLocale()->value);
        }
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // A public page carries its language in its address - `/es/page/...`
        // - under `locale`, the route parameter of every public controller.
        // It wins over the session, which holds the language of whoever last
        // logged in to the back office: the controller did set the page's
        // locale itself, but only once the translator had already been given
        // the session's, so every string a template translated came out in
        // French on the English and Spanish pages. Read here, before
        // Symfony hands the request's locale to the translator.
        $routeLocale = $request->attributes->get('locale');

        if (is_string($routeLocale) && LocaleEnum::isSupported($routeLocale)) {
            $request->setLocale($routeLocale);

            return;
        }

        if ($this->localeContext->isSingleLocaleMode()) {
            $request->setLocale($this->localeContext->getDefaultLocale());

            return;
        }

        $locale = $request->getSession()->get('_locale', LocaleEnum::default()->value);

        if (!LocaleEnum::isSupported($locale)) {
            $locale = LocaleEnum::default()->value;
        }

        $request->setLocale($locale);
    }
}
