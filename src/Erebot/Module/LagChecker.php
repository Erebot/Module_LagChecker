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

class   Erebot_Module_LagChecker
extends Erebot_Module_Base
{
    static protected $_metadata = array(
        'requires'  =>  array(
            'Erebot_Module_TriggerRegistry',
            'Erebot_Module_Helper',
        ),
    );
    protected $_timerPing;
    protected $_timerPong;
    protected $_timerQuit;

    protected $_delayPing;
    protected $_delayPong;
    protected $_delayReco;

    protected $_lastSent;
    protected $_lastRcvd;

    protected $_trigger;

    public function reload($flags)
    {
        if ($flags & self::RELOAD_MEMBERS) {
            if (!($flags & self::RELOAD_INIT)) {
                $timers =   array('_timerPing', '_timerPong', '_timerQuit');

                foreach ($timers as $timer) {
                    try {
                        $this->removeTimer($this->$timer);
                    }
                    catch (Erebot_ExceptionNotFound $e) {}
                    unset($this->$timer);
                    $this->$timer = NULL;
                }

                $this->_trigger = NULL;
            }

            $this->_delayPing   = $this->parseInt('check');
            $this->_delayPong   = $this->parseInt('timeout');
            $this->_delayReco   = $this->parseInt('reconnect');

            $this->_timerPing   = NULL;
            $this->_timerPong   = NULL;
            $this->_timerQuit   = NULL;

            $this->_lastRcvd    = NULL;
            $this->_lastSent    = NULL;
        }

        if ($flags & self::RELOAD_HANDLERS) {
            $handlers = array(
                            'handlePong'        => 'Erebot_Event_Pong',
                            'handleExit'        => 'Erebot_Event_Exit',
                            'handleConnect'     => 'Erebot_Event_Connect',
                        );

            foreach ($handlers as $callback => $event_type) {
                $handler    =   new Erebot_EventHandler(
                                    array($this, $callback),
                                    $event_type);
                $this->_connection->addEventHandler($handler);
            }

            $registry   =   $this->_connection->getModule(
                'Erebot_Module_TriggerRegistry'
            );

            $trigger    = $this->parseString('trigger', 'lag');
            $matchAny  = Erebot_Utils::getVStatic($registry, 'MATCH_ANY');

            $this->_trigger  =   $registry->registerTriggers(
                $trigger, $matchAny);
            if ($this->_trigger === NULL)
                throw new Exception($this->_translator->gettext(
                    'Unable to register trigger for Lag Checker'));

            $handler = new Erebot_EventHandler(
                array($this, 'handleGetLag'),
                'Erebot_Interface_Event_TextMessage',
                NULL,
                new Erebot_TextFilter_Static($trigger, TRUE)
            );
            $this->_connection->addEventHandler($handler);
            $this->registerHelpMethod(array($this, 'getHelp'));
        }
    }

    public function getHelp(Erebot_Interface_Event_TextMessage &$event, $words)
    {
        if ($event instanceof Erebot_Interface_Event_Private) {
            $target = $event->getSource();
            $chan   = NULL;
        }
        else
            $target = $chan = $event->getChan();

        $translator = $this->getTranslator($chan);
        $trigger    = $this->parseString('trigger', 'lag');

        $bot        =&  $this->_connection->getBot();
        $moduleName =   strtolower(get_class());
        $nbArgs     =   count($words);

        if ($nbArgs == 1 && $words[0] == $moduleName) {
            $msg = $translator->gettext('
Provides the <b><var name="trigger"/></b> command which prints
the current lag.
');
            $formatter = new Erebot_Styling($msg, $translator);
            $formatter->assign('trigger', $trigger);
            $this->sendMessage($target, $formatter->render());
            return TRUE;
        }

        if ($nbArgs < 2)
            return FALSE;

        if ($words[1] == $trigger) {
            $msg = $translator->gettext("
<b>Usage:</b> !<var name='trigger'/>.
Display the latency of the connection, that is, the number of seconds
it takes for a message from the bot to go to the IRC server and back.
");
            $formatter = new Erebot_Styling($msg, $translator);
            $formatter->assign('trigger', $trigger);
            $this->sendMessage($target, $formatter->render());

            return TRUE;
        }
    }

    public function checkLag(Erebot_Timer &$timer)
    {
        $this->_timerPong   =   new Erebot_Timer(
                                    array($this, 'disconnect'),
                                    $this->_delayPong, FALSE);
        $this->addTimer($this->_timerPong);

        $this->_lastSent    = microtime(TRUE);
        $this->_lastRcvd    = NULL;
        $this->sendCommand('PING '.$this->_lastSent);
    }

    public function handlePong(Erebot_Interface_Event_Generic &$event)
    {
        if ($event->getText() != ((string) $this->_lastSent))
            return;

        $this->_lastRcvd = microtime(TRUE);
        $this->removeTimer($this->_timerPong);
        unset($this->_timerPong);
        $this->_timerPong = NULL;
    }

    public function handleExit(Erebot_Interface_Event_Generic &$event)
    {
        if ($this->_timerPing) {
            $this->removeTimer($this->_timerPing);
            unset($this->_timerPing);
        }

        if ($this->_timerPong) {
            $this->removeTimer($this->_timerPong);
            unset($this->_timerPong);
        }
    }

    public function disconnect(Erebot_Interface_Timer &$timer)
    {
        $this->_connection->disconnect();

        $config     =&  $this->_connection->getConfig(NULL);
        $logging    =&  Plop::getInstance();
        $logger     =   $logging->getLogger(__FILE__);
        $logger->info($this->_translator->gettext(
            'Lag got too high for "%(server)s" ... '.
            'reconnecting in %(delay)d seconds'),
            array(
                'server'    => $config->getConnectionURL(),
                'delay'     => $this->_delayReco,
            ));

        $this->_timerQuit   =   new Erebot_Timer(
                                    array($this, 'reconnect'),
                                    $this->_delayReco, TRUE);
        $this->addTimer($this->_timerQuit);

        try {
            if ($this->_timerPing !== NULL)
                $this->removeTimer($this->_timerPing);
        }
        catch (Erebot_Exception $e) {
        }

        try {
            if ($this->_timerPong !== NULL)
                $this->removeTimer($this->_timerPong);
        }
        catch (Erebot_Exception $e) {
        }

        unset($this->_timerPing, $this->_timerPong);
        $this->_timerPing   = NULL;
        $this->_timerPong   = NULL;
    }

    public function reconnect(Erebot_Interface_Timer &$timer)
    {
        $config     =&  $this->_connection->getConfig(NULL);
        $logging    =&  Plop::getInstance();
        $logger     =   $logging->getLogger(__FILE__);
        $logger->info($this->_translator->gettext(
                        'Attempting reconnection to "%s"'),
                        $config->getConnectionURL());

        try {
            $this->_connection->connect();
            $bot =& $this->_connection->getBot();
            $bot->addConnection($this->_connection);

            $this->removeTimer($this->_timerQuit);
            unset($this->_timerQuit);
            $this->_timerQuit   = NULL;
        }
        catch (Erebot_ExceptionConnectionFailure $e) {}
    }

    public function getLag()
    {
        if ($this->_lastRcvd === NULL)
            return NULL;
        return ($this->_lastRcvd - $this->_lastSent);
    }

    public function handleGetLag(Erebot_Interface_Event_TextMessage &$event)
    {
        if ($event instanceof Erebot_Interface_Event_Private) {
            $target = $event->getSource();
            $chan   = NULL;
        }
        else
            $target = $chan = $event->getChan();

        $lag        = $this->getLag();
        $translator = $this->getTranslator($chan);

        if ($lag === NULL)
            $this->sendMessage($target, $translator->gettext(
                'No lag measure has been done yet'));
        else {
            $msg = $translator->gettext(
                'Current lag: <var name="lag"/> seconds');
            $formatter = new Erebot_Styling($msg, $translator);
            $formatter->assign('lag', $lag);
            $this->sendMessage($target, $formatter->render());
        }
    }

    public function handleConnect(Erebot_Interface_Event_Generic &$event)
    {
        $this->_timerPing   =   new Erebot_Timer(
                                    array($this, 'checkLag'),
                                    $this->_delayPing, TRUE);
        $this->addTimer($this->_timerPing);

        if ($this->_trigger !== NULL) {
            try {
                $registry   =   $this->_connection->getModule(
                    'Erebot_Module_TriggerRegistry'
                );
                $registry->freeTriggers($this->_trigger);
            }
            catch (Erebot_Exception $e) {
            }
        }
    }
}

