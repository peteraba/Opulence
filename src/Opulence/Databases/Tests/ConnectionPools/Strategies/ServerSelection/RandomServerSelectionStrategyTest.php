<?php

/*
 * Opulence
 *
 * @link      https://www.opulencephp.com
 * @copyright Copyright (C) 2017 David Young
 * @license   https://github.com/opulencephp/Opulence/blob/master/LICENSE.md
 */

namespace Opulence\Databases\Tests\ConnectionPools\Strategies\ServerSelection;

use InvalidArgumentException;
use Opulence\Databases\ConnectionPools\Strategies\ServerSelection\RandomServerSelectionStrategy;
use Opulence\Databases\Server;

/**
 * Tests the random server selection strategy
 */
class RandomServerSelectionStrategyTest extends \PHPUnit\Framework\TestCase
{
    /** @var RandomServerSelectionStrategy The strategy to use in tests */
    private $strategy = null;

    /**
     * Sets up the tests
     */
    public function setUp() : void
    {
        $this->strategy = new RandomServerSelectionStrategy();
    }

    /**
     * Tests that an exception is thrown when passing an empty list of servers
     */
    public function testExceptionThrownWithEmptyListOfServers() : void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->strategy->select([]);
    }

    /**
     * Tests selecting from a list of a servers
     */
    public function testSelectingFromListOfServers() : void
    {
        $server1 = $this->getServerMock();
        $server2 = $this->getServerMock();
        $this->assertTrue(in_array($this->strategy->select([$server1, $server2]), [$server1, $server2]));
    }

    /**
     * Tests selecting from a list of a single server
     */
    public function testSelectingFromListOfSingleServer() : void
    {
        $server = $this->getServerMock();
        $this->assertSame($server, $this->strategy->select([$server]));
    }

    /**
     * Tests selecting from a single server
     */
    public function testSelectingFromSingleServer() : void
    {
        $server = $this->getServerMock();
        $this->assertSame($server, $this->strategy->select($server));
    }

    /**
     * Gets a mock server
     *
     * @return Server|\PHPUnit_Framework_MockObject_MockObject
     */
    private function getServerMock()
    {
        return $this->getMockBuilder(Server::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}