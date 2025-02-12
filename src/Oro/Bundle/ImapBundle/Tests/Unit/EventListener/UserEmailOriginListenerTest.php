<?php

namespace Oro\Bundle\ImapBundle\Tests\Unit\EventListener;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\EmailBundle\Entity\EmailFolder;
use Oro\Bundle\ImapBundle\Entity\ImapEmailFolder;
use Oro\Bundle\ImapBundle\Entity\UserEmailOrigin;
use Oro\Bundle\ImapBundle\EventListener\UserEmailOriginListener;
use Oro\Component\Testing\Unit\EntityTrait;

class UserEmailOriginListenerTest extends \PHPUnit\Framework\TestCase
{
    use EntityTrait;

    /** @var EntityManager|\PHPUnit\Framework\MockObject\MockObject */
    private $em;

    /** UserEmailOriginListener */
    private $listener;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManager::class);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects($this->any())
            ->method('getManager')
            ->willReturn($this->em);

        $this->listener = new UserEmailOriginListener($doctrine);
    }

    public function testPrePersistOnEmptyOriginFolders(): void
    {
        $origin = new UserEmailOrigin();

        $this->em->expects(self::never())
            ->method('persist');

        $this->listener->prePersist($origin, $this->createMock(LifecycleEventArgs::class));
    }

    public function testPrePersistWithAlreadySavedFolders(): void
    {
        $folder1 = $this->getEntity(EmailFolder::class, ['id' => 1]);
        $folder2 = $this->getEntity(EmailFolder::class, ['id' => 2]);
        $origin = new UserEmailOrigin();
        $origin->addFolder($folder1);
        $origin->addFolder($folder2);

        $this->em->expects(self::never())
            ->method('persist');

        $this->listener->prePersist($origin, $this->createMock(LifecycleEventArgs::class));
    }

    public function testPrePersistWithNewFolders(): void
    {
        $folder1 = $this->getEntity(EmailFolder::class, ['id' => 1]);
        $folder2 = new EmailFolder();
        $origin = new UserEmailOrigin();
        $origin->addFolder($folder1);
        $origin->addFolder($folder2);

        $expectedImapEmailFolder = new ImapEmailFolder();
        $expectedImapEmailFolder->setUidValidity(0);
        $expectedImapEmailFolder->setFolder($folder2);

        $this->em->expects(self::once())
            ->method('persist')
            ->with($expectedImapEmailFolder);

        $this->listener->prePersist($origin, $this->createMock(LifecycleEventArgs::class));
    }
}
