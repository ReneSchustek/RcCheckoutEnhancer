<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout;

/**
 * Wie der Checkout für Gäste und Neukunden aussieht.
 *
 * Die Werte von `ONE_PAGE` und `GUIDED` sind dieselben wie die des Schalters `checkout_layout`
 * in RcAbTesting. So kann die Vorlage beide Quellen gleich behandeln.
 */
final class CheckoutLayout
{
    public const GUIDED = 'guided';

    public const ONE_PAGE = 'one_page';

    public const AB_TEST = 'ab_test';

    public const ALL = [self::GUIDED, self::ONE_PAGE, self::AB_TEST];

    /**
     * Die Twig-Erweiterung von RcAbTesting. Nur mit ihr gibt es `ab_switch()` in der Vorlage.
     */
    public const AB_TWIG_EXTENSION = 'Ruhrcoder\\RcAbTesting\\Twig\\Extension\\RcAbTwigExtension';

    /**
     * Kann der einseitige Checkout erscheinen? Beim A/B-Test nur mit RcAbTesting, ohne zeigt die
     * Vorlage den geführten Ablauf.
     */
    public static function mayShowOnePage(string $layout): bool
    {
        return $layout === self::ONE_PAGE
            || ($layout === self::AB_TEST && class_exists(self::AB_TWIG_EXTENSION));
    }
}
