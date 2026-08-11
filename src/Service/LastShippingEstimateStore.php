<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Ruhrcoder\RcCheckoutEnhancer\Struct\LastShippingEstimate;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimateResult;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Bewahrt die zuletzt abgefragte Versandkosten-Auskunft in der Sitzung auf, damit die
 * Warenkorb-Seitenleiste sie zeigen kann, ohne sie erneut zu erfragen.
 *
 * In der Sitzung und nicht in einem eigenen Cookie: Shopware führt für den Warenkorb
 * ohnehin eine, damit entsteht kein zusätzlicher Einwilligungsfall nach § 25 TDDDG.
 *
 * Gespeichert wird nur die **günstigste** Versandart. Die Leiste hat Platz für eine
 * Zeile; die vollständige Liste steht auf der Warenkorb-Seite, wo sie hingehört.
 */
// Bewusst nicht `final`: Der Subscriber, der diesen Speicher benutzt, wird gegen ein
// Test-Double geprüft, und eine `final`-Klasse lässt sich nicht doubeln. Eine
// Schnittstelle nur für diesen Zweck einzuziehen, wäre mehr Bauwerk als Nutzen.
class LastShippingEstimateStore
{
    private const SESSION_KEY = 'rcCheckoutLastShippingEstimate';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function remember(ShippingEstimateResult $result, string $cartFingerprint): void
    {
        $session = $this->requestStack->getSession();

        // Nur eine gelungene Auskunft ist eine Auskunft. „Kein Versand in dieses Land"
        // und „die Berechnung ist gescheitert" gehören nicht in die Leiste: Das eine
        // wäre eine Absage ohne Zusammenhang, das andere eine Entschuldigung an einer
        // Stelle, an der niemand danach gefragt hat.
        if (!$result->isSuccessful() || $result->estimates === []) {
            $session->remove(self::SESSION_KEY);

            return;
        }

        $cheapest = $result->estimates[0];
        foreach ($result->estimates as $estimate) {
            if ($estimate->price < $cheapest->price) {
                $cheapest = $estimate;
            }
        }

        $session->set(self::SESSION_KEY, (new LastShippingEstimate(
            $result->countryIso,
            $result->zipCode,
            $cheapest->name,
            $cheapest->price,
            $cheapest->currencyIsoCode,
            $cartFingerprint,
        ))->toArray());
    }

    public function get(): ?LastShippingEstimate
    {
        $session = $this->requestStack->getSession();
        $data = $session->get(self::SESSION_KEY);

        if (!\is_array($data)) {
            return null;
        }

        return LastShippingEstimate::fromArray($data);
    }
}
