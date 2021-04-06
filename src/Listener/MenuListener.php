<?php

namespace BackBeeCloud\Listener;

use BackBee\BBApplication;
use BackBee\Event\Event;
use BackBee\NestedNode\Page;
use BackBee\Renderer\Event\RendererEvent;
use BackBee\Site\Site;
use BackBeeCloud\Entity\Lang;
use Doctrine\ORM\EntityManager;
use Exception;

/**
 * Class MenuListener
 *
 * @package BackBeeCloud\Listener
 *
 * @author  Florian Kroockmann <florian.kroockmann@lp-digital.fr>
 */
class MenuListener
{
    /**
     * @var BBApplication
     */
    private static $bbApp;

    /**
     * @var EntityManager
     */
    private static $entityManager;

    /**
     * MenuListener constructor.
     *
     * @param BBApplication $bbApp
     */
    public function __construct(BBApplication $bbApp)
    {
        self::$bbApp = $bbApp;
        self::$entityManager = $bbApp->getEntityManager();
    }

    /**
     * Occurs on `basic.menu.persist`
     *
     * @param Event $event
     */
    public static function onPrePersist(Event $event): void
    {
        $menu = $event->getTarget();
        $param = $menu->getParam('items');

        if (empty($param['value'])) {
            $homepage = self::$entityManager->getRepository(Page::class)->getRoot(
                self::$entityManager->getRepository(Site::class)->findOneBy([])
            );
            $menu->setParam(
                'items',
                [
                    [
                        'id' => $homepage->getUid(),
                        'url' => $homepage->getUrl(),
                        'label' => $homepage->getTitle(),
                    ],
                ]
            );
        }
    }

    /**
     * Called on `basic.menu.render` event.
     *
     * @param RendererEvent $event
     */
    public static function onRender(RendererEvent $event): void
    {
        $renderer = $event->getRenderer();
        $block = $event->getTarget();
        $items = $block->getParamValue('items');
        $validItems = [];

        try {
            foreach ($items as $item) {
                if (false !== $item['id'] && null !== ($page = self::getPageByUid($item['id']))) {
                    $item['url'] = $page->getUrl();
                    $item['label'] = $page->getTitle();
                    $item['is_online'] = $page->isOnline();
                    $item['is_current'] = $renderer->getCurrentPage() === $page;

                    $validChildren = [];
                    if (isset($item['children'])) {
                        foreach ((array)$item['children'] as $child) {
                            if (null !== ($page = self::getPageByUid($child['id'], self::$bbApp))) {
                                $child['url'] = $page->getUrl();
                                $child['label'] = $page->getTitle();
                                $child['is_online'] = $page->isOnline();
                                $child['is_current'] = $renderer->getCurrentPage() === $page;
                                $validChildren[] = $child;
                            }
                        }
                    }

                    $item['children'] = $validChildren;
                    $validItems[] = $item;
                }
            }

            if (null !== self::$bbApp->getBBUserToken()) {
                $block->setParam('items', $validItems);
                self::$entityManager->flush($block->getDraft() ?: $block);
            }

            $renderer->assign('items', $validItems);
        } catch (Exception $exception) {
            self::$bbApp->getLogging()->error(
                sprintf('%s : %s : %s', __CLASS__, __FUNCTION__, $exception->getMessage())
            );
        }
    }

    /**
     * Get page by uid.
     *
     * @param string|null $uid
     *
     * @return Page|null
     */
    private static function getPageByUid(?string $uid = null): ?Page
    {
        $page = null;
        $multiLangMgr = self::$bbApp->getContainer()->get('multilang_manager');

        try {
            if (null !== ($page = self::$entityManager->find(Page::class, $uid))) {
                if (null === self::$bbApp->getBBUserToken() && !$page->isOnline()) {
                    return null;
                }

                if ($page->isRoot() &&
                    null !== ($currentLang = $multiLangMgr->getCurrentLang()) &&
                    null !== ($lang = self::$entityManager->find(Lang::class, $currentLang))
                ) {
                    $page = $multiLangMgr->getRootByLang($lang);
                }
            }
        } catch (Exception $exception) {
            self::$bbApp->getLogging()->error(
                sprintf('%s : %s : %s', __CLASS__, __FUNCTION__, $exception->getMessage())
            );
        }

        return $page;
    }
}
