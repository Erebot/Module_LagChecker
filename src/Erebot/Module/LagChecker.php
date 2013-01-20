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

/**
 * \brief
 *      This module provides a way for Erebot
 *      to check latency.
 *
 * The bot will disconnect itself from an IRC server
 * when the lag exceeds a given threshold.
 * It will then reconnect to that server after a certain
 * delay.
 */
class   Erebot_Module_LagChecker
extends Erebot_Module_Base
{
    /// Timer for lag checks.
    protected $_timerPing;

    /// Timer defining the timeout for lag responses.
    protected $_timerPong;

    /// Timer used to reconnect the bot after a disconnection due to latency.
    protected $_timerQuit;

    /// Delay between lag checks.
    protected $_delayPing;

    /// Timeout for lag responses.
    protected $_delayPong;

    /// Delay before the bot reconnects after a disconnection due to latency.
    protected $_delayReco;

    /// Timestamp of the last lag check sent.
    protected $_lastSent;

    /// Timestamp of the last lag response received.
    protected $_lastRcvd;

    /// Trigger registered by this module.
    protected $_trigger;

    /**
     * This method is called whenever the module is (re)loaded.
     *
     * \param int $flags
     *      A bitwise OR of the Erebot_Module_Base::RELOAD_*
     *      constants. Your method should take proper actions
     *      depending on the value of those flags.
     *
     * \note
     *      See the documentation on individual RELOAD_*
     *      constants for a list of possible values.
     *
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function _reload($flags)
    {
        if ($flags & self::RELOAD_MEMBERS) {
            if (!($flags & self::RELOAD_INIT)) {
                $timers =   array('_timerPing', '_timerPong', '_timerQuit');

                foreach ($timers as $timer) {
                    try {
                        $this->removeTimer($this->$timer);
                    }
                    catch (Erebot_ExceptionNotFound $e) {
                    }
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
                'handlePong'        => 'Erebot_Interface_Event_Pong',
                'handleExit'        => 'Erebot_Interface_Event_Exit',
                'handleConnect'     => 'Erebot_Interface_Event_Connect',
            );

            foreach ($handlers as $callback => $eventType) {
                $handler = new Erebot_EventHandler(
                    new Erebot_Callable(array($this, $callback)),
                    new Erebot_Event_Match_InstanceOf($eventType)
                );
                $this->_connection->addEventHandler($handler);
            }

            $registry   =   $this->_connection->getModule(
                'Erebot_Module_TriggerRegistry'
            );

            $trigger    = $this->parseString('trigger', 'lag');
            $matchAny   = Erebot_Utils::getVStatic($registry, 'MATCH_ANY');

            $this->_trigger = $registry->registerTriggers(
                $trigger,
                $matchAny
            );
            if ($this->_trigger === NULL) {
                $fmt = $this->getFormatter(FALSE);
                throw new Exception(
                    $fmt->_(
                        'Unable to register trigger for Lag Checker'
                    )
                );
            }

            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleGetLag')),
                new Erebot_Event_Match_All(
                    new Erebot_Event_Match_InstanceOf(
                        'Erebot_Interface_Event_Base_TextMessage'
                    ),
                    new Erebot_Event_Match_TextStatic($trigger, TRUE)
                )
            );
            $this->_connection->addEventHandler($handler);

            $cls = $this->getFactory('!Callable');
            $this->registerHelpMethod(new $cls(array($this, 'getHelp')));
        }
    }

    /**
     * \copydoc Erebot_Module_Base::_unload()
     *
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function _unload()
    {
        $timers =   array('_timerPing', '_timerPong', '_timerQuit');

        foreach ($timers as $timer) {
            try {
                if (isset($this->$timer))
                    $this->removeTimer($this->$timer);
            }
            catch (Erebot_ExceptionNotFound $e) {
            }
            unset($this->$timer);
            $this->$timer = NULL;
        }
    }

    /**
     * Provides help about this module.
     *
     * \param Erebot_Interface_Event_Base_TextMessage $event
     *      Some help request.
     *
     * \param Erebot_Interface_TextWrapper $words
     *      Parameters passed with the request. This is the same
     *      as this module's name when help is requested on the
     *      module itself (in opposition with help on a specific
     *      command provided by the module).
     */
    public function getHelp(
        Erebot_Interface_Event_Base_TextMessage $event,
        Erebot_Interface_TextWrapper            $words
    )
    {
        if ($event instanceof Erebot_Interface_Event_Base_Private) {
            $target = $event->getSource();
            $chan   = NULL;
        }
        else
            $target = $chan = $event->getChan();

        $fmt        = $this->getFormatter($chan);
        $trigger    = $this->parseString('trigger', 'lag');

        $moduleName = strtolower(get_class());
        $nbArgs     = count($words);

        if ($nbArgs == 1 && $words[0] == $moduleName) {
            $msg = $fmt->_(
                'Provides the <b><var name="trigger"/></b> command which '.
                'prints the current lag.',
                array('trigger' => $trigger)
            );
            $this->sendMessage($target, $msg);
            return TRUE;
        }

        if ($nbArgs < 2)
            return FALSE;

        if ($words[1] == $trigger) {
            $msg = $fmt->_(
                "<b>Usage:</b> !<var name='trigger'/>. Display the latency ".
                "of the connection, that is, the number of seconds it takes ".
                "for a message from the bot to go to the IRC server and back.",
                array('trigger' => $trigger)
            );
            $this->sendMessage($target, $msg);
            return TRUE;
        }
    }

    /**
     * Handles a request to check current lag.
     *
     * \param Erebot_Interface_Timer $timer
     *      Timer that triggered this method.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function checkLag(Erebot_Interface_Timer $timer)
    {
        $timerCls = $this->getFactory('!Timer');
        $this->_timerPong = new $timerCls(
            new Erebot_Callable(array($this, 'disconnect')),
            $this->_delayPong,
            FALSE
        );
        $this->addTimer($this->_timerPong);

        $this->_lastSent    = microtime(TRUE);
        $this->_lastRcvd    = NULL;
        $this->sendCommand('PING '.$this->_lastSent);
    }

    /**
     * Handles the response to a lag check
     * sent by the bot.
     *
     * \param Erebot_Interface_EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Pong $event
     *      Response to a lag check.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handlePong(
        Erebot_Interface_EventHandler   $handler,
        Erebot_Interface_Event_Pong     $event
    )
    {
        if ($event->getText() != ((string) $this->_lastSent))
            return;

        $this->_lastRcvd = microtime(TRUE);
        $this->removeTimer($this->_timerPong);
        unset($this->_timerPong);
        $this->_timerPong = NULL;
    }

    /**
     * Handles the bot exiting.
     *
     * \param Erebot_Interface_EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Exit $event
     *      Exit signal.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleExit(
        Erebot_Interface_EventHandler   $handler,
        Erebot_Interface_Event_Exit     $event
    )
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

    /**
     * Handles a request to disconnect the bot
     * after a very high lag has been detected.
     *
     * \param Erebot_Interface_Timer $timer
     *      Timer that triggered this method.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function disconnect(Erebot_Interface_Timer $timer)
    {
        $this->_connection->disconnect();

        $config = $this->_connection->getConfig(NULL);
        $uris   = $config->getConnectionURI();
        $uri    = new Erebot_URI($uris[count($uris) - 1]);
        $logger = Plop::getInstance();
        $fmt    = $this->getFormatter(FALSE);
        $cls    = $this->getFactory('!Styling_Duration');

        $logger->info(
            $fmt->_(
                'Lag got too high for "<var name="server"/>" ... '.
                'reconnecting in <var name="delay"/>',
                array(
                    'server'    => $uri->getHost(),
                    'delay'     => new $cls($this->_delayReco),
                )
            )
        );

        $timerCls = $this->getFactory('!Timer');
        $this->_timerQuit = new $timerCls(
            new Erebot_Callable(array($this, 'reconnect')),
            $this->_delayReco,
            TRUE
        );
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

    /**
     * Handles a request to reconnect after
     * a very high lag was detected and the
     * bot was disconnected.
     *
     * \param Erebot_Interface_Timer $timer
     *      Timer that triggered this method.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function reconnect(Erebot_Interface_Timer $timer)
    {
        $config = $this->_connection->getConfig(NULL);
        $uris   = $config->getConnectionURI();
        $uri    = new Erebot_URI($uris[count($uris) - 1]);
        $logger = Plop::getInstance();
        $fmt    = $this->getFormatter(FALSE);

        $logger->info(
            $fmt->_(
                'Attempting reconnection to "<var name="server"/>"',
                array('server' => $uri->getHost())
            )
        );

        try {
            $this->_connection->connect();
            $bot = $this->_connection->getBot();
            $bot->addConnection($this->_connection);

            if ($this->_timerQuit !== NULL) {
                $this->removeTimer($this->_timerQuit);
                unset($this->_timerQuit);
                $this->_timerQuit = NULL;
            }
        }
        catch (Erebot_ExceptionConnectionFailure $e) {
        }
    }

    /**
     * Returns the current lag.
     *
     * \retval mixed
     *      Either NULL if the lag has not been
     *      computed yet or a floating point value
     *      with the current lag.
     */
    public function getLag()
    {
        if ($this->_lastRcvd === NULL)
            return NULL;
        return ($this->_lastRcvd - $this->_lastSent);
    }

    /**
     * Handles a request to get the current lag.
     *
     * \param Erebot_Interface_EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Base_TextMessage $event
     *      A request to get the current lag.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleGetLag(
        Erebot_Interface_EventHandler           $handler,
        Erebot_Interface_Event_Base_TextMessage $event
    )
    {
        if ($event instanceof Erebot_Interface_Event_Base_Private) {
            $target = $event->getSource();
            $chan   = NULL;
        }
        else
            $target = $chan = $event->getChan();

        $lag    = $this->getLag();
        $fmt    = $this->getFormatter($chan);

        if ($lag === NULL)
            $this->sendMessage(
                $target,
                $fmt->_('No lag measure has been done yet')
            );
        else {
            $msg = $fmt->_(
                'Current lag: <var name="lag"/> seconds',
                array('lag' => $lag)
            );
            $this->sendMessage($target, $msg);
        }
    }

    /**
     * Handles connections to an IRC server.
     * This method is called after the logon phase,
     * when the bot has already sent its credentials.
     * It starts the lag detection process.
     *
     * \param Erebot_Interface_EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Connect $event
     *      Connection event.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function handleConnect(
        Erebot_Interface_EventHandler   $handler,
        Erebot_Interface_Event_Connect  $event
    )
    {
        $timerCls = $this->getFactory('!Timer');
        $this->_timerPing = new $timerCls(
            new Erebot_Callable(array($this, 'checkLag')),
            $this->_delayPing,
            TRUE
        );
        $this->addTimer($this->_timerPing);

        if ($this->_trigger !== NULL) {
            try {
                $registry = $this->_connection->getModule(
                    'Erebot_Module_TriggerRegistry'
                );
                $registry->freeTriggers($this->_trigger);
            }
            catch (Erebot_Exception $e) {
            }
        }
    }
}

