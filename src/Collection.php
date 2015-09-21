<?php

namespace tienrocker;

/**
 * Class Collection
 *
 * You need
 * + add debug = 1 in application config to enable profiler
 * + add mongo database config under "mongo" name (or customize by your self)
 *
 * ################
 * Please add follow line to app/config/services.php
 *
 * # After init $di variable
 *
 * Phalcon profiler
 * $di->setShared('profiler', function () use ($di, $config) { if (isset($config->debug) && (bool)$config->debug === true) {return new \Fabfuel\Prophiler\Profiler();}return null;});
 *
 * # Bottom of page
 *
 * Mongo Database connection
 * $di->setShared('mongo', function () use ($config) { $conn = sprintf('mongodb://%s:%s@%s:%s', $config->mongodb->username, $config->mongodb->password, $config->mongodb->host, $config->mongodb->port); $mongo = new \MongoClient($conn); return $mongo->selectDb($config->mongodb->dbname);});
 *
 * Collection manager for Mongo Database
 * $di->setShared('collectionManager', function () { return new Phalcon\Mvc\Collection\Manager(); });
 *
 * Set profiler event handler
 * $profiler = &$di->get('profiler');if (!empty($profiler)) { $profiler->addAggregator(new \Fabfuel\Prophiler\Aggregator\Database\QueryAggregator()); $profiler->addAggregator(new \Fabfuel\Prophiler\Aggregator\Cache\CacheAggregator()); $pluginManager = new \Fabfuel\Prophiler\Plugin\Manager\Phalcon($profiler); $pluginManager->register();}
 *
 * ################
 * Please add follow line to public/index.php
 *
 * # Before declare $config variable
 *
 * Composer autoloader
 * require APP_PATH . "/vendor/autoload.php";
 *
 * # After render page
 *
 * Add Phalcon profiler toolbar
 * if ($profiler !== null) { session_commit(); $toolbar = new \Fabfuel\Prophiler\Toolbar($di->get('profiler')); $toolbar->addDataCollector(new \Fabfuel\Prophiler\DataCollector\Request()); $content = str_replace('</body>', $toolbar->render() . '</body>', $content);}
 */
class Collection extends \Phalcon\Mvc\Collection
{
    /**
     * @var \Fabfuel\Prophiler\Benchmark\BenchmarkInterface
     */
    protected $currentBenchmark;

    /**
     * Logs a SQL statement
     *
     * @param string $sql The SQL to be executed
     * @param array|null $params The SQL parameters
     * @param array|null $types The SQL parameter types
     * @return void
     */
    protected function startQuery($sql, array $params = null, array $types = null)
    {
        $metadata = [
            'query' => $sql,
            'params' => $params,
            'types' => $types,
        ];

        $this->setCurrentBenchmark(\Phalcon\DI::getDefault()->get('profiler')->start('Doctrine::query', $metadata, 'Database'));
    }

    /**
     * Marks the last started query as stopped
     *
     * @return void
     */
    protected function stopQuery()
    {
        \Phalcon\DI::getDefault()->get('profiler')->stop($this->getCurrentBenchmark());
    }

    /**
     * @return \Fabfuel\Prophiler\Benchmark\BenchmarkInterface
     */
    protected function getCurrentBenchmark()
    {
        return $this->currentBenchmark;
    }

    /**
     * @param \Fabfuel\Prophiler\Benchmark\BenchmarkInterface $currentBenchmark
     */
    protected function setCurrentBenchmark($currentBenchmark)
    {
        $this->currentBenchmark = $currentBenchmark;
    }

    protected function beforeCreate()
    {
        if (\Phalcon\DI::getDefault()->get('profiler')) {
            $data = (array)$this;
            foreach (array_keys($data) as $key) if (strpos($key, '*') !== false) unset($data[$key]);

            foreach ($data as &$value) {
                if (is_string($value)) $value = '\'' . $value . '\'';
                elseif (is_null($value)) $value = 'NULL';
            }

            $sql = 'INSERT INTO ' . $this->getSource() . ' (`' . implode('`, `', array_keys($data)) . '`) VALUES(' . implode(', ', array_values($data)) . ');';;
            $this->startQuery($sql, $data, array('INSERT'));
        }
    }

    protected function afterCreate()
    {
        if (\Phalcon\DI::getDefault()->get('profiler')) {
            $this->stopQuery();
        }
    }

    protected function beforeUpdate()
    {
        if (\Phalcon\DI::getDefault()->get('profiler')) {

            $data = (array)$this;
            $keys = array();

            foreach ($data as $key => $value)
                if (strpos($key, '*') === false) {
                    if (is_string($value)) $keys[] = $key . '=' . '\'' . $value . '\'';
                    elseif (is_null($value)) $keys[] = $key . '=' . 'NULL';
                    else $keys[] = $key . '=' . $value;
                }

            $sql = 'UPDATE ' . $this->getSource() . ' SET ' . implode(' AND ', array_values($keys)) . ';';;

            $this->startQuery($sql, $data, array('UPDATE'));
        }
    }

    protected function afterUpdate()
    {
        if (\Phalcon\DI::getDefault()->get('profiler')) {
            $this->stopQuery();
        }
    }

    protected function beforeDelete()
    {
        if (\Phalcon\DI::getDefault()->get('profiler')) {

            $data = (array)$this;
            foreach (array_keys($data) as $key) if (strpos($key, '*') !== false) unset($data[$key]);

            $sql = 'DELETE FROM ' . $this->getSource() . ' WHERE ' . implode(', ', array_map(function ($v, $k) {
                    return sprintf("%s='%s'", $k, $v);
                }, $data, array_keys($data))) . ' ';

            $this->startQuery($sql, $data, array('UPDATE'));
        }
    }

    protected function afterDelete()
    {
        if (\Phalcon\DI::getDefault()->get('profiler')) {
            $this->stopQuery();
        }
    }

    /**
     * Perform a count over a resultset
     *
     * @param array $params
     * @param \Phalcon\Mvc\Collection $collection
     * @param \MongoDb $connection
     * @return int
     */
    protected static function _getGroupResultset($params, Collection $collection, $connection)
    {

        if (\Phalcon\DI::getDefault()->get('profiler')) {
            $sql = 'SELECT COUNT(*) FROM ' . $collection->getSource() . ' ';

            /**
             * Get where condition
             */
            if (is_array(@$params[0])) {
                $sql .= ' WHERE ' . implode(', ', array_map(function ($v, $k) {
                        return sprintf("%s='%s'", $k, $v);
                    }, $params[0], array_keys($params[0]))) . ' ';
            }

            /**
             * Check if a "sort" clause was defined
             */
            if (isset($params['sort'])) {
                $sql .= ' ORDER BY ' . implode(', ', $params['sort']);
            }

            /**
             * Check if a "limit" clause was defined
             */
            if (isset($params['limit'])) {
                $sql .= ' LIMIT ' . $params['limit'];
            }

            /**
             * Check if a "skip" clause was defined
             */
            if (isset($params['skip'])) {
                $sql .= ' SKIP ' . $params['skip'];
            }

            $sql = str_replace('  ', ' ', $sql);

            $metadata = [
                'query' => $sql,
                'params' => $params,
                'types' => 'COUNT',
            ];

            \Phalcon\DI::getDefault()->get('profiler')->start('MongoDB::query', $metadata, 'Database');
        }

        $rs = parent::_getGroupResultset($params, $collection, $connection);

        if (\Phalcon\DI::getDefault()->get('profiler')) \Phalcon\DI::getDefault()->get('profiler')->stop();

        return $rs;
    }

    /**
     * Returns a collection resultset
     *
     * @param array $params
     * @param \Phalcon\Mvc\Collection $collection
     * @param \MongoDb $connection
     * @param boolean $unique
     * @return array
     */
    protected static function _getResultset($params, \Phalcon\Mvc\CollectionInterface $collection, $connection, $unique)
    {

        if (\Phalcon\DI::getDefault()->get('profiler')) {
            $sql = 'SELECT ';

            /**
             * Perform the find
             */
            if (isset($params['fields'])) {
                $sql .= implode(', ', $params['fields']);
            } else {
                $sql .= ' * ';
            }

            $sql .= ' FROM ' . $collection->getSource() . ' ';

            /**
             * Get where condition
             */
            if (is_array(@$params[0])) {
                $sql .= ' WHERE ' . implode(', ', array_map(function ($v, $k) {
                        return sprintf("%s='%s'", $k, $v);
                    }, $params[0], array_keys($params[0]))) . ' ';
            }


            /**
             * Check if a "sort" clause was defined
             */
            if (isset($params['sort'])) {
                $sql .= ' ORDER BY ' . implode(', ', $params['sort']);
            }

            /**
             * Check if a "limit" clause was defined
             */
            if (isset($params['limit'])) {
                $sql .= ' LIMIT ' . $params['limit'];
            }

            /**
             * Check if a "skip" clause was defined
             */
            if (isset($params['skip'])) {
                $sql .= ' SKIP ' . $params['skip'];
            }

            $sql = str_replace('  ', ' ', $sql);

            $metadata = [
                'query' => $sql,
                'params' => $params,
                'types' => 'SELECT',
            ];

            \Phalcon\DI::getDefault()->get('profiler')->start('MongoDB::query', $metadata, 'Database');
        }

        $rs = parent::_getResultset($params, $collection, $connection, $unique);

        if (\Phalcon\DI::getDefault()->get('profiler')) \Phalcon\DI::getDefault()->get('profiler')->stop();

        return $rs;
    }
}