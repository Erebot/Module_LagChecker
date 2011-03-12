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

require_once(
    dirname(__FILE__) .
    DIRECTORY_SEPARATOR . 'testenv' .
    DIRECTORY_SEPARATOR . 'bootstrap.php'
);

class   Erebot_Module_LagCheckerTestHelper
extends Erebot_Module_LagChecker
{
    public function setLastSent($sent)
    {
        $this->_lastSent = $sent;
    }

    public function setLastReceived($rcvd)
    {
        $this->_lastRcvd = $rcvd;
    }
}

class   GetLagTest
extends ErebotModuleTestCase
{
    public function setUp()
    {
        parent::setUp();
        $this->_module = new Erebot_Module_LagCheckerTestHelper(NULL);
        // Would otherwise fail due to timers being used.
        $this->_module->reload($this->_connection, 0);
    }

    public function tearDown()
    {
        unset($this->_module);
        parent::setUp();
    }

    public function testGetLag()
    {
        $this->assertSame(NULL, $this->_module->getLag());

        $event = new Erebot_Event_PrivateText(
            $this->_connection,
            'foo',
            '!lag'  // Does not matter.
        );
        $this->_module->handleGetLag($event);
        $this->assertSame(1, count($this->_outputBuffer));
        $this->assertSame(
            "PRIVMSG foo :No lag measure has been done yet",
            $this->_outputBuffer[0]
        );

        // Clear output buffer.
        $this->_outputBuffer = array();
        $now = microtime(TRUE);
        // Sounds stupid, right ? But still necessary...
        $lag = (string) ($now + 3.14 - $now);
        $this->_module->setLastSent($now);
        $this->_module->setLastReceived($now + 3.14);

        $event = new Erebot_Event_PrivateText(
            $this->_connection,
            'foo',
            '!lag'  // Does not matter.
        );
        $this->_module->handleGetLag($event);
        $this->assertSame(1, count($this->_outputBuffer));
        $this->assertSame(
            "PRIVMSG foo :Current lag: ".$lag." seconds",
            $this->_outputBuffer[0]
        );
    }
}

