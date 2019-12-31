<?php

/**
 * @see       https://github.com/mezzio/mezzio-session for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-session/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-session/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace MezzioTest\Session\LazySessionTest;

use Mezzio\Session\LazySession;
use Mezzio\Session\SessionCookiePersistenceInterface;
use Mezzio\Session\SessionIdentifierAwareInterface;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionPersistenceInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Psr\Http\Message\ServerRequestInterface;

class LazySessionTest extends TestCase
{
    public function setUp()
    {
        $this->proxy = $this->prophesize(SessionInterface::class);
        $this->persistence = $this->prophesize(SessionPersistenceInterface::class);
        $this->request = $this->prophesize(ServerRequestInterface::class);
        $this->session = new LazySession($this->persistence->reveal(), $this->request->reveal());
    }

    /**
     * @param \Prophecy\ObjectProphecy|SessionPersistenceInterface $persistence
     * @param \Prophecy\ObjectProphecy|ServerRequestInterface $request
     */
    public function assertProxyCreated($persistence, $request)
    {
        $persistence
            ->initializeSessionFromRequest(Argument::that([$request, 'reveal']))
            ->will([$this->proxy, 'reveal']);
    }

    public function initializeProxy()
    {
        $this->proxy->has('foo')->willReturn(true);
        $this->session->has('foo');
    }

    public function testRegenerateWillReturnSameInstance()
    {
        $newSession = $this->prophesize(SessionInterface::class);
        $newSession->isRegenerated()->willReturn(true);

        $this->assertProxyCreated($this->persistence, $this->request);
        $this->proxy->regenerate()->will([$newSession, 'reveal']);

        $regeneratedSession = $this->session->regenerate();
        $this->assertSame($this->session, $regeneratedSession);
        $this->assertAttributeSame($newSession->reveal(), 'proxiedSession', $regeneratedSession);

        return $this->session;
    }

    /**
     * @depends testRegenerateWillReturnSameInstance
     */
    public function testIsRegeneratedReturnsTrueAfterSessionRegeneration(LazySession $session)
    {
        $this->assertTrue($session->isRegenerated());
    }

    public function testIsRegneratedReturnsFalseIfProxiedSessionIsNotRegenerated()
    {
        $this->assertProxyCreated($this->persistence, $this->request);
        $this->proxy->isRegenerated()->willReturn(false);
        $this->assertFalse($this->session->isRegenerated());
    }

    public function testToArrayProxiesToUnderlyingSession()
    {
        $expected = ['foo' => 'bar'];
        $this->assertProxyCreated($this->persistence, $this->request);
        $this->proxy->toArray()->willReturn($expected);
        $this->assertSame($expected, $this->session->toArray());
    }

    public function testGetProxiesToUnderlyingSession()
    {
        $expected = 'foo';
        $this->assertProxyCreated($this->persistence, $this->request);
        $this->proxy->get('test', 'bar')->willReturn($expected);
        $this->assertSame($expected, $this->session->get('test', 'bar'));
    }

    public function testHasProxiesToUnderlyingSession()
    {
        $this->assertProxyCreated($this->persistence, $this->request);
        $this->proxy->has('test')->willReturn(true);
        $this->assertTrue($this->session->has('test'));
    }

    public function testSetProxiesToUnderlyingSession()
    {
        $this->assertProxyCreated($this->persistence, $this->request);
        $this->proxy->set('test', 'bar')->shouldBeCalled();
        $this->assertNull($this->session->set('test', 'bar'));
    }

    public function testUnsetProxiesToUnderlyingSession()
    {
        $this->assertProxyCreated($this->persistence, $this->request);
        $this->proxy->unset('test')->shouldBeCalled();
        $this->assertNull($this->session->unset('test'));
    }

    public function testClearProxiesToUnderlyingSession()
    {
        $this->assertProxyCreated($this->persistence, $this->request);
        $this->proxy->clear()->shouldBeCalled();
        $this->assertNull($this->session->clear());
    }

    public function testHasChangedReturnsFalseIfProxyNotInitialized()
    {
        $this->proxy->hasChanged()->shouldNotBeCalled();
        $this->assertFalse($this->session->hasChanged());
    }

    public function testHasChangedReturnsFalseIfProxyInitializedAndDoesNotHaveChanges()
    {
        $this->assertProxyCreated($this->persistence, $this->request);
        $this->initializeProxy();
        $this->proxy->isRegenerated()->willReturn(false);
        $this->proxy->hasChanged()->willReturn(false);
        $this->assertFalse($this->session->hasChanged());
    }

    public function testHasChangedReturnsTrueIfProxyInitializedAndHasChanges()
    {
        $this->assertProxyCreated($this->persistence, $this->request);
        $this->initializeProxy();
        $this->proxy->isRegenerated()->willReturn(false);
        $this->proxy->hasChanged()->willReturn(true);
        $this->assertTrue($this->session->hasChanged());
    }

    public function testHasChangedReturnsTrueIfProxyHasBeenRegenerated()
    {
        $this->assertProxyCreated($this->persistence, $this->request);
        $this->initializeProxy();
        $this->proxy->isRegenerated()->willReturn(true);
        $this->proxy->hasChanged()->shouldNotBeCalled();
        $this->assertTrue($this->session->hasChanged());
    }

    public function testGetIdReturnsEmptyStringIfProxyDoesNotImplementIdentifierAwareInterface()
    {
        $this->assertProxyCreated($this->persistence, $this->request);
        $this->initializeProxy();
        $this->assertSame('', $this->session->getId());
    }

    public function testGetIdReturnsValueFromProxyIfItImplementsIdentiferAwareInterface()
    {
        $proxy = $this->prophesize(SessionInterface::class);
        $proxy->willImplement(SessionIdentifierAwareInterface::class);
        $this->persistence
            ->initializeSessionFromRequest(Argument::that([$this->request, 'reveal']))
            ->will([$proxy, 'reveal']);
        $proxy->getId()->willReturn('abcd1234');

        $session = new LazySession($this->persistence->reveal(), $this->request->reveal());

        $this->assertSame('abcd1234', $session->getId());
    }

    public function testPersistSessionForDoesNothingIfProxyDoesNotImplementSessionCookiePersistence()
    {
        $this->assertProxyCreated($this->persistence, $this->request);
        $this->initializeProxy();

        $session = new LazySession($this->persistence->reveal(), $this->request->reveal());

        $this->assertNull($session->persistSessionFor(60));
    }

    public function testPersistSessionForProxiesToUnderlyingSession()
    {
        $proxy = $this->prophesize(SessionInterface::class);
        $proxy->willImplement(SessionCookiePersistenceInterface::class);
        $this->persistence
            ->initializeSessionFromRequest(Argument::that([$this->request, 'reveal']))
            ->will([$proxy, 'reveal']);
        $proxy->persistSessionFor(60)->shouldBeCalled();

        $session = new LazySession($this->persistence->reveal(), $this->request->reveal());

        $this->assertNull($session->persistSessionFor(60));
    }

    public function testGetSessionLifetimeReturnsZeroIfProxyDoesNotImplementSessionCookiePersistence()
    {
        $this->assertProxyCreated($this->persistence, $this->request);
        $this->initializeProxy();

        $session = new LazySession($this->persistence->reveal(), $this->request->reveal());

        $this->assertSame(0, $session->getSessionLifetime());
    }

    public function testGetSessionLifetimeReturnsValueFromProxy()
    {
        $proxy = $this->prophesize(SessionInterface::class);
        $proxy->willImplement(SessionCookiePersistenceInterface::class);
        $this->persistence
            ->initializeSessionFromRequest(Argument::that([$this->request, 'reveal']))
            ->will([$proxy, 'reveal']);
        $proxy->getSessionLifetime()->willReturn(60);

        $session = new LazySession($this->persistence->reveal(), $this->request->reveal());

        $this->assertSame(60, $session->getSessionLifetime());
    }
}
