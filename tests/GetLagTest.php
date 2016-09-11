<?php
/*
    This file is part of Erebot.

    Erebot is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Erebot is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Erebot.  If not, see <http://www.gnu.org/licenses/>.
*/

class   Erebot_Module_LagCheckerTestHelper
extends \Erebot\Module\LagChecker
{
    public function setLastSent($sent)
    {
        $this->lastSent = $sent;
    }

    public function setLastReceived($rcvd)
    {
        $this->lastRcvd = $rcvd;
    }
}

class   GetLagTest
extends Erebot_Testenv_Module_TestCase
{
    protected function _mockPrivateText()
    {
        $event = $this->getMockBuilder('\\Erebot\\Interfaces\\Event\\PrivateText')->getMock();

        $event
            ->expects($this->any())
            ->method('getConnection')
            ->will($this->returnValue($this->_connection));
        $event
            ->expects($this->any())
            ->method('getSource')
            ->will($this->returnValue('foo'));
        $event
            ->expects($this->any())
            ->method('getText')
            ->will($this->returnValue('!lag'));
        return $event;
    }

    public function setUp()
    {
        $this->_now = time();
        $this->_module = new Erebot_Module_LagCheckerTestHelper(NULL);
        parent::setUp();

        // Would otherwise fail due to timers being used.
        $this->_module->reloadModule($this->_connection, 0);
    }

    public function tearDown()
    {
        $this->_module->unloadModule();
        parent::tearDown();
    }

    public function testGetLag()
    {
        $this->assertSame(NULL, $this->_module->getLag());

        $event = $this->_mockPrivateText();
        $this->_module->handleGetLag($this->_eventHandler, $event);
        $this->assertSame(1, count($this->_outputBuffer));
        $this->assertSame(
            "PRIVMSG foo :No lag measure has been done yet",
            $this->_outputBuffer[0]
        );

        // Clear output buffer.
        $this->_outputBuffer = array();
        $this->_module->setLastSent($this->_now);
        $this->_module->setLastReceived($this->_now + 42);

        $event = $this->_mockPrivateText();
        $this->_module->handleGetLag($this->_eventHandler, $event);
        $this->assertSame(1, count($this->_outputBuffer));
        $this->assertSame(
            'PRIVMSG foo :Current lag: '.($this->_now + 42 - $this->_now).' seconds',
            $this->_outputBuffer[0]
        );
    }
}

