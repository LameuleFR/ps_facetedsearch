<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace PrestaShop\Module\FacetedSearch\Product;

use Configuration;
use PrestaShop\Module\FacetedSearch\Filters;
use PrestaShop\Module\FacetedSearch\URLSerializer;
use PrestaShop\PrestaShop\Core\Product\Search\Facet;
use PrestaShop\PrestaShop\Core\Product\Search\FacetCollection;
use PrestaShop\PrestaShop\Core\Product\Search\FacetsRendererInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchProviderInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchResult;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;
use Ps_Facetedsearch;
use Tools;

class SearchProvider implements FacetsRendererInterface, ProductSearchProviderInterface
{
    /**
     * @var Ps_Facetedsearch
     */
    private $module;

    /**
     * @var Filters\Converter
     */
    private $filtersConverter;

    /**
     * @var Filters\DataAccessor
     */
    private $dataAccessor;

    /**
     * @var URLSerializer
     */
    private $urlSerializer;

    /**
     * @var SearchFactory
     */
    private $searchFactory;

    /**
     * @var Filters\Provider
     */
    private $provider;

    public function __construct(
        Ps_Facetedsearch $module,
        Filters\Converter $converter,
        URLSerializer $serializer,
        Filters\DataAccessor $dataAccessor,
        SearchFactory $searchFactory = null,
        Filters\Provider $provider
    ) {
        $this->module = $module;
        $this->filtersConverter = $converter;
        $this->urlSerializer = $serializer;
        $this->dataAccessor = $dataAccessor;
        $this->searchFactory = $searchFactory === null ? new SearchFactory() : $searchFactory;
        $this->provider = $provider;
    }

    /**
     * @param ProductSearchQuery $query
     *
     * @return array
     */
    private function getAvailableSortOrders($query)
    {
        $sortSalesDesc = new SortOrder('product', 'sales', 'desc');
        $sortPosAsc = new SortOrder('product', 'position', 'asc');
        $sortNameAsc = new SortOrder('product', 'name', 'asc');
        $sortNameDesc = new SortOrder('product', 'name', 'desc');
        $sortPriceAsc = new SortOrder('product', 'price', 'asc');
        $sortPriceDesc = new SortOrder('product', 'price', 'desc');
        $sortDateAsc = new SortOrder('product', 'date_add', 'asc');
        $sortDateDesc = new SortOrder('product', 'date_add', 'desc');
        $sortRefAsc = new SortOrder('product', 'reference', 'asc');
        $sortRefDesc = new SortOrder('product', 'reference', 'desc');
        $translator = $this->module->getTranslator();

        $sortOrders = [
            $sortSalesDesc->setLabel(
                $translator->trans('Sales, highest to lowest', [], 'Shop.Theme.Catalog')
            ),
            $sortPosAsc->setLabel(
                $translator->trans('Relevance', [], 'Shop.Theme.Catalog')
            ),
            $sortNameAsc->setLabel(
                $translator->trans('Name, A to Z', [], 'Shop.Theme.Catalog')
            ),
            $sortNameDesc->setLabel(
                $translator->trans('Name, Z to A', [], 'Shop.Theme.Catalog')
            ),
            $sortPriceAsc->setLabel(
                $translator->trans('Price, low to high', [], 'Shop.Theme.Catalog')
            ),
            $sortPriceDesc->setLabel(
                $translator->trans('Price, high to low', [], 'Shop.Theme.Catalog')
            ),
            $sortRefAsc->setLabel(
                $translator->trans('Reference, A to Z', [], 'Shop.Theme.Catalog')
            ),
            $sortRefDesc->setLabel(
                $translator->trans('Reference, Z to A', [], 'Shop.Theme.Catalog')
            ),
        ];

        if ($query->getQueryType() == 'new-products') {
            $sortOrders[] = $sortDateAsc->setLabel(
                $translator->trans('Date added, oldest to newest', [], 'Shop.Theme.Catalog')
            );
            $sortOrders[] = $sortDateDesc->setLabel(
                $translator->trans('Date added, newest to oldest', [], 'Shop.Theme.Catalog')
            );
        }

        return $sortOrders;
    }

    /**
     * Instance of this class was previously passed to frontend controller, so we are now
     * ready to accept runQuery requests. The query object contains all the important information
     * about what we should get.
     *
     * @param ProductSearchContext $context
     * @param ProductSearchQuery $query
     *
     * @return ProductSearchResult
     */
    public function runQuery(
        ProductSearchContext $context,
        ProductSearchQuery $query
    ) {
        $result = new ProductSearchResult();

        /**
         * Get currently selected filters. In the query, it's passed as encoded URL string,
         * we make it an array. All filters in the URL that are no longer valid are removed.
         */
        $facetedSearchFilters = $this->filtersConverter->createFacetedSearchFiltersFromQuery($query);

        // Initialize the search mechanism
        $context = $this->module->getContext();
        $facetedSearch = $this->searchFactory->build($context);

        // Add query information into Search
        $facetedSearch->setQuery($query);

        // Init the search with the initial population associated with the current filters
        $facetedSearch->initSearch($facetedSearchFilters);

        // Request combination IDs if we have some attributes to search by.
        // If not, we won't use this to let the core select the default combination.
        if ($this->shouldPassCombinationIds($facetedSearchFilters)) {
            $facetedSearch->getSearchAdapter()->getInitialPopulation()->addSelectField('id_product_attribute');
            $facetedSearch->getSearchAdapter()->addSelectField('id_product_attribute');
        }

        // Load the product searcher, it gets the Adapter through Search object
        $filterProductSearch = new Filters\Products($facetedSearch);

        // Get the product associated with the current filter
        $productsAndCount = $filterProductSearch->getProductByFilters(
            $query,
            $facetedSearchFilters
        );

        $result
            ->setProducts($productsAndCount['products'])
            ->setTotalProductsCount($productsAndCount['count'])
            ->setAvailableSortOrders($this->getAvailableSortOrders($query));

        // Now let's get the filter blocks associated with the current search.
        // This will allow user to further filter this list we found.
        $filterBlockSearch = new Filters\Block(
            $facetedSearch->getSearchAdapter(),
            $context,
            $this->module->getDatabase(),
            $this->dataAccessor,
            $query,
            $this->provider
        );

        // Let's try to get filters from cache, if the controller is supported
        $filterHash = $this->generateCacheKeyForQuery($query, $facetedSearchFilters);
        if ($this->module->shouldCacheController($query->getQueryType())) {
            $filterBlock = $filterBlockSearch->getFromCache($filterHash);
        }

        // If not, we regenerate it and cache it
        if (empty($filterBlock)) {
            $filterBlock = $filterBlockSearch->getFilterBlock($productsAndCount['count'], $facetedSearchFilters);
            if ($this->module->shouldCacheController($query->getQueryType())) {
                $filterBlockSearch->insertIntoCache($filterHash, $filterBlock);
            }
        }

        $facets = $this->filtersConverter->getFacetsFromFilterBlocks(
            $filterBlock['filters']
        );

        $this->labelRangeFilters($facets);
        $this->addEncodedFacetsToFilters($facets);
        $this->hideUselessFacets($facets, (int) $result->getTotalProductsCount());

        $facetCollection = new FacetCollection();
        $nextMenu = $facetCollection->setFacets($facets);
        $result->setFacetCollection($nextMenu);

        $facetFilters = $this->urlSerializer->getActiveFacetFiltersFromFacets($facets);
        $result->setEncodedFacets($this->urlSerializer->serialize($facetFilters));

        return $result;
    }

    /**
     * Generate unique cache hash to store blocks in cache
     *
     * @param ProductSearchQuery $query
     * @param array $facetedSearchFilters
     *
     * @return string
     */
    private function generateCacheKeyForQuery(ProductSearchQuery $query, array $facetedSearchFilters)
    {
        $context = $this->module->getContext();

        $filterKey = $query->getQueryType();
        if ($query->getQueryType() == 'category') {
            $filterKey .= $query->getIdCategory();
        } elseif ($query->getQueryType() == 'manufacturer') {
            $filterKey .= $query->getIdManufacturer();
        } elseif ($query->getQueryType() == 'supplier') {
            $filterKey .= $query->getIdSupplier();
        }

        $filterHash = md5(
            sprintf(
                '%d-%d-%d-%s-%d-%s',
                (int) $context->shop->id,
                (int) $context->currency->id,
                (int) $context->language->id,
                $filterKey,
                (int) $context->country->id,
                serialize($facetedSearchFilters)
            )
        );

        return $filterHash;
    }

    /**
     * Renders an product search result.
     *
     * @param ProductSearchContext $context
     * @param ProductSearchResult $result
     *
     * @return string the HTML of the facets
     */
    public function renderFacets(ProductSearchContext $context, ProductSearchResult $result)
    {
        list($activeFilters, $displayedFacets, $facetsVar) = $this->prepareActiveFiltersForRender($context, $result);

        // No need to render without facets
        if (empty($facetsVar)) {
            return '';
        }

        $this->module->getContext()->smarty->assign(
            [
                'show_quantities' => Configuration::get('PS_LAYERED_SHOW_QTIES'),
                'facets' => $facetsVar,
                'js_enabled' => $this->module->isAjax(),
                'displayedFacets' => $displayedFacets,
                'activeFilters' => $activeFilters,
                'sort_order' => $result->getCurrentSortOrder()->toString(),
                'clear_all_link' => $this->updateQueryString(
                    [
                        'q' => null,
                        'page' => null,
                    ]
                ),
            ]
        );

        return $this->module->fetch(
            'module:ps_facetedsearch/views/templates/front/catalog/facets.tpl'
        );
    }

    /**
     * Renders an product search result of active filters.
     *
     * @param ProductSearchContext $context
     * @param ProductSearchResult $result
     *
     * @return string the HTML of the facets
     */
    public function renderActiveFilters(ProductSearchContext $context, ProductSearchResult $result)
    {
        list($activeFilters) = $this->prepareActiveFiltersForRender($context, $result);

        $this->module->getContext()->smarty->assign(
            [
                'activeFilters' => $activeFilters,
                'clear_all_link' => $this->updateQueryString(
                    [
                        'q' => null,
                        'page' => null,
                    ]
                ),
            ]
        );

        return $this->module->fetch(
            'module:ps_facetedsearch/views/templates/front/catalog/active-filters.tpl'
        );
    }

    /**
     * Prepare active filters for renderer.
     *
     * @param ProductSearchContext $context
     * @param ProductSearchResult $result
     *
     * @return array|null
     */
    private function prepareActiveFiltersForRender(ProductSearchContext $context, ProductSearchResult $result)
    {
        $facetCollection = $result->getFacetCollection();

        if (empty($facetCollection)) {
            return null;
        }

        $facetsVar = array_map([$this, 'prepareFacetForTemplate'], $facetCollection->getFacets());

        $activeFilters = [];
        foreach ($facetsVar as $idx => $facet) {
            // Remove undisplayed facets
            $displayedFacets = array_filter($facetsVar, function ($facet) {
                return !empty($facet['displayed']);
            });

            // Check if a filter is active
            foreach ($facet['filters'] as $filter) {
                if ($filter['active']) {
                    $activeFilters[] = $filter;
                }
            }
        }

        return [
            'activeFilters' => $activeFilters,
            'displayedFacets' => $displayedFacets,
            'facetsVar' => $facetsVar,
        ];
    }


    /**
     * Converts a Facet to an array with all necessary
     * information for templating.
     *
     * @param Facet $facet
     *
     * @return array ready for templating
     */
    protected function prepareFacetForTemplate(Facet $facet)
    {
        $facetsArray = $facet->toArray();
        $facetLabel = $facet->getLabel();
        $widgetType = $facet->getWidgetType();
    
        foreach ($facetsArray['filters'] as &$filter) {
            $filter['facetLabel'] = $facetLabel;
    
            if ($filter['nextEncodedFacets'] || $widgetType === 'slider') {
                $filter['nextEncodedFacetsURL'] = $this->updateQueryString([
                    'q' => $filter['nextEncodedFacets'],
                    'page' => null,
                ]);
            } else {
                $filter['nextEncodedFacetsURL'] = $this->updateQueryString(['q' => null]);
            }
        }
        unset($filter);
    
        return $facetsArray;
    }

    /**
     * Add a label associated with the facets
     *
     * @param array $facets
     */
    private function labelRangeFilters(array $facets)
    {
        $context = $this->module->getContext();
        $currentLocale = $context->getCurrentLocale();
        $currencyIsoCode = $context->currency->iso_code;

        foreach ($facets as $facet) {
            if (!in_array($facet->getType(), Filters\Converter::RANGE_FILTERS)) {
                continue;
            }

            foreach ($facet->getFilters() as $filter) {
                $filterValue = $filter->getValue();
                $min = empty($filterValue[0]) ? $facet->getProperty('min') : $filterValue[0];
                $max = empty($filterValue[1]) ? $facet->getProperty('max') : $filterValue[1];

                switch ($facet->getType()) {
                    case 'weight':
                        $unit = Configuration::get('PS_WEIGHT_UNIT');
                        $filter->setLabel(
                            sprintf(
                                '%1$s %2$s - %3$s %4$s',
                                $currentLocale->formatNumber($min),
                                $unit,
                                $currentLocale->formatNumber($max),
                                $unit
                            )
                        );
                        break;

                    case 'price':
                        $filter->setLabel(
                            sprintf(
                                '%1$s - %2$s',
                                $currentLocale->formatPrice($min, $currencyIsoCode),
                                $currentLocale->formatPrice($max, $currencyIsoCode)
                            )
                        );
                        break;
                }
            }
        }
    }


    /**
     * This method generates a URL stub for each filter inside the given facets
     * and assigns this stub to the filters.
     * The URL stub is called 'nextEncodedFacets' because it is used
     * to generate the URL of the search once a filter is activated.
     */
    private function addEncodedFacetsToFilters(array $facets)
    {
        // first get the currently active facetFilter in an array
        $originalFacetFilters = $this->urlSerializer->getActiveFacetFiltersFromFacets($facets);

        foreach ($facets as $facet) {
            $activeFacetFilters = $originalFacetFilters;
            // If only one filter can be selected, we keep track of
            // the current active filter to disable it before generating the url stub
            // and not select two filters in a facet that can have only one active filter.
            if (!$facet->isMultipleSelectionAllowed() && !$facet->getProperty('range')) {
                foreach ($facet->getFilters() as $filter) {
                    if ($filter->isActive()) {
                        // we have a currently active filter is the facet, remove it from the facetFilter array
                        $activeFacetFilters = $this->urlSerializer->removeFilterFromFacetFilters(
                            $originalFacetFilters,
                            $filter,
                            $facet
                        );
                        break;
                    }
                }
            }

            foreach ($facet->getFilters() as $filter) {
                // toggle the current filter
                if ($filter->isActive() || $facet->getProperty('range')) {
                    $facetFilters = $this->urlSerializer->removeFilterFromFacetFilters(
                        $activeFacetFilters,
                        $filter,
                        $facet
                    );
                } else {
                    $facetFilters = $this->urlSerializer->addFilterToFacetFilters(
                        $activeFacetFilters,
                        $filter,
                        $facet
                    );
                }

                // We've toggled the filter, so the call to serialize
                // returns the "URL" for the search when user has toggled
                // the filter.
                $filter->setNextEncodedFacets(
                    $this->urlSerializer->serialize($facetFilters)
                );
            }
        }
    }

    /**
     * Remove the facet when there's only 1 result.
     * Keep facet status when it's a slider.
     * Keep facet status if it's a availability facet.
     *
     * @param array $facets
     * @param int $totalProducts
     */
    private function hideUselessFacets(array $facets, $totalProducts)
    {
        foreach ($facets as $facet) {
            if ($facet->getWidgetType() === 'slider') {
                // If the facet is a slider type, we hide it ONLY if the MIN and MAX value match
                $facet->setDisplayed($facet->getProperty('min') != $facet->getProperty('max'));
                continue;
            }

            $totalFacetProducts = 0;
            $usefulFiltersCount = 0;

            foreach ($facet->getFilters() as $filter) {
                if ($filter->getMagnitude() > 0 && $filter->isDisplayed()) {
                    $totalFacetProducts += $filter->getMagnitude();
                    ++$usefulFiltersCount;
                }
            }

            // We display the facet in several cases
            $facet->setDisplayed(
                $usefulFiltersCount > 1 ||
                (
                    count($facet->getFilters()) === 1 &&
                    (
                        $totalFacetProducts < $totalProducts ||
                        $facet->getType() == 'availability'
                    )
                )
            );
            // Other cases - hidden by default
        }
    }


    /**
     * Generate a URL corresponding to the current page but
     * with the query string altered.
     *
     * Params from $extraParams that have a null value are stripped,
     * and other params are added. Params not in $extraParams are unchanged.
     */
    private function updateQueryString(array $extraParams = [])
    {
        $uriParts = explode('?', $_SERVER['REQUEST_URI']);
        $uriWithoutParams = $uriParts[0];
        $url = Tools::getCurrentUrlProtocolPrefix() . $_SERVER['HTTP_HOST'] . $uriWithoutParams;

        parse_str($uriParts[1] ?? '', $params);

        $queryString = http_build_query(array_merge($params, $extraParams), '', '&');

        return $url . ($queryString ? "?$queryString" : '');
    }


    /**
     * Checks if we should return information about combinations to the core
     *
     * @param array $facetedSearchFilters filters passed in the query and parsed by our module
     *
     * @return bool if should add attributes to the select
     */
    private function shouldPassCombinationIds(array $facetedSearchFilters)
    {
        return !empty($facetedSearchFilters['id_attribute_group']);
    }
}
