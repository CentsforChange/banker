<?php

use CentsforChange\Banker;

class BankerTest extends \PHPUnit\Framework\TestCase
{
    protected static $banker;

    public static function setUpBeforeClass()
    {
        echo "Set up";
        $this->banker = new \CentsforChange\Banker("5959", "HAN", "https://google.com", "example_user", "PaSSwOrd");
    }

    public function testUUID()
    {
        $reflection = new \ReflectionClass(get_class(self::banker));
        $method = $reflection->getMethod("uuid");
        $method->setAccessible(true);

        $this->assertEquals(strlen($method->invokeArgs($object, array(4312))), 4312);
    }
}
