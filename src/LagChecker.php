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

namespace Erebot\Module;

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
class LagChecker extends \Erebot\Module\Base implements \Erebot\Interfaces\HelpEnabled
{
    /// Timer for lag checks.
    protected $timerPing;

    /// Timer defining the timeout for lag responses.
    protected $timerPong;

    /// Timer used to reconnect the bot after a disconnection due to latency.
    protected $timerQuit;

    /// Delay between lag checks.
    protected $delayPing;

    /// Timeout for lag responses.
    protected $delayPong;

    /// Delay before the bot reconnects after a disconnection due to latency.
    protected $delayReco;

    /// Timestamp of the last lag check sent.
    protected $lastSent;

    /// Timestamp of the last lag response received.
    protected $lastRcvd;

    /// Trigger registered by this module.
    protected $trigger;

    /**
     * This method is called whenever the module is (re)loaded.
     *
     * \param int $flags
     *      A bitwise OR of the Erebot::Module::Base::RELOAD_*
     *      constants. Your method should take proper actions
     *      depending on the value of those flags.
     *
     * \note
     *      See the documentation on individual RELOAD_*
     *      constants for a list of possible values.
     *
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function reload($flags)
    {
        if ($flags & self::RELOAD_MEMBERS) {
            if (!($flags & self::RELOAD_INIT)) {
                $timers =   array('timerPing', 'timerPong', 'timerQuit');

                foreach ($timers as $timer) {
                    try {
                        $this->removeTimer($this->$timer);
                    } catch (\Erebot\ExceptionNotFound $e) {
                    }
                    unset($this->$timer);
                    $this->$timer = null;
                }

                $this->trigger = null;
            }

            $this->delayPing    = $this->parseInt('check');
            $this->delayPong    = $this->parseInt('timeout');
            $this->delayReco    = $this->parseInt('reconnect');

            $this->timerPing    = null;
            $this->timerPong    = null;
            $this->timerQuit    = null;

            $this->lastRcvd     = null;
            $this->lastSent     = null;
        }

        if ($flags & self::RELOAD_HANDLERS) {
            $handlers = array(
                'handlePong'        => '\\Erebot\\Interfaces\\Event\\Pong',
                'handleExit'        => '\\Erebot\\Interfaces\\Event\\ExitEvent',
                'handleConnect'     => '\\Erebot\\Interfaces\\Event\\Connect',
            );

            foreach ($handlers as $callback => $eventType) {
                $handler = new \Erebot\EventHandler(
                    \Erebot\CallableWrapper::wrap(array($this, $callback)),
                    new \Erebot\Event\Match\Type($eventType)
                );
                $this->connection->addEventHandler($handler);
            }

            $registry   =   $this->connection->getModule(
                '\\Erebot\\Module\\TriggerRegistry'
            );

            $trigger    = $this->parseString('trigger', 'lag');

            $this->trigger = $registry->registerTriggers(
                $trigger,
                $registry::MATCH_ANY
            );
            if ($this->trigger === null) {
                $fmt = $this->getFormatter(false);
                throw new Exception(
                    $fmt->_(
                        'Unable to register trigger for Lag Checker'
                    )
                );
            }

            $handler = new \Erebot\EventHandler(
                \Erebot\CallableWrapper::wrap(array($this, 'handleGetLag')),
                new \Erebot\Event\Match\All(
                    new \Erebot\Event\Match\Type(
                        '\\Erebot\\Interfaces\\Event\\Base\\TextMessage'
                    ),
                    new \Erebot\Event\Match\TextStatic($trigger, true)
                )
            );
            $this->connection->addEventHandler($handler);
        }
    }

    /**
     * \copydoc Erebot::Module::Base::unload()
     *
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function unload()
    {
        foreach (array('timerPing', 'timerPong', 'timerQuit') as $timer) {
            try {
                if (isset($this->$timer)) {
                    $this->removeTimer($this->$timer);
                }
            } catch (\Erebot\ExceptionNotFound $e) {
            }
            unset($this->$timer);
            $this->$timer = null;
        }
    }

    /**
     * Provides help about this module.
     *
     * \param Erebot::Interfaces::Event::Base::TextMessage $event
     *      Some help request.
     *
     * \param Erebot::Interfaces::TextWrapper $words
     *      Parameters passed with the request. This is the same
     *      as this module's name when help is requested on the
     *      module itself (in opposition with help on a specific
     *      command provided by the module).
     */
    public function getHelp(
        \Erebot\Interfaces\Event\Base\TextMessage   $event,
        \Erebot\Interfaces\TextWrapper              $words
    ) {
        if ($event instanceof \Erebot\Interfaces\Event\Base\PrivateMessage) {
            $target = $event->getSource();
            $chan   = null;
        } else {
            $target = $chan = $event->getChan();
        }

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
            return true;
        }

        if ($nbArgs < 2) {
            return false;
        }

        if ($words[1] == $trigger) {
            $msg = $fmt->_(
                "<b>Usage:</b> !<var name='trigger'/>. Display the latency ".
                "of the connection, that is, the number of seconds it takes ".
                "for a message from the bot to go to the IRC server and back.",
                array('trigger' => $trigger)
            );
            $this->sendMessage($target, $msg);
            return true;
        }
    }

    /**
     * Handles a request to check current lag.
     *
     * \param Erebot::TimerInterface $timer
     *      Timer that triggered this method.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function checkLag(\Erebot\TimerInterface $timer)
    {
        $timerCls = $this->getFactory('!Timer');
        $this->timerPong = new $timerCls(
            \Erebot\CallableWrapper::wrap(array($this, 'disconnect')),
            $this->delayPong,
            false
        );
        $this->addTimer($this->timerPong);

        $this->lastSent = microtime(true);
        $this->lastRcvd = null;
        $this->sendCommand('PING '.$this->lastSent);
    }

    /**
     * Handles the response to a lag check
     * sent by the bot.
     *
     * \param Erebot::Interfaces::EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot::Interfaces::Event::Pong $event
     *      Response to a lag check.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handlePong(
        \Erebot\Interfaces\EventHandler $handler,
        \Erebot\Interfaces\Event\Pong   $event
    ) {
        if ($event->getText() != ((string) $this->lastSent)) {
            return;
        }

        $this->lastRcvd = microtime(true);
        $this->removeTimer($this->timerPong);
        unset($this->timerPong);
        $this->timerPong = null;
    }

    /**
     * Handles the bot exiting.
     *
     * \param Erebot::Interfaces::EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot::Interfaces::Event::ExitEvent $event
     *      Exit signal.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleExit(
        \Erebot\Interfaces\EventHandler     $handler,
        \Erebot\Interfaces\Event\ExitEvent  $event
    ) {
        if ($this->timerPing) {
            $this->removeTimer($this->timerPing);
            unset($this->timerPing);
        }

        if ($this->timerPong) {
            $this->removeTimer($this->timerPong);
            unset($this->timerPong);
        }
    }

    /**
     * Handles a request to disconnect the bot
     * after a very high lag has been detected.
     *
     * \param Erebot::TimerInterface $timer
     *      Timer that triggered this method.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function disconnect(\Erebot\TimerInterface $timer)
    {
        $this->connection->disconnect();

        $config = $this->connection->getConfig(null);
        $uris   = $config->getConnectionURI();
        $uri    = new \Erebot\URI($uris[count($uris) - 1]);
        $logger = \Plop\Plop::getInstance();
        $fmt    = $this->getFormatter(false);
        $cls    = $this->getFactory('!Styling\\Variables\\Duration');

        $logger->info(
            $fmt->_(
                'Lag got too high for "<var name="server"/>" ... '.
                'reconnecting in <var name="delay"/>',
                array(
                    'server'    => $uri->getHost(),
                    'delay'     => new $cls($this->delayReco),
                )
            )
        );

        $timerCls = $this->getFactory('!Timer');
        $this->timerQuit = new $timerCls(
            \Erebot\CallableWrapper::wrap(array($this, 'reconnect')),
            $this->delayReco,
            true
        );
        $this->addTimer($this->timerQuit);

        try {
            if ($this->timerPing !== null) {
                $this->removeTimer($this->timerPing);
            }
        } catch (\Erebot\Exception $e) {
        }

        try {
            if ($this->timerPong !== null) {
                $this->removeTimer($this->timerPong);
            }
        } catch (\Erebot\Exception $e) {
        }

        unset($this->timerPing, $this->timerPong);
        $this->timerPing    = null;
        $this->timerPong    = null;
    }

    /**
     * Handles a request to reconnect after
     * a very high lag was detected and the
     * bot was disconnected.
     *
     * \param Erebot::TimerInterface $timer
     *      Timer that triggered this method.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function reconnect(\Erebot\TimerInterface $timer)
    {
        $config = $this->connection->getConfig(null);
        $uris   = $config->getConnectionURI();
        $uri    = new \Erebot\URI($uris[count($uris) - 1]);
        $logger = \Plop\Plop::getInstance();
        $fmt    = $this->getFormatter(false);

        $logger->info(
            $fmt->_(
                'Attempting reconnection to "<var name="server"/>"',
                array('server' => $uri->getHost())
            )
        );

        try {
            $this->connection->connect();
            $bot = $this->connection->getBot();
            $bot->addConnection($this->connection);

            if ($this->timerQuit !== null) {
                $this->removeTimer($this->timerQuit);
                unset($this->timerQuit);
                $this->timerQuit = null;
            }
        } catch (\Erebot\ConnectionFailureException $e) {
        }
    }

    /**
     * Returns the current lag.
     *
     * \retval mixed
     *      Either \b null if the lag has not been
     *      computed yet or a floating point value
     *      with the current lag.
     */
    public function getLag()
    {
        if ($this->lastRcvd === null) {
            return null;
        }
        return ($this->lastRcvd - $this->lastSent);
    }

    /**
     * Handles a request to get the current lag.
     *
     * \param Erebot::Interfaces::EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot::Interfaces::Event::Base::TextMessage $event
     *      A request to get the current lag.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleGetLag(
        \Erebot\Interfaces\EventHandler             $handler,
        \Erebot\Interfaces\Event\Base\TextMessage   $event
    ) {
        if ($event instanceof \Erebot\Interfaces\Event\Base\PrivateMessage) {
            $target = $event->getSource();
            $chan   = null;
        } else {
            $target = $chan = $event->getChan();
        }

        $lag    = $this->getLag();
        $fmt    = $this->getFormatter($chan);

        if ($lag === null) {
            $this->sendMessage(
                $target,
                $fmt->_('No lag measure has been done yet')
            );
        } else {
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
     * \param Erebot::Interfaces::EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot::Interfaces::Event::Connect $event
     *      Connection event.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function handleConnect(
        \Erebot\Interfaces\EventHandler   $handler,
        \Erebot\Interfaces\Event\Connect  $event
    ) {
        $timerCls = $this->getFactory('!Timer');
        $this->timerPing = new $timerCls(
            \Erebot\CallableWrapper::wrap(array($this, 'checkLag')),
            $this->delayPing,
            true
        );
        $this->addTimer($this->timerPing);

        if ($this->trigger !== null) {
            try {
                $registry = $this->connection->getModule(
                    '\\Erebot\\Module\\TriggerRegistry'
                );
                $registry->freeTriggers($this->trigger);
            } catch (\Erebot\Exception $e) {
            }
        }
    }
}
