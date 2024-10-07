<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Katorymnd\PawaPayIntegration\Config\Config;

class ConfigTest extends TestCase
{
    /**
     * Test if the sandbox environment returns the correct API URL
     */
    public function testSandboxApiUrl()
    {
        // Retrieve the sandbox API URL from the Config class
        $sandboxUrl = Config::$settings['sandbox']['api_url'];

        // Assert that the sandbox API URL is correct
        $this->assertEquals('https://api.sandbox.pawapay.cloud', $sandboxUrl);
    }

    /**
     * Test if the production environment returns the correct API URL
     */
    public function testProductionApiUrl()
    {
        // Retrieve the production API URL from the Config class
        $productionUrl = Config::$settings['production']['api_url'];

        // Assert that the production API URL is correct
        $this->assertEquals('https://api.pawapay.cloud', $productionUrl);
    }

    /**
     * Test that an invalid environment does not exist in the config
     */
    public function testInvalidEnvironment()
    {
        // Assert that an invalid environment key is not present
        $this->assertArrayNotHasKey('invalid', Config::$settings);
    }
}