<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\PhoneNumberRequirement;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\PhoneNumberSubscriber;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * Das Feld erscheint genau dann, wenn die Sperre greift.
 *
 * Fielen Sperre und Feld auseinander, stünde der Kunde vor einer Bestellung, die sich nicht
 * abschließen lässt, ohne dass irgendwo stünde warum. Beide fragen denselben Dienst, und dieser
 * Test hält fest, dass es dabei bleibt.
 */
final class PhoneNumberSubscriberTest extends TestCase
{
    /**
     * Was: Die Nummer fehlt.
     * Warum: Dann gehört das Feld auf die Seite, sonst ist die Sperre eine Sackgasse.
     */
    public function testItAddsTheFieldWhenTheNumberIsMissing(): void
    {
        $event = $this->event();

        $this->subscriber(missing: true)->onConfirmPage($event);

        self::assertTrue($event->getPage()->hasExtension(PhoneNumberSubscriber::EXTENSION_NAME));
    }

    /**
     * Was: Die Nummer steht da.
     * Warum: Ein Feld für etwas, das nicht fehlt, verwirrt — und sähe aus, als sei die
     *        gespeicherte Nummer nicht angekommen.
     */
    public function testItAddsNothingWhenTheNumberIsThere(): void
    {
        $event = $this->event();

        $this->subscriber(missing: false)->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension(PhoneNumberSubscriber::EXTENSION_NAME));
    }

    private function subscriber(bool $missing): PhoneNumberSubscriber
    {
        $requirement = $this->createMock(PhoneNumberRequirement::class);
        $requirement->method('isMissing')->willReturn($missing);

        return new PhoneNumberSubscriber($requirement);
    }

    private function event(): CheckoutConfirmPageLoadedEvent
    {
        return new CheckoutConfirmPageLoadedEvent(
            new CheckoutConfirmPage(),
            $this->createMock(SalesChannelContext::class),
            new Request(),
        );
    }
}
