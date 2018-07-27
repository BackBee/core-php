<?php

namespace BackBeeCloud\Listener;

use BackBeeCloud\ThemeColor\ColorPanelCssGenerator;
use BackBee\Renderer\Event\RendererEvent;
use BackBee\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;

/**
 * @author Sachan Nilleti <sachan.nilleti@lp-digital.fr>
 */
class ThemeColorListener
{
    /**
     * @var ColorPanelCssGenerator
     */
    protected $cssGenerator;

    /**
     * @var RouteCollection
     */
    protected $routing;

    public function __construct(ColorPanelCssGenerator $cssGenerator, RouteCollection $routing)
    {
        $this->cssGenerator = $cssGenerator;
        $this->routing = $routing;
    }

    public function onKernelResponse(FilterResponseEvent $event)
    {
        $response = $event->getResponse();
        if ($response instanceof JsonResponse) {
            return;
        }

        $response->setContent(str_replace(
            '</head>',
            sprintf(
                '<link rel="stylesheet" href="%s">',
                $this->routing->getUrlByRouteName('api.color_panel.get_color_panel_css', [
                    'hash' => $this->cssGenerator->getCurrentHash(),
                ])
            ),
            $response->getContent()
        ));
    }
}
