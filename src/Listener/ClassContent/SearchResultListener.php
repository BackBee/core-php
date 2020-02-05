<?php

namespace BackBeeCloud\Listener\ClassContent;

use BackBee\Renderer\Event\RendererEvent;
use BackBeeCloud\Elasticsearch\SearchEvent;
use BackBeeCloud\Search\ResultItemHtmlFormatter;

/**
 * @author Eric Chau <eric.chau@lp-digital.fr>
 * @author Djoudi Bensid <djoudi.bensid@lp-digital.fr>
 */
class SearchResultListener
{
    const SEARCHRESULT_PRE_SEARCH_EVENT = 'content.searchresult.presearch';
    const RESULT_PER_PAGE = 10;

    public static function onRender(RendererEvent $event)
    {
        $app = $event->getApplication();
        $request = $app->getRequest();

        $query = $request->query->get('q', '');
        $page = $request->query->getInt('page', 1);

        $contents = [];
        $pages = [];
        $esQuery = [];
        if (is_string($query) && 0 < strlen(trim($query))) {
            $esQuery = [
                'query' => [
                    'bool' => [
                        'should' => [
                            [ 'match' => ['title' => ['query' => $query, 'boost' => 2] ] ],
                            [ 'match' => ['title.raw' => ['query' => $query, 'boost' => 2] ] ],
                            [ 'match' => ['title.folded' => ['query' => $query, 'boost' => 2] ] ],
                            [ 'match' => ['tags' => $query] ],
                            [ 'match' => ['tags.raw' => $query] ],
                            [ 'match' => ['tags.folded' => $query] ],
                            [ 'match' => ['contents' => $query] ],
                            [ 'match' => ['contents.folded' => $query] ],
                            [ 'match_phrase_prefix' => ['title' => ['query' => $query, 'boost' => 2] ] ],
                            //[ 'match_phrase_prefix' => ['title.raw' => ['query' => $query, 'boost' => 2] ] ],
                            [ 'match_phrase_prefix' => ['title.folded' => ['query' => $query, 'boost' => 2] ] ],
                            [ 'match_phrase_prefix' => ['tags' => $query] ],
                        ],
                        'minimum_should_match' => 1,
                    ],
                ],
            ];
        }

        $searchEvent = new SearchEvent($esQuery);
        $app->getEventDispatcher()->dispatch(self::SEARCHRESULT_PRE_SEARCH_EVENT, $searchEvent);
        $esQuery = $searchEvent->getQueryBody()->getArrayCopy();

        if (!isset($esQuery['query']['bool'])) {
            $esQuery['query']['bool'] = [];
        }

        if (!isset($esQuery['query']['bool']['must'])) {
            $esQuery['query']['bool']['must'] = [];
        }

        //$esQuery['query']['bool']['must'][] = [ 'match' => ['is_pullable' => true] ];
        if (null === $app->getBBUserToken()) {
            $esQuery['query']['bool']['must'][] = [ 'match' => ['is_online' => true] ];
        }

        if (null !== $currentLang = $app->getContainer()->get('multilang_manager')->getCurrentLang()) {
            $esQuery['query']['bool']['must'][]['prefix'] = [
                'url' => sprintf('/%s/', $currentLang),
            ];
        }

        $sort = [];
        if (false == $searchEvent->getOrderBy()) {
            $sort[] = "published_at:desc";
        }

        $size = $searchEvent->getSize() ?: self::RESULT_PER_PAGE;
        $pages = $app->getContainer()->get('elasticsearch.manager')->customSearchPage(
            $esQuery,
            ($page > 0 ? $page - 1 : 0) * $size,
            $size,
            $sort,
            false
        );
        $formatter = new ResultItemHtmlFormatter(
            $app->getEntityManager(),
            $app->getRenderer(),
            $app->getBBUserToken()
        );

        $extraParams = [
            'show_image' => $event->getTarget()->getParamValue('show_image'),
            'show_abstract' => $event->getTarget()->getParamValue('show_abstract'),
            'show_published_at' => $event->getTarget()->getParamValue('show_published_at'),
        ];

        foreach ($pages->collection() as $page) {
            $contents[] = $formatter->renderItemFromRawData($page, $extraParams);
        }

        $renderer = $event->getRenderer();
        $renderer->assign('query', $query);
        $renderer->assign('pages', $pages);
        $renderer->assign('contents', $contents);
    }
}
