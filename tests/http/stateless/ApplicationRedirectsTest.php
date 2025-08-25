<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\Group;
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('http')]
final class ApplicationRedirectsTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testRedirectWhenRouteIsSiteRedirect(): void
    {
        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createRequest('GET', 'site/redirect'));

        self::assertSame(
            302,
            $response->getStatusCode(),
            "Expected HTTP '302' for route 'site/redirect'.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/html; charset=UTF-8' for route 'site/redirect'.",
        );
        self::assertSame(
            '/site/index',
            $response->getHeaderLine('Location'),
            "Expected redirect to '/site/index'.",
        );
    }
}
