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
    protected function _mockPrivateText()
    {
        $event = $this->getMock(
            'Erebot_Interface_Event_PrivateText',
            array(), array(), '', FALSE, FALSE
        );

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
        parent::setUp();
        $this->_now = microtime(TRUE);
        $this->_module = new Erebot_Module_LagCheckerTestHelper(NULL);
        $styling = $this->getMockForAbstractClass(
            'StylingStub',
            array(), '', FALSE, FALSE
        );
        $this->_module->setFactory('!Styling', get_class($styling));

        // Would otherwise fail due to timers being used.
        $this->_module->reload($this->_connection, 0);
    }

    public function tearDown()
    {
        $this->_module->unload();
        parent::setUp();
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
        // Sounds stupid, right ? But still necessary...
        $lag = (string) ($this->_now + 3.14 - $this->_now);
        $this->_module->setLastSent($this->_now);
        $this->_module->setLastReceived($this->_now + 3.14);

        $event = $this->_mockPrivateText();
        $this->_module->handleGetLag($this->_eventHandler, $event);
        $this->assertSame(1, count($this->_outputBuffer));
        $this->assertSame(
            'PRIVMSG foo :Current lag: <var name="'.$lag.'"/> seconds',
            $this->_outputBuffer[0]
        );
    }
}

