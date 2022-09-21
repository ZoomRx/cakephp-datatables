<?php
namespace DataTables\Controller\Component;

use Cake\Controller\Component;
use Cake\ORM\TableRegistry;
use Cake\Log\Log;
use Psr\Http\Message\ServerRequestInterface;


/**
 * DataTables component
 */
class DataTablesComponent extends Component
{
    protected $_defaultConfig = [
        'start' => 0,
        'length' => 10,
        'order' => [],
        'conditionsOr' => [], // table-wide search conditions
        'conditionsAnd' => [], // column search conditions
        'matching' => [], // column search conditions for foreign tables
    ];

    protected $_viewVars = [
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'draw' => 0,
    ];

    protected $_isAjaxRequest = false;

    protected $_applyLimit = true;

    protected $_tableName = null;

    protected $_plugin = null;

    //TODO: Rewrite it
    private $queryFields = []; //To handle computed fields in conditions

    /**
     * Process query data of ajax request
     *
     */
    private function _processRequest($param = null)
    {
        // -- check whether it is an ajax call from data tables server-side plugin or a normal request
        $this->_isAjaxRequest = $this->getController()->getRequest()->is('ajax');

        // -- check whether it is an csv request
        $csvOutputQuery = $this->getController()->getRequest()->getQuery('_csv_output');
        if (!empty($csvOutputQuery) && $csvOutputQuery) {
            $this->_applyLimit = false;
        }

        if ($this->_applyLimit === true) {
            // -- add limit
            $lengthQuery = $this->getController()->getRequest()->getQuery('length');
            if (isset($lengthQuery) && !empty($lengthQuery)) {
                $this->setConfig('length', $this->getController()->getRequest()->getQuery('length'));
            }

            // -- add offset
            $start = $this->getController()->getRequest()->getQuery('start');
            if (isset($start) && !empty($start)) {
                $this->setConfig('start', (int)$this->getController()->getRequest()->getQuery('start'));
            }
        }

        // -- add order
        $orderQuery = $this->getController()->getRequest()->getQuery('order');
        if (isset($orderQuery) && !empty($orderQuery)) {
            $order = $this->getConfig('order');
            foreach ($this->getController()->getRequest()->getQuery('order') as $item) {
                $order[$this->getController()->getRequest()->getQuery('columns')[$item['column']]['name']] = $item['dir'];
            }
            $this->setConfig('order', $order);
        }

        // -- add draw (an additional field of data tables plugin)
        $drawQuery = $this->getController()->getRequest()->getQuery('draw');
        if (isset($drawQuery) && !empty($drawQuery)) {
            $this->_viewVars['draw'] = (int)$this->getController()->getRequest()->getQuery('draw');
        }

        // -- don't support any search if columns data missing
        $columnsQuery = $this->getController()->getRequest()->getQuery('columns');
        if (!isset($columnsQuery) ||
            empty($columnsQuery)) {
            return;
        }

        // -- check table search field
        $globalSearch = (isset($this->getController()->getRequest()->getQuery('search')['value']) ?
            $this->getController()->getRequest()->getQuery('search')['value'] : false);

        // -- add conditions for both table-wide and column search fields
        foreach ($this->getController()->getRequest()->getQuery('columns') as $column) {
            if (!empty($column['name'])) {
                if ($globalSearch && $column['searchable'] == 'true') {
                    $this->_addCondition($column['name'], $globalSearch, 'or', true);
                }
                $localSearch = $column['search']['value'];
                /* In some circumstances (no "table-search" row present), DataTables
                fills in all column searches with the global search. Compromise:
                Ignore local field if it matches global search. */
                if ((isset($localSearch) && strlen($localSearch) > 0) && ($localSearch !== $globalSearch)) {
                    if (isset($column['search']['regex']) && $column['search']['regex'] != 'false') {
                        /*  Hack regex field is used for wild card search or full search options.
                            If regex field is true then we have to use full search (column must equal to value)
                            otherwise we can perform wild card search (%LIKE%) */
                        $this->_addCondition($column['name'], $column['search']['value'], 'and', false);
                    } else {
                        $this->_addCondition($column['name'], $column['search']['value'], 'and', true);
                    }
                }
            }
        }
    }

    /**
     * Get data paths for CSV export
     *
     * @return array
     */
    public function getPaths()
    {
        $parser = [];
        foreach ($this->getController()->getRequest()->getQuery('columns') as $column) {
            if (!empty($column['name']) && !empty($column['data'])) {
                $parser[] = $column['data'];
            }
        }
        return $parser;
    }

    /**
     * Get data header for CSV export
     *
     * @return array
     */
    public function getHeader()
    {
        $header = [];
        foreach ($this->getController()->getRequest()->getQuery('columns') as $column) {
            if (!empty($column['name'])) {
                $column = str_replace('_matchingData.', '', $column['name']);
                $header[] = $column;
            }
        }
        return $header;
    }

    /**
     * Check if specific array key exists in multidimensional array
     * @param array $arr - An array with keys to check.
     * @param string $lookup - Value to check.
     * @return Returns path of the key on success or null on failure.
     */
    public function findPath($arr, $lookup)
    {
        if (array_key_exists($lookup, $arr)) {
            return [$lookup];
        } else {
            foreach ($arr as $key => $subarr) {
                if (is_array($subarr)) {
                    $ret = $this->findPath($subarr, $lookup);

                    if ($ret) {
                        $ret[] = $key;
                        return $ret;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Check if specific array key exists in multidimensional array
     * @param array $arr - An array with keys to check.
     * @param string $lookup - Value to check.
     * @return Returns dot notation path of the key on success or FALSE on failure.
     */
    public function getKeyPath($arr, $lookup)
    {
        $path = $this->findPath($arr, $lookup);
        if ($path === null) {
            return false;
        } else {
            return implode('.', array_reverse($path));
        }
    }

    /**
     * Find data
     *
     * @param string $tableName Name of the table
     * @param string $finder all,list.,
     * @param array $options query options
     * @return array|\Cake\ORM\Query
     */
    public function find($tableName, $finder = 'all', array $options = [])
    {
        // -- get table object
        $table = TableRegistry::getTableLocator()->get($tableName);
        $data = $table->find($finder, $options);
        return $this->process($data);
    }

    /**
     * Process queryObject
     *
     * @param \Cake\ORM\Query  $queryObject Query object to be processed
     * @param array $filterParams used for post request instead of ajax
     * @param bool $applyLimit boolean to set Limit
     * @return array|\Cake\ORM\Query
     */
    public function process($queryObject, $filterParams = null, $applyLimit = null)
    {
        $this->_setQueryFields($queryObject);
        //set table alias name
        $this->_tableName = $queryObject->getRepository()->getAlias();

        if ($filterParams !== null) {
            $this->request = $this->request->withQueryParams([
                'columns' => $filterParams['columns'],
                'search' => $filterParams['search']
            ]);
            $this->_applyLimit = false;
        }

        if ($applyLimit !== null) {
            $this->_applyLimit = $applyLimit;
        }

        // -- get query options
        $this->_processRequest();

        // -- record count
        $this->_viewVars['recordsTotal'] = $queryObject->count();


        // -- filter result
        if (count($this->getConfig('conditionsAnd')) > 0) {
            $queryObject->where($this->getConfig('conditionsAnd'));
        }

        foreach ($this->getConfig('matching') as $association => $where) {
            /*$associationPath = $this->getKeyPath($queryObject->contain(), $association);
            if ($associationPath !== false) {
                $queryObject->contain([
                    $associationPath => function ($q) use ($where) {
                        return $q->where($where);
                    },
                ]);
            } elseif (isset($queryObject->join()[$association])) {
                $queryObject->andWhere($where);
            } else {
                $queryObject->matching($association, function ($q) use ($where) {
                    return $q->where($where);
                });
            }*/
            $queryObject->where($where);
        }

        if (count($this->getConfig('conditionsOr')) > 0) {
            $queryObject->andWhere(['or' => $this->getConfig('conditionsOr')]);
        }

        //->bufferResults(true) Hack => when we add inner join cond the count will be returned from cache not actual count
        $this->_viewVars['recordsFiltered'] = $queryObject->enableBufferedResults(true)->count();

        if ($this->_applyLimit === true) {
            // -- add limit
            $queryObject->limit($this->getConfig('length'));
            $queryObject->offset($this->getConfig('start'));
        }

        // -- sort
        $queryObject->order($this->getConfig('order'));

        // -- set all view vars to view and serialize array
        $this->_setViewVars();

        return $queryObject;
    }

    private function _getController()
    {
        return $this->_registry->getController();
    }

    private function _setViewVars()
    {
        $controller = $this->getController();
        $_serialize = $controller->viewBuilder()->getVar('serialize') ?? [];
        $_serialize = array_merge($_serialize, array_keys($this->_viewVars));

        $controller->set($this->_viewVars);
        $controller->set('serialize', $_serialize);
        $csvOutputQuery = $this->getController()->getRequest()->getQuery('_csv_output');
        if (!empty($csvOutputQuery) && $csvOutputQuery) {
            // In case of CSV download, set csv headers and the fields to be inserted into csv file
            $controller->set('header', $this->getHeader());
            $controller->set('extract', $this->getPaths());
        }
    }

    private function _addCondition($column, $value, $type = 'and', $isWildcardSearch = true)
    {
        $columnObj = $this->_getColumnName($column);
        $condition = [];
        if ($isWildcardSearch == true) {
            $condition = [$this->queryObject->newExpr()->like($columnObj, "%$value%", 'string')];
        } else {
            if (is_null($value) || $value == 'null' || $value == 'NULL') {
                $condition = [$column . ' IS NULL'];
            } else {
                $condition[$column] = $value;
            }
        }

        if ($type === 'or') {
            $this->setConfig('conditionsOr', $condition); // merges
            return;
        } else {
            $pieces = explode('.', $column);
            if (count($pieces) > 1) {
                list($association, $field) = $pieces;
                if ($this->_tableName == $association) {
                    $this->setConfig('conditionsAnd', $condition); // merges
                } else {
                    $this->setConfig('matching', [$association => $condition]); // merges
                }
            } else {
                $this->setConfig('conditionsAnd', $condition); // merges
            }
        }
    }

    /**
     * Replace all computed columns
     * @param string $name
     * @return string
     */
    private function _getColumnName($name)
    {
        if (isset($this->queryFields[$name])) {
            return $this->queryFields[$name];
        } else {
            return $name;
        }
    }

    /**
     * Set the query object and select fields, so that it can be used in conditions
     * @param Query $queryObject
     */
    private function _setQueryFields($queryObject)
    {
        $this->queryObject = $queryObject;
        $this->queryFields = $queryObject->clause('select');
    }
}
